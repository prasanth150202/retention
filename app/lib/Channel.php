<?php
/**
 * Channel classification — turning a campaign tag into "Instagram" or "Email".
 *
 * The rules live in the `channel_rules` table, ordered by priority, first
 * match wins. They are deliberately data rather than code.
 *
 * WHY THIS IS A TABLE AND NOT A CASE STATEMENT
 * The audit of the previous system found 392 visitors carrying Instagram UTMs
 * and a Facebook referrer, all booked to Instagram. That outcome is correct —
 * an explicit UTM tag is a stronger signal than a referrer header — but the
 * decision was buried in a CASE expression nobody had read in a year, so
 * nobody could say whether it was intended. Here the same decision is a row a
 * merchant can see, reorder and preview.
 *
 * ORDERING PRINCIPLE, stated once so it is not rediscovered by argument:
 *   UTM source  >  UTM medium  >  click id  >  referrer host
 * An explicit tag beats an inferred one. Referrer is last because it is the
 * easiest to lose (privacy settings, in-app browsers, https->http) and the
 * easiest to be misled by (l.facebook.com fronting an Instagram link).
 */

declare(strict_types=1);

final class Channel
{
    /**
     * Where an unmatched touch goes.
     *
     * Deliberately NOT 'Direct/Untracked'. A touch carrying utm_source=xyz
     * that no rule recognises IS tracked — the store simply has no rule for
     * it. Filing it under Direct would hide real campaign spend inside the
     * bucket merchants read as "people who typed the URL", and the merchant
     * would never learn a rule was missing.
     */
    public const UNMATCHED = 'Other';

    /** Fallback when there is genuinely nothing to go on. */
    public const NONE = 'Direct/Untracked';

    /** @var array<int,array<int,array<string,mixed>>> tenant_id => rules */
    private static array $rules = [];

    /**
     * Classify one touch.
     *
     * @param int|null $campaignId  -> dim_campaign
     * @param int|null $referrerId  -> dim_referrer
     * @param int|null $pathId      -> dim_path
     */
    public static function classify(int $tenantId, ?int $campaignId, ?int $referrerId, ?int $pathId): string
    {
        $facts = self::facts($tenantId, $campaignId, $referrerId, $pathId);

        foreach (self::rules($tenantId) as $rule) {
            if (self::matches($rule, $facts)) {
                return (string) $rule['channel'];
            }
        }

        // Nothing matched. Whether that means "no signal" or "a signal we have
        // no rule for" is the difference between an honest Direct bucket and a
        // hidden one.
        $tagged = ($facts['utm_source'] ?? '') !== ''
            || ($facts['utm_medium'] ?? '') !== ''
            || ($facts['utm_campaign'] ?? '') !== ''
            || $facts['has_gclid']
            || $facts['has_fbclid']
            || ($facts['referrer_host'] ?? '') !== '';

        return $tagged ? self::UNMATCHED : self::NONE;
    }

    /**
     * Reclassify every stored attribution row for a tenant.
     *
     * Editing a rule has to change history, not just tomorrow — otherwise the
     * Campaigns tab shows two different definitions of "Instagram" either side
     * of the day a rule changed, and neither is labelled.
     *
     * @return int rows updated
     */
    public static function reclassify(int $tenantId, ?int $limit = null): int
    {
        self::flush($tenantId);

        $pdo = Db::core();

        $sql = 'SELECT order_id, model, campaign_id, referrer_id, landing_path_id, channel
                  FROM order_attribution WHERE tenant_id = ?';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tenantId]);

        $upd = $pdo->prepare(
            'UPDATE order_attribution SET channel = ?
              WHERE tenant_id = ? AND order_id = ? AND model = ?'
        );

        $n = 0;
        foreach ($stmt->fetchAll() as $row) {
            $channel = self::classify(
                $tenantId,
                $row['campaign_id'] !== null ? (int) $row['campaign_id'] : null,
                $row['referrer_id'] !== null ? (int) $row['referrer_id'] : null,
                $row['landing_path_id'] !== null ? (int) $row['landing_path_id'] : null
            );

            if ($channel !== $row['channel']) {
                $upd->execute([$channel, $tenantId, (int) $row['order_id'], $row['model']]);
                $n++;
            }
        }

        return $n;
    }

    /**
     * How many rows a proposed rule set would move, without saving it.
     *
     * This is what makes the rules editable in practice. "Reordering these two
     * rules reclassifies 392 orders" is a sentence a merchant can act on;
     * "rules saved" is not.
     *
     * @param array<int,array<string,mixed>> $proposed
     * @return array{changed:int,total:int,moves:array<string,int>}
     */
    public static function preview(int $tenantId, array $proposed): array
    {
        $live = self::rules($tenantId);
        self::$rules[$tenantId] = self::normalise($proposed);

        try {
            $stmt = Db::core()->prepare(
                'SELECT campaign_id, referrer_id, landing_path_id, channel
                   FROM order_attribution WHERE tenant_id = ?'
            );
            $stmt->execute([$tenantId]);

            $changed = 0;
            $total   = 0;
            $moves   = [];

            foreach ($stmt->fetchAll() as $row) {
                $total++;

                $now = self::classify(
                    $tenantId,
                    $row['campaign_id'] !== null ? (int) $row['campaign_id'] : null,
                    $row['referrer_id'] !== null ? (int) $row['referrer_id'] : null,
                    $row['landing_path_id'] !== null ? (int) $row['landing_path_id'] : null
                );

                if ($now !== $row['channel']) {
                    $changed++;
                    $key = ($row['channel'] ?? 'NULL') . ' -> ' . $now;
                    $moves[$key] = ($moves[$key] ?? 0) + 1;
                }
            }

            arsort($moves);

            return ['changed' => $changed, 'total' => $total, 'moves' => $moves];
        } finally {
            self::$rules[$tenantId] = $live;   // never leave a preview in place
        }
    }

