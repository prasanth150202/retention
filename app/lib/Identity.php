<?php
/**
 * Identity resolution — deciding which orders belong to the same person.
 *
 * Everything retention measures depends on this being right. "Repeat purchase
 * rate", "time to second order", "cohort retention" are all statements about a
 * person placing more than one order, and they are only as good as the
 * decision that two orders came from the same person.
 *
 * WHY NOT JUST GROUP BY customer_id
 * Because guest checkout produces orders with no customer record at all, and
 * the same person routinely appears as several Shopify customers — different
 * email, same phone; same email, new account. In Indian D2C, where guest
 * checkout is the norm and the phone number is the reliable identifier, that
 * is the common case rather than an edge case. Grouping by customer_id alone
 * reports a repeat rate that is simply too low, and does so silently.
 *
 * SO: a person_id layer sits above Shopify's customers, built by union-find
 * over three keys — Shopify customer id, normalised phone, normalised email.
 * Any one matching merges.
 *
 * KNOWN LIMITS, worth stating rather than discovering in a client review:
 *   - A shared household or family phone occasionally merges two real people.
 *   - Orders with neither phone nor email stay singletons and depress the
 *     measured repeat rate.
 *   - Merges are logged and therefore reversible, but reversal is manual.
 */

declare(strict_types=1);

final class Identity
{
    /**
     * Resolve one order to a person, creating or merging as needed.
     *
     * @param array<string,mixed> $order row from `orders`
     * @return array{person_id:int,merged:int,created:bool}
     */
    public static function resolveOrder(int $tenantId, array $order): array
    {
        $keys = self::keysFor($tenantId, $order);

        if ($keys === []) {
            // No identifying key at all. A person is still created so the order
            // has a sequence of 1 — a first purchase is a real fact even when
            // the buyer is unidentifiable. It simply can never merge with
            // anything, which is why these depress the repeat rate.
            $personId = self::createPerson($tenantId, (string) $order['created_at']);
            self::attachOrder($tenantId, (int) $order['order_id'], $personId);

            // Still needs a sequence. It is order number 1 for a person of
            // one, and skipping this would leave order_sequence NULL, which
            // quietly drops the order from every first-purchase count.
            self::queueResequence($tenantId, $personId);

            return ['person_id' => $personId, 'merged' => 0, 'created' => true];
        }

        $found = self::personsForKeys($tenantId, $keys);

        if ($found === []) {
            $personId = self::createPerson($tenantId, (string) $order['created_at']);
            $created  = true;
            $merged   = 0;
        } else {
            // Lowest id survives: deterministic, and it keeps the oldest
            // person, which is the one whose first order defines the cohort.
            sort($found);
            $personId = array_shift($found);
            $created  = false;
            $merged   = 0;

            foreach ($found as $absorbed) {
                self::merge($tenantId, $personId, (int) $absorbed, self::mergeReason($tenantId, $keys, (int) $absorbed));
                $merged++;
            }
        }

        self::attachKeys($tenantId, $keys, $personId);
        self::attachOrder($tenantId, (int) $order['order_id'], $personId);
        self::queueResequence($tenantId, $personId);

        return ['person_id' => $personId, 'merged' => $merged, 'created' => $created];
    }

