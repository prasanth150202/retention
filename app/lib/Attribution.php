<?php
/**
 * Attribution — which campaign gets credit for an order.
 *
 * FOUR MODELS, STORED SIDE BY SIDE, NEVER RECONCILED
 *   pixel_first / pixel_last    what our own tracking saw
 *   shopify_first / shopify_last  what Shopify's customerJourneySummary says
 *
 * They will disagree. That is the point. Our pixel sees more of the journey
 * (Shopify's summary caps its moments and drops older ones) but loses whole
 * visitors to ad blockers and refused consent. Shopify's survives both but is
 * coarser. A dashboard that averaged them into one "true" number would be
 * inventing a certainty nobody has; showing both, and the gap between them,
 * is the honest version and is more useful — a large gap on one campaign is
 * usually consent or an ad blocker, and that is worth knowing.
 *
 * shopify_first/last are written by app/cron/sync.php as orders arrive. This
 * class computes the pixel pair, which needs the event shard.
 *
 * WHAT "FIRST TOUCH" MEANS HERE
 * The earliest campaign-bearing touch within LOOKBACK_DAYS before the order,
 * not the earliest ever. An unbounded window would credit a Diwali campaign
 * for a purchase the following August, which no ad platform would and no
 * merchant would believe.
 *
 * THE LIMIT WORTH STATING OUT LOUD
 * A touch is tied to a browser, not a human. Someone who sees an Instagram ad
 * on their phone and buys on a laptop leaves two visitors, and only the
 * laptop's touches are in front of us. Where the buyer is a known person we
 * union all their visitors, which recovers the cross-device case for repeat
 * buyers, but never for a first purchase. This is a floor on how good pixel
 * attribution can be, and it is why the Shopify models are kept beside it.
 */

declare(strict_types=1);

final class Attribution
{
    /** Touches older than this are not credited. */
    public const LOOKBACK_DAYS = 30;

    /**
     * Orders younger than this are left alone.
     *
     * The pixel writes to a spool file that the importer drains every five
     * minutes, and the order sync runs hourly, so an order can reach the
     * database before its own checkout event does. Attributing immediately
     * would record "no touch found" for orders whose touches simply had not
     * landed yet, and Direct/Untracked would slowly fill with other people's
     * campaigns.
     */
    public const GRACE_HOURS = 2;

    /**
     * How long an attribution can still be revised.
     *
     * Matches the rollup reclose window. After this the answer is frozen even
     * if a late event arrives, because a number that keeps moving is one a
     * merchant cannot reconcile against their ad platform.
     */
    public const RECLOSE_DAYS = 3;

    /**
     * Orders needing pixel attribution.
     *
     * Two groups: never attributed, and attributed to nothing while still
     * inside the reclose window — a late touch can still upgrade the latter.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function pending(int $tenantId, int $limit = 500): array
    {
        $stmt = Db::core()->prepare(
            "SELECT o.order_id, o.created_at, o.visitor_key, o.person_id
               FROM orders o
               LEFT JOIN order_attribution a
                      ON a.tenant_id = o.tenant_id
                     AND a.order_id  = o.order_id
                     AND a.model     = 'pixel_last'
              WHERE o.tenant_id = :tenant_id
                AND o.created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :grace HOUR)
                AND (
                      a.order_id IS NULL
                   OR (a.campaign_id IS NULL
                       AND o.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL :reclose DAY))
                )
              ORDER BY o.created_at
              LIMIT " . (int) $limit
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':grace'     => self::GRACE_HOURS,
            ':reclose'   => self::RECLOSE_DAYS,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Attribute a batch of orders.
     *
     * Batched rather than per-order on purpose. The events table carries only
     * two indexes — the row-size budget in 002_events_shard.sql explains why
     * a third costs two months of shard life — so there is no index on
     * visitor_key. Every lookup is therefore a time-range scan, and doing one
     * per order would re-scan the same window hundreds of times. One scan per
     * batch, filtered to the batch's visitors, reads that window once.
     *
     * @param array<int,array<string,mixed>> $orders rows from pending()
     * @return array{orders:int,with_touch:int,models:int}
     */
    public static function resolveBatch(int $tenantId, array $orders): array
    {
        if ($orders === []) {
            return ['orders' => 0, 'with_touch' => 0, 'models' => 0];
        }

        // Which browsers count as "this buyer". For a known person that is all
        // of their browsers; otherwise just the one that placed the order.
        $visitorsByOrder = self::visitorSets($tenantId, $orders);

        $allVisitors = [];
        foreach ($visitorsByOrder as $set) {
            foreach ($set as $v) {
                $allVisitors[$v] = true;
            }
        }

        $touches = $allVisitors === []
            ? []
            : self::touchesFor($tenantId, array_keys($allVisitors), self::window($orders));

        $withTouch = 0;
        $models    = 0;

        foreach ($orders as $order) {
            $orderId = (int) $order['order_id'];
            $placed  = (string) $order['created_at'];
            $from    = date('Y-m-d H:i:s', strtotime($placed) - self::LOOKBACK_DAYS * 86400);

            $mine = [];
            foreach ($visitorsByOrder[$orderId] ?? [] as $v) {
                foreach ($touches[$v] ?? [] as $t) {
                    if ($t['occurred_at'] >= $from && $t['occurred_at'] <= $placed) {
                        $mine[] = $t;
                    }
                }
            }

            if ($mine !== []) {
                usort($mine, static fn($a, $b) => [$a['occurred_at'], $a['id']] <=> [$b['occurred_at'], $b['id']]);
                $withTouch++;
            }

            // An order with no touches still gets rows written. "We looked and
            // found nothing" is a real finding — it is what Direct/Untracked
            // means — and an order that has never been examined is not the
            // same thing as one that was examined and had nothing.
            //
            // It does not settle the matter: pending() keeps revisiting a
            // campaign-less order until the reclose window closes, because a
            // late touch can still upgrade it. Orders that DID get a campaign
            // are never looked at again.
            $models += self::write($tenantId, $orderId, 'pixel_first', $mine === [] ? null : $mine[0]);
            $models += self::write($tenantId, $orderId, 'pixel_last', $mine === [] ? null : end($mine));
        }

        return ['orders' => count($orders), 'with_touch' => $withTouch, 'models' => $models];
    }