    public static function flush(?int $tenantId = null): void
    {
        if ($tenantId === null) {
            self::$rules = [];
        } else {
            unset(self::$rules[$tenantId]);
        }
    }

    // -----------------------------------------------------------------

    /**
     * The facts a rule can test, assembled from the interned dimensions.
     *
     * @return array<string,mixed>
     */
    private static function facts(int $tenantId, ?int $campaignId, ?int $referrerId, ?int $pathId): array
    {
        $facts = [
            'utm_source'    => null,
            'utm_medium'    => null,
            'utm_campaign'  => null,
            'referrer_host' => null,
            'landing_path'  => null,
            'has_gclid'     => false,
            'has_fbclid'    => false,
        ];

        $pdo = Db::core();

        if ($campaignId !== null) {
            $stmt = $pdo->prepare(
                'SELECT utm_source, utm_medium, utm_campaign, has_gclid, has_fbclid
                   FROM dim_campaign WHERE tenant_id = ? AND campaign_id = ?'
            );
            $stmt->execute([$tenantId, $campaignId]);

            if ($row = $stmt->fetch()) {
                $facts['utm_source']   = $row['utm_source'];
                $facts['utm_medium']   = $row['utm_medium'];
                $facts['utm_campaign'] = $row['utm_campaign'];
                $facts['has_gclid']    = (bool) $row['has_gclid'];
                $facts['has_fbclid']   = (bool) $row['has_fbclid'];
            }
        }

        if ($referrerId !== null) {
            $stmt = $pdo->prepare(
                'SELECT referrer_host FROM dim_referrer WHERE tenant_id = ? AND referrer_id = ?'
            );
            $stmt->execute([$tenantId, $referrerId]);
            $facts['referrer_host'] = $stmt->fetchColumn() ?: null;
        }

        if ($pathId !== null) {
            $stmt = $pdo->prepare(
                'SELECT path FROM dim_path WHERE tenant_id = ? AND path_id = ?'
            );
            $stmt->execute([$tenantId, $pathId]);
            $facts['landing_path'] = $stmt->fetchColumn() ?: null;
        }

        return $facts;
    }

    /** @param array<string,mixed> $rule @param array<string,mixed> $facts */
    private static function matches(array $rule, array $facts): bool
    {
        $field = (string) $rule['match_field'];
        $op    = (string) $rule['match_op'];
        $want  = $rule['match_value'];

        if ($field === 'has_gclid' || $field === 'has_fbclid') {
            // These are booleans, so 'exists' is the only sensible operator.
            return $op === 'exists' ? (bool) $facts[$field] : false;
        }

        $have = $facts[$field] ?? null;
        $have = $have === null ? '' : (string) $have;

        return match ($op) {
            'exists'   => $have !== '',
            // A NULL match_value asks "is this field empty?" — that is how the
            // seeded fallback rule catches untagged traffic.
            'equals'   => $want === null ? $have === '' : strcasecmp($have, (string) $want) === 0,
            'contains' => $want !== null && $want !== '' && stripos($have, (string) $want) !== false,
            'regex'    => $want !== null && self::regex($have, (string) $want),
            default    => false,
        };
    }

    /**
     * Merchant-editable regex, so a bad pattern must not take the job down.
     */
    private static function regex(string $subject, string $pattern): bool
    {
        $delimited = '/' . str_replace('/', '\\/', $pattern) . '/i';

        set_error_handler(static fn() => true);
        $result = preg_match($delimited, $subject);
        restore_error_handler();

        return $result === 1;
    }

    /** @return array<int,array<string,mixed>> */
    private static function rules(int $tenantId): array
    {
        if (isset(self::$rules[$tenantId])) {
            return self::$rules[$tenantId];
        }

        $stmt = Db::core()->prepare(
            'SELECT priority, match_field, match_op, match_value, channel
               FROM channel_rules
              WHERE tenant_id = ? AND enabled = 1
              ORDER BY priority'
        );
        $stmt->execute([$tenantId]);

        return self::$rules[$tenantId] = $stmt->fetchAll();
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     * @return array<int,array<string,mixed>>
     */
    private static function normalise(array $rules): array
    {
        usort($rules, static fn($a, $b) => ($a['priority'] ?? 0) <=> ($b['priority'] ?? 0));

        return array_values(array_filter(
            $rules,
            static fn($r) => (int) ($r['enabled'] ?? 1) === 1
        ));
    }
}