    /**
     * Recompute order_sequence for a person.
     *
     * order_sequence = 1 is their first order, 2 the second, and so on. It is
     * what every retention figure counts.
     *
     * Cancelled orders are excluded — a cancelled order is not a purchase.
     * Refunded ones are included by default: the purchase happened, and the
     * refund is a separate fact. That is a per-store setting because
     * reasonable people differ, and a store with heavy COD refusal may want
     * the opposite.
     */
    public static function resequence(int $tenantId, int $personId): int
    {
        $pdo = Db::core();

        $refundedCounts = (bool) (Tenant::find($tenantId)['refunded_counts_as_order'] ?? 1);

        $exclude = $refundedCounts
            ? ''
            : " AND (financial_status IS NULL OR financial_status NOT IN ('refunded','REFUNDED'))";

        $stmt = $pdo->prepare(
            "SELECT order_id FROM orders
              WHERE tenant_id = :tenant_id AND person_id = :person_id
                AND cancelled_at IS NULL {$exclude}
              ORDER BY created_at, order_id"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':person_id' => $personId]);

        $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $upd = $pdo->prepare(
            'UPDATE orders SET order_sequence = ? WHERE tenant_id = ? AND order_id = ?'
        );

        $n = 0;
        foreach ($orderIds as $i => $orderId) {
            $upd->execute([$i + 1, $tenantId, (int) $orderId]);
            $n++;
        }

        // Cancelled orders keep no sequence: they are not purchases and should
        // not occupy a position that makes the next order look like a repeat.
        $pdo->prepare(
            'UPDATE orders SET order_sequence = NULL
              WHERE tenant_id = ? AND person_id = ? AND cancelled_at IS NOT NULL'
        )->execute([$tenantId, $personId]);

        $pdo->prepare('DELETE FROM resequence_queue WHERE tenant_id = ? AND person_id = ?')
            ->execute([$tenantId, $personId]);

        return $n;
    }

    /** Orders with no person yet, oldest first so sequences build in order. */
    public static function unresolved(int $tenantId, int $limit = 500): array
    {
        $stmt = Db::core()->prepare(
            'SELECT order_id, created_at, shopify_customer_id, email_hash, phone_hash
               FROM orders
              WHERE tenant_id = :tenant_id AND person_id IS NULL
              ORDER BY created_at, order_id
              LIMIT ' . (int) $limit
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        return $stmt->fetchAll();
    }

    /** @return array<int,int> person ids awaiting resequencing */
    public static function pendingResequence(int $tenantId, int $limit = 500): array
    {
        $stmt = Db::core()->prepare(
            'SELECT person_id FROM resequence_queue
              WHERE tenant_id = :tenant_id ORDER BY queued_at LIMIT ' . (int) $limit
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // -----------------------------------------------------------------

    /**
     * The identity keys an order carries.
     *
     * @return array<int,array{type:string,hash:string}>
     */
    private static function keysFor(int $tenantId, array $order): array
    {
        $keys = [];

        if (!empty($order['shopify_customer_id'])) {
            $keys[] = [
                'type' => 'shopify_customer',
                'hash' => Hash::pii($tenantId, (string) $order['shopify_customer_id']),
            ];
        }
        if (!empty($order['phone_hash'])) {
            $keys[] = ['type' => 'phone', 'hash' => (string) $order['phone_hash']];
        }
        if (!empty($order['email_hash'])) {
            $keys[] = ['type' => 'email', 'hash' => (string) $order['email_hash']];
        }

        return $keys;
    }

    /**
     * Every person already associated with any of these keys.
     *
     * More than one means two records that were thought separate are the same
     * person — which is exactly what this exists to find.
     *
     * @param array<int,array{type:string,hash:string}> $keys
     * @return array<int,int>
     */
    private static function personsForKeys(int $tenantId, array $keys): array
    {
        $pdo   = Db::core();
        $found = [];

        $stmt = $pdo->prepare(
            'SELECT person_id FROM identity_keys
              WHERE tenant_id = ? AND key_type = ? AND key_hash = ?'
        );

        foreach ($keys as $k) {
            $stmt->execute([$tenantId, $k['type'], $k['hash']]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $found[(int) $pid] = true;
            }
        }

        // Follow merge pointers: a key may still point at a person already
        // absorbed by an earlier merge.
        $resolved = [];
        foreach (array_keys($found) as $pid) {
            $resolved[self::root($tenantId, $pid)] = true;
        }

        return array_keys($resolved);
    }

    /** Follow merged_into to the surviving person. */
    private static function root(int $tenantId, int $personId): int
    {
        $pdo  = Db::core();
        $seen = [];

        while (true) {
            if (isset($seen[$personId])) {
                return $personId;   // cycle guard; should not happen
            }
            $seen[$personId] = true;

            $stmt = $pdo->prepare('SELECT merged_into FROM persons WHERE tenant_id = ? AND person_id = ?');
            $stmt->execute([$tenantId, $personId]);
            $next = $stmt->fetchColumn();

            if ($next === false || $next === null) {
                return $personId;
            }
            $personId = (int) $next;
        }
    }

    private static function createPerson(int $tenantId, string $firstSeen): int
    {
        $pdo = Db::core();
        $pdo->prepare(
            'INSERT INTO persons (tenant_id, first_seen, last_seen) VALUES (?, ?, ?)'
        )->execute([$tenantId, $firstSeen, $firstSeen]);

        return (int) $pdo->lastInsertId();
    }

    /** @param array<int,array{type:string,hash:string}> $keys */
    private static function attachKeys(int $tenantId, array $keys, int $personId): void
    {
        $stmt = Db::core()->prepare(
            'INSERT INTO identity_keys (tenant_id, key_type, key_hash, person_id, created_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE person_id = VALUES(person_id)'
        );

        foreach ($keys as $k) {
            $stmt->execute([$tenantId, $k['type'], $k['hash'], $personId]);
        }
    }

    private static function attachOrder(int $tenantId, int $orderId, int $personId): void
    {
        Db::core()->prepare(
            'UPDATE orders SET person_id = ? WHERE tenant_id = ? AND order_id = ?'
        )->execute([$personId, $tenantId, $orderId]);

        Db::core()->prepare(
            'UPDATE persons SET last_seen = GREATEST(last_seen, (
                 SELECT MAX(created_at) FROM orders WHERE tenant_id = ? AND person_id = ?
             )) WHERE tenant_id = ? AND person_id = ?'
        )->execute([$tenantId, $personId, $tenantId, $personId]);
    }

    /**
     * Absorb one person into another.
     *
     * Every merge is logged. They are occasionally wrong — a shared family
     * phone is the usual cause — and an unlogged merge is one nobody can
     * unpick when a merchant asks why two customers became one.
     */
    private static function merge(int $tenantId, int $survivor, int $absorbed, string $reason): void
    {
        if ($survivor === $absorbed) {
            return;
        }

        $pdo = Db::core();

        $pdo->prepare(
            'UPDATE identity_keys SET person_id = ? WHERE tenant_id = ? AND person_id = ?'
        )->execute([$survivor, $tenantId, $absorbed]);

        $pdo->prepare(
            'UPDATE orders SET person_id = ? WHERE tenant_id = ? AND person_id = ?'
        )->execute([$survivor, $tenantId, $absorbed]);

        $pdo->prepare(
            'UPDATE customers SET person_id = ? WHERE tenant_id = ? AND person_id = ?'
        )->execute([$survivor, $tenantId, $absorbed]);

        // The absorbed row is kept, pointing at its survivor. Deleting it would
        // break any stored reference and destroy the audit trail.
        $pdo->prepare(
            'UPDATE persons SET merged_into = ? WHERE tenant_id = ? AND person_id = ?'
        )->execute([$survivor, $tenantId, $absorbed]);

        $pdo->prepare(
            'INSERT INTO person_merges (tenant_id, survivor_id, absorbed_id, reason, created_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$tenantId, $survivor, $absorbed, $reason]);

        self::queueResequence($tenantId, $survivor);
    }

    /**
     * Which key caused a merge.
     *
     * Recorded because "merged on phone" and "merged on email" have very
     * different reliability, and a merchant disputing a merge deserves better
     * than "the system decided".
     *
     * @param array<int,array{type:string,hash:string}> $keys
     */
    private static function mergeReason(int $tenantId, array $keys, int $absorbed): string
    {
        $stmt = Db::core()->prepare(
            'SELECT key_type FROM identity_keys
              WHERE tenant_id = ? AND person_id = ? AND key_type = ? AND key_hash = ?'
        );

        foreach ($keys as $k) {
            $stmt->execute([$tenantId, $absorbed, $k['type'], $k['hash']]);
            if ($stmt->fetchColumn() !== false) {
                return $k['type'];
            }
        }

        return 'unknown';
    }

    private static function queueResequence(int $tenantId, int $personId): void
    {
        Db::core()->prepare(
            'INSERT INTO resequence_queue (tenant_id, person_id, queued_at)
             VALUES (?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE queued_at = queued_at'
        )->execute([$tenantId, $personId]);
    }
}