    /**
     * Fill in channel for attribution rows that have none.
     *
     * sync.php writes the Shopify models as orders arrive, before this class
     * has classified anything, so their channel starts NULL. A NULL channel is
     * invisible to every GROUP BY, which means revenue silently vanishes from
     * the Campaigns tab rather than appearing in a bucket.
     *
     * @return int rows updated
     */
    public static function backfillChannels(int $tenantId, int $limit = 2000): int
    {
        $pdo = Db::core();

        $stmt = $pdo->prepare(
            'SELECT order_id, model, campaign_id, referrer_id, landing_path_id
               FROM order_attribution
              WHERE tenant_id = ? AND channel IS NULL
              LIMIT ' . (int) $limit
        );
        $stmt->execute([$tenantId]);

        $upd = $pdo->prepare(
            'UPDATE order_attribution SET channel = ?
              WHERE tenant_id = ? AND order_id = ? AND model = ?'
        );

        $n = 0;
        foreach ($stmt->fetchAll() as $row) {
            $upd->execute([
                Channel::classify(
                    $tenantId,
                    $row['campaign_id'] !== null ? (int) $row['campaign_id'] : null,
                    $row['referrer_id'] !== null ? (int) $row['referrer_id'] : null,
                    $row['landing_path_id'] !== null ? (int) $row['landing_path_id'] : null
                ),
                $tenantId,
                (int) $row['order_id'],
                $row['model'],
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * How far the four models disagree.
     *
     * Surfaced in the UI rather than hidden, because the gap is diagnostic: a
     * campaign our pixel credits far less than Shopify does is usually one
     * whose audience blocks trackers or declines consent, and a merchant
     * comparing our dashboard against Meta's will otherwise conclude the
     * dashboard is broken.
     *
     * @return array<string,mixed>
     */
    public static function disagreement(int $tenantId, string $from, string $to): array
    {
        $stmt = Db::core()->prepare(
            "SELECT
                SUM(pl.channel IS NOT NULL AND sl.channel IS NOT NULL)              AS comparable,
                SUM(pl.channel = sl.channel)                                        AS agree,
                SUM(pl.channel IS NULL OR pl.channel = 'Direct/Untracked')          AS pixel_blind,
                SUM(sl.channel IS NULL OR sl.channel = 'Direct/Untracked')          AS shopify_blind,
                COUNT(*)                                                            AS orders
               FROM orders o
               LEFT JOIN order_attribution pl
                      ON pl.tenant_id = o.tenant_id AND pl.order_id = o.order_id AND pl.model = 'pixel_last'
               LEFT JOIN order_attribution sl
                      ON sl.tenant_id = o.tenant_id AND sl.order_id = o.order_id AND sl.model = 'shopify_last'
              WHERE o.tenant_id = :tenant_id
                AND o.created_at >= :from AND o.created_at < :to"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':from' => $from, ':to' => $to]);

        $row = $stmt->fetch() ?: [];

        $comparable = (int) ($row['comparable'] ?? 0);
        $agree      = (int) ($row['agree'] ?? 0);

        return [
            'orders'        => (int) ($row['orders'] ?? 0),
            'comparable'    => $comparable,
            'agree'         => $agree,
            'agree_pct'     => $comparable > 0 ? round($agree / $comparable * 100, 1) : null,
            'pixel_blind'   => (int) ($row['pixel_blind'] ?? 0),
            'shopify_blind' => (int) ($row['shopify_blind'] ?? 0),
        ];
    }

    // -----------------------------------------------------------------

    /**
     * Browsers belonging to each order's buyer.
     *
     * @param array<int,array<string,mixed>> $orders
     * @return array<int,array<int,int>> order_id => visitor keys
     */
    private static function visitorSets(int $tenantId, array $orders): array
    {
        $personIds = [];
        foreach ($orders as $o) {
            if ($o['person_id'] !== null) {
                $personIds[(int) $o['person_id']] = true;
            }
        }

        // One query for every person in the batch rather than one per order.
        $byPerson = [];
        if ($personIds !== []) {
            $list = implode(',', array_map('intval', array_keys($personIds)));
            $stmt = Db::core()->prepare(
                "SELECT person_id, visitor_key FROM dim_visitor
                  WHERE tenant_id = ? AND person_id IN ({$list})"
            );
            $stmt->execute([$tenantId]);

            foreach ($stmt->fetchAll() as $r) {
                $byPerson[(int) $r['person_id']][] = (int) $r['visitor_key'];
            }
        }

        $out = [];
        foreach ($orders as $o) {
            $orderId = (int) $o['order_id'];
            $set     = [];

            if ($o['person_id'] !== null) {
                $set = $byPerson[(int) $o['person_id']] ?? [];
            }
            if ($o['visitor_key'] !== null) {
                $set[] = (int) $o['visitor_key'];
            }

            $out[$orderId] = array_values(array_unique($set));
        }

        return $out;
    }

    /**
     * The time range one scan has to cover for a whole batch.
     *
     * @param array<int,array<string,mixed>> $orders
     * @return array{0:string,1:string}
     */
    private static function window(array $orders): array
    {
        $times = array_map(static fn($o) => strtotime((string) $o['created_at']), $orders);

        return [
            date('Y-m-d H:i:s', min($times) - self::LOOKBACK_DAYS * 86400),
            date('Y-m-d H:i:s', max($times)),
        ];
    }

    /**
     * Campaign-bearing touches for a set of visitors, from the event shards.
     *
     * A lookback can cross a year boundary, so this walks every shard the
     * window touches. Only integers cross the database boundary — the shard
     * knows campaign_id, never what campaign that is — which is what lets each
     * database keep a separate credential.
     *
     * @param array<int,int> $visitors
     * @param array{0:string,1:string} $window
     * @return array<int,array<int,array<string,mixed>>> visitor_key => touches
     */
    private static function touchesFor(int $tenantId, array $visitors, array $window): array
    {
        [$from, $to] = $window;

        $list = implode(',', array_map('intval', $visitors));
        $out  = [];

        foreach (Shard::forRange(substr($from, 0, 10), substr($to, 0, 10)) as $shard) {
            try {
                $pdo = Shard::connectionForDate((string) $shard['date_from']);
            } catch (Throwable) {
                continue;   // a shard that is registered but not reachable
            }

            $stmt = Db::tenantQuery(
                $pdo,
                "SELECT id, visitor_key, occurred_at, campaign_id, path_id, referrer_id
                   FROM events
                  WHERE tenant_id = :tenant_id
                    AND occurred_at >= :from AND occurred_at <= :to
                    AND visitor_key IN ({$list})
                    AND (campaign_id IS NOT NULL OR referrer_id IS NOT NULL)
                  ORDER BY occurred_at, id",
                $tenantId,
                [':from' => $from, ':to' => $to]
            );

            foreach ($stmt->fetchAll() as $r) {
                $out[(int) $r['visitor_key']][] = [
                    'id'          => (int) $r['id'],
                    'occurred_at' => (string) $r['occurred_at'],
                    'campaign_id' => $r['campaign_id'] !== null ? (int) $r['campaign_id'] : null,
                    'path_id'     => $r['path_id'] !== null ? (int) $r['path_id'] : null,
                    'referrer_id' => $r['referrer_id'] !== null ? (int) $r['referrer_id'] : null,
                ];
            }
        }

        return $out;
    }

    /** @param array<string,mixed>|null $touch */
    private static function write(int $tenantId, int $orderId, string $model, ?array $touch): int
    {
        $campaignId = $touch['campaign_id'] ?? null;
        $referrerId = $touch['referrer_id'] ?? null;
        $pathId     = $touch['path_id'] ?? null;

        Db::core()->prepare(
            'INSERT INTO order_attribution
                (tenant_id, order_id, model, campaign_id, channel, landing_path_id, referrer_id, touch_at)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                campaign_id     = VALUES(campaign_id),
                channel         = VALUES(channel),
                landing_path_id = VALUES(landing_path_id),
                referrer_id     = VALUES(referrer_id),
                touch_at        = VALUES(touch_at)'
        )->execute([
            $tenantId,
            $orderId,
            $model,
            $campaignId,
            Channel::classify($tenantId, $campaignId, $referrerId, $pathId),
            $pathId,
            $referrerId,
            $touch['occurred_at'] ?? null,
        ]);

        return 1;
    }
}
