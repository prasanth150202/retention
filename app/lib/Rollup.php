<?php
/**
 * Rollups — the only tables the dashboard reads.
 *
 * Nothing in the UI queries events or orders directly. Every panel reads a
 * pre-aggregated row, which is what keeps a dashboard responsive on shared
 * hosting when the events table holds tens of millions of rows.
 *
 * A DAY IS THE MERCHANT'S DAY, NOT UTC
 * Everything is stored UTC, but a store in Asia/Kolkata sells on IST days.
 * Rolling up by UTC date would put the first five and a half hours of every
 * Indian evening into the previous day, and the merchant's first act on
 * opening this dashboard is to compare yesterday's revenue against Shopify
 * admin — which reports in store time. A mismatch there costs all trust in
 * every other number on the page, however correct those are. So stat_date is
 * a local date and each rollup converts it to a UTC window.
 *
 * PROVISIONAL DAYS
 * A day stays is_provisional = 1 for RECLOSE_DAYS and is recomputed on every
 * run until it closes. Late events, refunds and Shopify's own journey data all
 * arrive after the fact. The UI is expected to mark provisional days rather
 * than hide them: a number that silently changes is worse than one labelled
 * as still settling.
 *
 * TWO DATABASES, NEVER ONE QUERY
 * Event-derived metrics come from the shard, order-derived metrics from core,
 * and they are combined here in PHP. No statement spans both, which is what
 * lets each database keep its own credential.
 */

declare(strict_types=1);

final class Rollup
{
    /** How long a day can still change. */
    public const RECLOSE_DAYS = 3;

    /** Idle gap that ends a session, in minutes. */
    public const SESSION_GAP_MIN = 30;

    /** Days measured for repeat-purchase cohorts. */
    public const COHORT_BUCKETS = [30, 60, 90, 180];

    /**
     * Local dates that need computing: never done, or still provisional.
     *
     * @return array<int,string>
     */
    public static function pendingDays(int $tenantId, int $limit = 10): array
    {
        $tz    = self::timezone($tenantId);
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

        $stmt = Db::core()->prepare(
            'SELECT MAX(stat_date) FROM rollup_daily_kpi
              WHERE tenant_id = ? AND is_provisional = 0'
        );
        $stmt->execute([$tenantId]);
        $lastClosed = $stmt->fetchColumn();

        if ($lastClosed !== false && $lastClosed !== null) {
            $start = (new DateTimeImmutable((string) $lastClosed, $tz))->modify('+1 day');
        } else {
            $start = self::firstDataDay($tenantId, $tz);
        }

        $end  = new DateTimeImmutable($today, $tz);
        $days = [];

        for ($d = $start; $d <= $end && count($days) < $limit; $d = $d->modify('+1 day')) {
            $days[] = $d->format('Y-m-d');
        }

        return $days;
    }

    /**
     * Recompute every daily rollup for one local date.
     *
     * @return array<string,int> rows written per rollup
     */
    public static function day(int $tenantId, string $localDate): array
    {
        [$from, $to] = self::utcRange($tenantId, $localDate);

        $provisional = self::isProvisional($tenantId, $localDate) ? 1 : 0;

        $written = [];
        $written['kpi']      = self::kpi($tenantId, $localDate, $from, $to, $provisional);
        $written['funnel']   = self::funnel($tenantId, $localDate, $from, $to, $provisional);
        $written['campaign'] = self::campaign($tenantId, $localDate, $from, $to, $provisional);
        $written['channel']  = self::channel($tenantId, $localDate, $from, $to, $provisional);
        $written['landing']  = self::landing($tenantId, $localDate, $from, $to, $provisional);
        $written['product']  = self::product($tenantId, $localDate, $from, $to, $provisional);
        $written['geo']      = self::geo($tenantId, $localDate, $from, $to, $provisional);
        $written['device']   = self::device($tenantId, $localDate, $from, $to, $provisional);
        $written['abandon']  = self::abandon($tenantId, $localDate, $from, $to, $provisional);

        return $written;
    }

    /**
     * Recompute the snapshot rollups: repeat cohorts and the order-count
     * distribution.
     *
     * These are not per-day. A cohort's 180-day figure changes for six months
     * after the cohort closes, so they are recomputed wholesale rather than
     * incrementally — cheap, because they read only `orders`.
     *
     * @return array<string,int>
     */
    public static function cohorts(int $tenantId): array
    {
        return [
            'repeat'          => self::cohortRepeat($tenantId),
            'campaign_cohort' => self::cohortCampaign($tenantId),
            'person_orders'   => self::personOrders($tenantId),
        ];
    }

    // -----------------------------------------------------------------
    // Daily rollups
    // -----------------------------------------------------------------

    private static function kpi(int $t, string $date, string $from, string $to, int $prov): int
    {
        $ev = self::shardRow(
            $t,
            "SELECT
                COUNT(DISTINCT visitor_key)                                          AS visitors,
                SUM(event_type = :page)                                              AS pageviews,
                COUNT(DISTINCT CASE WHEN event_type = :product THEN visitor_key END) AS product_viewers,
                COUNT(DISTINCT CASE WHEN event_type = :atc THEN visitor_key END)     AS atc_visitors,
                COUNT(DISTINCT CASE WHEN event_type = :checkout THEN visitor_key END) AS checkout_visitors,
                COUNT(DISTINCT CASE WHEN event_type = :payment THEN visitor_key END) AS payment_visitors
               FROM events
              WHERE tenant_id = :tenant_id AND occurred_at >= :from AND occurred_at < :to",
            $from,
            $to,
            [
                ':page'     => EventType::PAGE_VIEWED,
                ':product'  => EventType::PRODUCT_VIEWED,
                ':atc'      => EventType::PRODUCT_ADDED_TO_CART,
                ':checkout' => EventType::CHECKOUT_STARTED,
                ':payment'  => EventType::PAYMENT_INFO_SUBMITTED,
            ]
        );

        $stmt = Db::core()->prepare(
            'SELECT
                COUNT(*)                                                  AS orders,
                COUNT(DISTINCT o.person_id)                               AS purchasers,
                COALESCE(SUM(o.total_minor), 0)                           AS revenue_minor,
                COALESCE(SUM(o.refunded_minor), 0)                        AS refunded_minor,
                COUNT(DISTINCT CASE WHEN o.order_sequence = 1 THEN o.person_id END) AS new_customers
               FROM orders o
              WHERE o.tenant_id = :tenant_id
                AND o.created_at >= :from AND o.created_at < :to
                AND o.cancelled_at IS NULL'
        );
        $stmt->execute([':tenant_id' => $t, ':from' => $from, ':to' => $to]);
        $or = $stmt->fetch() ?: [];

        // Returning customers are DERIVED, never counted separately.
        //
        // Counting them as "people with order_sequence > 1 today" double counts
        // anyone who bought twice in one day: their first-ever order and their
        // second both fall on this date, so they appear as new AND returning.
        // The two then sum to more than the number of people who actually
        // bought, and any repeat rate dividing by that sum comes out too low.
        //
        // A person is new on the day they became a customer, and returning on
        // any later day they buy. Never both — "were they a customer before
        // today" has exactly one answer.
        $or['repeat_customers'] = max(
            0,
            (int) ($or['purchasers'] ?? 0) - (int) ($or['new_customers'] ?? 0)
        );

        // Units are a separate statement rather than a subquery because PDO
        // runs with native prepares here, and those cannot bind the same
        // named placeholder twice.
        $stmt = Db::core()->prepare(
            'SELECT COALESCE(SUM(li.quantity), 0) AS units
               FROM order_line_items li
               JOIN orders o ON o.tenant_id = li.tenant_id AND o.order_id = li.order_id
              WHERE li.tenant_id = :tenant_id
                AND o.created_at >= :from AND o.created_at < :to
                AND o.cancelled_at IS NULL'
        );
        $stmt->execute([':tenant_id' => $t, ':from' => $from, ':to' => $to]);
        $or['units'] = (int) $stmt->fetchColumn();

        Db::core()->prepare(
            'INSERT INTO rollup_daily_kpi
                (tenant_id, stat_date, visitors, sessions, pageviews, product_viewers,
                 atc_visitors, checkout_visitors, payment_visitors, purchasers, orders,
                 units, revenue_minor, refunded_minor, new_customers, repeat_customers,
                 is_provisional, computed_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                visitors = VALUES(visitors), sessions = VALUES(sessions),
                pageviews = VALUES(pageviews), product_viewers = VALUES(product_viewers),
                atc_visitors = VALUES(atc_visitors), checkout_visitors = VALUES(checkout_visitors),
                payment_visitors = VALUES(payment_visitors), purchasers = VALUES(purchasers),
                orders = VALUES(orders), units = VALUES(units),
                revenue_minor = VALUES(revenue_minor), refunded_minor = VALUES(refunded_minor),
                new_customers = VALUES(new_customers), repeat_customers = VALUES(repeat_customers),
                is_provisional = VALUES(is_provisional), computed_at = UTC_TIMESTAMP()'
        )->execute([
            $t, $date,
            (int) ($ev['visitors'] ?? 0),
            self::sessions($t, $from, $to),
            (int) ($ev['pageviews'] ?? 0),
            (int) ($ev['product_viewers'] ?? 0),
            (int) ($ev['atc_visitors'] ?? 0),
            (int) ($ev['checkout_visitors'] ?? 0),
            (int) ($ev['payment_visitors'] ?? 0),
            (int) ($or['purchasers'] ?? 0),
            (int) ($or['orders'] ?? 0),
            (int) ($or['units'] ?? 0),
            (int) ($or['revenue_minor'] ?? 0),
            (int) ($or['refunded_minor'] ?? 0),
            (int) ($or['new_customers'] ?? 0),
            (int) ($or['repeat_customers'] ?? 0),
            $prov,
        ]);

        return 1;
    }

    /**
     * Funnel, counted two ways on purpose.
     *
     * `reached` is how many visitors fired a step at all. `strict` is how many
     * fired every earlier step first, in order. The two differ a great deal in
     * practice — someone landing straight on a product page from an ad never
     * fires a plain page view — and reporting only one of them is how a funnel
     * ends up showing more add-to-carts than product views, which reads as a
     * broken dashboard rather than as a direct-to-product ad working well.
     */
    private static function funnel(int $t, string $date, string $from, string $to, int $prov): int
    {
        $steps = EventType::FUNNEL_STEPS;

        $cases = [];
        $args  = [];
        foreach ($steps as $n => $type) {
            $cases[] = "MIN(CASE WHEN event_type = :t{$n} THEN occurred_at END) AS t{$n}";
            $cases[] = "SUM(event_type = :c{$n}) AS c{$n}";
            $args[":t{$n}"] = $type;
            $args[":c{$n}"] = $type;
        }

        $rows = self::shardAll(
            $t,
            'SELECT visitor_key, ' . implode(', ', $cases) . '
               FROM events
              WHERE tenant_id = :tenant_id AND occurred_at >= :from AND occurred_at < :to
              GROUP BY visitor_key',
            $from,
            $to,
            $args
        );

        $reached = array_fill_keys(array_keys($steps), 0);
        $strict  = array_fill_keys(array_keys($steps), 0);
        $events  = array_fill_keys(array_keys($steps), 0);

        foreach ($rows as $r) {
            $stillStrict = true;
            $prev        = null;

            foreach (array_keys($steps) as $n) {
                $at = $r["t{$n}"] ?? null;

                $events[$n] += (int) ($r["c{$n}"] ?? 0);

                if ($at !== null) {
                    $reached[$n]++;
                }

                // Strict means: this step happened, and no earlier step is
                // missing or later than it.
                if ($stillStrict && $at !== null && ($prev === null || $at >= $prev)) {
                    $strict[$n]++;
                    $prev = $at;
                } else {
                    $stillStrict = false;
                }
            }
        }

        $ins = Db::core()->prepare(
            'INSERT INTO rollup_daily_funnel
                (tenant_id, stat_date, step, reached_visitors, strict_visitors, events,
                 is_provisional, computed_at)
             VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                reached_visitors = VALUES(reached_visitors),
                strict_visitors  = VALUES(strict_visitors),
                events           = VALUES(events),
                is_provisional   = VALUES(is_provisional),
                computed_at      = UTC_TIMESTAMP()'
        );

        foreach (array_keys($steps) as $n) {
            $ins->execute([$t, $date, $n, $reached[$n], $strict[$n], $events[$n], $prov]);
        }

        return count($steps);
    }

    /**
     * Campaign performance, per attribution model.
     *
     * Traffic-side numbers (visitors, product views, add-to-carts) come from
     * the events themselves and are the same under every model — a visit
     * carrying a UTM tag belongs to that campaign, full stop. Order-side
     * numbers come from order_attribution and DO differ by model, which is the
     * comparison the Campaigns tab exists to show.
     */
    private static function campaign(int $t, string $date, string $from, string $to, int $prov): int
    {
        $traffic = self::shardAll(
            $t,
            "SELECT COALESCE(campaign_id, 0) AS campaign_id,
                    COUNT(DISTINCT visitor_key)      AS visitors,
                    SUM(event_type = :product)       AS product_views,
                    SUM(event_type = :atc)           AS atc,
                    SUM(event_type = :checkout)      AS checkouts_started
               FROM events
              WHERE tenant_id = :tenant_id AND occurred_at >= :from AND occurred_at < :to
              GROUP BY COALESCE(campaign_id, 0)",
            $from,
            $to,
            [
                ':product'  => EventType::PRODUCT_VIEWED,
                ':atc'      => EventType::PRODUCT_ADDED_TO_CART,
                ':checkout' => EventType::CHECKOUT_STARTED,
            ]
        );

        $sessions = self::sessionsByCampaign($t, $from, $to);

        $stmt = Db::core()->prepare(
            "SELECT a.model,
                    COALESCE(a.campaign_id, 0) AS campaign_id,
                    COUNT(*)                        AS orders,
                    COALESCE(SUM(o.total_minor), 0) AS revenue_minor,
                    COUNT(DISTINCT o.person_id)                                         AS buyers,
                    COUNT(DISTINCT CASE WHEN o.order_sequence = 1 THEN o.person_id END) AS new_customers
               FROM orders o
               JOIN order_attribution a
                 ON a.tenant_id = o.tenant_id AND a.order_id = o.order_id
              WHERE o.tenant_id = :tenant_id
                AND o.created_at >= :from AND o.created_at < :to
                AND o.cancelled_at IS NULL
              GROUP BY a.model, COALESCE(a.campaign_id, 0)"
        );
        $stmt->execute([':tenant_id' => $t, ':from' => $from, ':to' => $to]);

        $orders = [];
        foreach ($stmt->fetchAll() as $r) {
            // Same partition as the daily KPI above, for the same reason.
            $r['returning_customers'] = max(
                0,
                (int) $r['buyers'] - (int) $r['new_customers']
            );
            $orders[$r['model']][(int) $r['campaign_id']] = $r;
        }

        $models = ['pixel_first', 'pixel_last', 'shopify_first', 'shopify_last'];
        $ins    = Db::core()->prepare(
            'INSERT INTO rollup_daily_campaign
                (tenant_id, stat_date, model, campaign_id, visitors, sessions, product_views,
                 atc, checkouts_started, orders, revenue_minor, new_customers,
                 returning_customers, is_provisional, computed_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                visitors = VALUES(visitors), sessions = VALUES(sessions),
                product_views = VALUES(product_views), atc = VALUES(atc),
                checkouts_started = VALUES(checkouts_started), orders = VALUES(orders),
                revenue_minor = VALUES(revenue_minor), new_customers = VALUES(new_customers),
                returning_customers = VALUES(returning_customers),
                is_provisional = VALUES(is_provisional), computed_at = UTC_TIMESTAMP()'
        );

        $n = 0;
        foreach ($models as $model) {
            $ids = array_unique(array_merge(
                array_map(static fn($r) => (int) $r['campaign_id'], $traffic),
                array_keys($orders[$model] ?? [])
            ));

            foreach ($ids as $cid) {
                $tr = self::pick($traffic, 'campaign_id', $cid);
                $or = $orders[$model][$cid] ?? [];

                $ins->execute([
                    $t, $date, $model, $cid,
                    (int) ($tr['visitors'] ?? 0),
                    (int) ($sessions[$cid] ?? 0),
                    (int) ($tr['product_views'] ?? 0),
                    (int) ($tr['atc'] ?? 0),
                    (int) ($tr['checkouts_started'] ?? 0),
                    (int) ($or['orders'] ?? 0),
                    (int) ($or['revenue_minor'] ?? 0),
                    (int) ($or['new_customers'] ?? 0),
                    (int) ($or['returning_customers'] ?? 0),
                    $prov,
                ]);
                $n++;
            }
        }

        return $n;
    }

    /** Channel is the campaign rollup folded up by channel name. */
    private static function channel(int $t, string $date, string $from, string $to, int $prov): int
    {
        $traffic = self::shardAll(
            $t,
            'SELECT COALESCE(campaign_id, 0) AS campaign_id,
                    COALESCE(referrer_id, 0) AS referrer_id,
                    COUNT(DISTINCT visitor_key) AS visitors
               FROM events
              WHERE tenant_id = :tenant_id AND occurred_at >= :from AND occurred_at < :to
              GROUP BY COALESCE(campaign_id, 0), COALESCE(referrer_id, 0)',
            $from,
            $to
        );

        $visitors = [];
        foreach ($traffic as $r) {
            $ch = Channel::classify(
                $t,
                (int) $r['campaign_id'] ?: null,
                (int) $r['referrer_id'] ?: null,
                null
            );
            // Distinct visitors do not sum across groups without double
            // counting anyone who arrived twice under different tags. Stated
            // rather than hidden: this is an upper bound, and the KPI card
            // remains the authority on total visitors.
            $visitors[$ch] = ($visitors[$ch] ?? 0) + (int) $r['visitors'];
        }

        $stmt = Db::core()->prepare(
            "SELECT a.model,
                    COALESCE(a.channel, 'Direct/Untracked') AS channel,
                    COUNT(*) AS orders,
                    COALESCE(SUM(o.total_minor), 0) AS revenue_minor
               FROM orders o
               JOIN order_attribution a
                 ON a.tenant_id = o.tenant_id AND a.order_id = o.order_id
              WHERE o.tenant_id = :tenant_id
                AND o.created_at >= :from AND o.created_at < :to
                AND o.cancelled_at IS NULL
              GROUP BY a.model, COALESCE(a.channel, 'Direct/Untracked')"
        );
        $stmt->execute([':tenant_id' => $t, ':from' => $from, ':to' => $to]);

        $ins = Db::core()->prepare(
            'INSERT INTO rollup_daily_channel
                (tenant_id, stat_date, model, channel, visitors, orders, revenue_minor,
                 is_provisional, computed_at)
             VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                visitors = VALUES(visitors), orders = VALUES(orders),
                revenue_minor = VALUES(revenue_minor),
                is_provisional = VALUES(is_provisional), computed_at = UTC_TIMESTAMP()'
        );

        $seen = [];
        $n    = 0;

        foreach ($stmt->fetchAll() as $r) {
            $ch = (string) $r['channel'];
            $ins->execute([
                $t, $date, $r['model'], $ch,
                (int) ($visitors[$ch] ?? 0),
                (int) $r['orders'],
                (int) $r['revenue_minor'],
                $prov,
            ]);
            $seen[$r['model']][$ch] = true;
            $n++;
        }

        // Channels that brought traffic but no orders still belong on the
        // page. A channel that only ever appears when it converts makes every
        // channel look like it converts.
        foreach (['pixel_first', 'pixel_last', 'shopify_first', 'shopify_last'] as $model) {
            foreach ($visitors as $ch => $v) {
                if (!isset($seen[$model][$ch])) {
                    $ins->execute([$t, $date, $model, $ch, $v, 0, 0, $prov]);
                    $n++;
                }
            }
        }

        return $n;
    }

    private static function landing(int $t, string $date, string $from, string $to, int $prov): int
    {
        $traffic = self::shardAll(
            $t,
            'SELECT path_id, COUNT(DISTINCT visitor_key) AS visitors
               FROM events
              WHERE tenant_id = :tenant_id AND occurred_at >= :from AND occurred_at < :to
                AND path_id IS NOT NULL
              GROUP BY path_id',
            $from,
            $to
        );

        // Landing page for an order is the one its last pixel touch arrived
        // on, which is what order_attribution already records.
        $stmt = Db::core()->prepare(
            "SELECT a.landing_path_id AS path_id,
                    COUNT(*) AS orders,
                    COALESCE(SUM(o.total_minor), 0) AS revenue_minor
               FROM orders o
               JOIN order_attribution a
                 ON a.tenant_id = o.tenant_id AND a.order_id = o.order_id AND a.model = 'pixel_last'
              WHERE o.tenant_id = :tenant_id
                AND o.created_at >= :from AND o.created_at < :to
                AND o.cancelled_at IS NULL
                AND a.landing_path_id IS NOT NULL
              GROUP BY a.landing_path_id"
        );
        $stmt->execute([':tenant_id' => $t, ':from' => $from, ':to' => $to]);

        $orders = [];
        foreach ($stmt->fetchAll() as $r) {
            $orders[(int) $r['path_id']] = $r;
        }

        $ins = Db::core()->prepare(
            'INSERT INTO rollup_daily_landing
                (tenant_id, stat_date, path_id, visitors, orders, revenue_minor,
                 is_provisional, computed_at)
             VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                visitors = VALUES(visitors), orders = VALUES(orders),
                revenue_minor = VALUES(revenue_minor),
                is_provisional = VALUES(is_provisional), computed_at = UTC_TIMESTAMP()'
        );

        $ids = array_unique(array_merge(
            array_map(static fn($r) => (int) $r['path_id'], $traffic),
            array_keys($orders)
        ));

        foreach ($ids as $pid) {
            $tr = self::pick($traffic, 'path_id', $pid);
            $or = $orders[$pid] ?? [];

            $ins->execute([
                $t, $date, $pid,
                (int) ($tr['visitors'] ?? 0),
                (int) ($or['orders'] ?? 0),
                (int) ($or['revenue_minor'] ?? 0),
                $prov,
            ]);
        }

        return count($ids);
    }

    private static function product(int $t, string $date, string $from, string $to, int $prov): int
    {
        $traffic = self::shardAll(
            $t,
            'SELECT product_id,
                    SUM(event_type = :viewed) AS views,
                    COUNT(DISTINCT CASE WHEN event_type = :viewer THEN visitor_key END) AS viewers,
                    SUM(event_type = :atc)   AS atc
               FROM events
              WHERE tenant_id = :tenant_id AND occurred_at >= :from AND occurred_at < :to
                AND product_id IS NOT NULL
              GROUP BY product_id',
            $from,
            $to,
            [':viewed' => EventType::PRODUCT_VIEWED,
             ':viewer' => EventType::PRODUCT_VIEWED,
             ':atc'    => EventType::PRODUCT_ADDED_TO_CART]
        );

        $stmt = Db::core()->prepare(
            'SELECT li.product_id,
                    COUNT(DISTINCT li.order_id) AS purchases,
                    COALESCE(SUM(li.quantity), 0) AS units,
                    COALESCE(SUM(li.quantity * COALESCE(li.price_minor, 0) - li.discount_minor), 0) AS revenue_minor
               FROM order_line_items li
               JOIN orders o ON o.tenant_id = li.tenant_id AND o.order_id = li.order_id
              WHERE li.tenant_id = :tenant_id
                AND o.created_at >= :from AND o.created_at < :to
                AND o.cancelled_at IS NULL
                AND li.product_id IS NOT NULL
              GROUP BY li.product_id'
        );
        $stmt->execute([':tenant_id' => $t, ':from' => $from, ':to' => $to]);

        $sold = [];
        foreach ($stmt->fetchAll() as $r) {
            $sold[(int) $r['product_id']] = $r;
        }

        // "Most abandoned product" is the question merchants actually ask, and
        // it is answered from carts that were never completed, not from
        // add-to-cart events that later converted.
        $stmt = Db::core()->prepare(
            'SELECT ci.product_id, COUNT(DISTINCT ci.checkout_id) AS abandons
               FROM abandoned_checkout_items ci
               JOIN abandoned_checkouts c
                 ON c.tenant_id = ci.tenant_id AND c.checkout_id = ci.checkout_id
              WHERE ci.tenant_id = :tenant_id
                AND c.created_at >= :from AND c.created_at < :to
                AND c.completed_at IS NULL
                AND ci.product_id IS NOT NULL
              GROUP BY ci.product_id'
        );
        $stmt->execute([':tenant_id' => $t, ':from' => $from, ':to' => $to]);

        $abandoned = [];
        foreach ($stmt->fetchAll() as $r) {
            $abandoned[(int) $r['product_id']] = (int) $r['abandons'];
        }

        $ins = Db::core()->prepare(
            'INSERT INTO rollup_daily_product
                (tenant_id, stat_date, product_id, views, viewers, atc, purchases, units,
                 revenue_minor, abandons, is_provisional, computed_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                views = VALUES(views), viewers = VALUES(viewers), atc = VALUES(atc),
                purchases = VALUES(purchases), units = VALUES(units),
                revenue_minor = VALUES(revenue_minor), abandons = VALUES(abandons),
                is_provisional = VALUES(is_provisional), computed_at = UTC_TIMESTAMP()'
        );

        $ids = array_unique(array_merge(
            array_map(static fn($r) => (int) $r['product_id'], $traffic),
            array_keys($sold),
            array_keys($abandoned)
        ));

        foreach ($ids as $pid) {
            $tr = self::pick($traffic, 'product_id', $pid);
            $so = $sold[$pid] ?? [];

            $ins->execute([
                $t, $date, $pid,
                (int) ($tr['views'] ?? 0),
                (int) ($tr['viewers'] ?? 0),
                (int) ($tr['atc'] ?? 0),
                (int) ($so['purchases'] ?? 0),
                (int) ($so['units'] ?? 0),
                (int) ($so['revenue_minor'] ?? 0),
                $abandoned[$pid] ?? 0,
                $prov,
            ]);
        }

        return count($ids);
    }

    private static function geo(int $t, string $date, string $from, string $to, int $prov): int
    {
        return self::visitorDimension(
            $t,
            $date,
            $from,
            $to,
            $prov,
            'geo_id',
            'rollup_daily_geo'
        );
    }

    private static function device(int $t, string $date, string $from, string $to, int $prov): int
    {
        // Device needs the human-readable browser and os columns, which live on
        // dim_useragent in core while the events carry only ua_id.
        $rows = self::shardAll(
            $t,
            'SELECT ua_id, visitor_key
               FROM events
              WHERE tenant_id = :tenant_id AND occurred_at >= :from AND occurred_at < :to
                AND ua_id IS NOT NULL',
            $from,
            $to
        );

        $uaByVisitor = [];
        $visitorsByUa = [];
        foreach ($rows as $r) {
            $ua = (int) $r['ua_id'];
            $v  = (int) $r['visitor_key'];

            $uaByVisitor[$v]      = $ua;   // last seen wins
            $visitorsByUa[$ua][$v] = true;
        }

        $orderCounts = self::ordersByVisitor($t, $from, $to);

        $agg = [];
        foreach ($visitorsByUa as $ua => $visitors) {
            $agg[$ua] = ['visitors' => count($visitors), 'orders' => 0, 'revenue' => 0];
        }
        foreach ($orderCounts as $v => $o) {
            $ua = $uaByVisitor[$v] ?? null;
            if ($ua === null) {
                continue;
            }
            $agg[$ua]['orders']  += $o['orders'];
            $agg[$ua]['revenue'] += $o['revenue'];
        }

        // Several user agents fold into one (device_type, browser, os) row, so
        // this rollup accumulates rather than replaces. It therefore has to
        // start from zero on a recompute, or a reclosed day would add its
        // totals on top of the ones already there.
        Db::core()->prepare('DELETE FROM rollup_daily_device WHERE tenant_id = ? AND stat_date = ?')
            ->execute([$t, $date]);

        $ins = Db::core()->prepare(
            'INSERT INTO rollup_daily_device
                (tenant_id, stat_date, device_type, browser, os, visitors, orders,
                 revenue_minor, is_provisional, computed_at)
             VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                visitors = visitors + VALUES(visitors),
                orders = orders + VALUES(orders),
                revenue_minor = revenue_minor + VALUES(revenue_minor),
                is_provisional = VALUES(is_provisional), computed_at = UTC_TIMESTAMP()'
        );

        $lookup = Db::core()->prepare(
            'SELECT device_type, browser, os FROM dim_useragent WHERE tenant_id = ? AND ua_id = ?'
        );

        $n = 0;
        foreach ($agg as $ua => $a) {
            $lookup->execute([$t, $ua]);
            $d = $lookup->fetch() ?: ['device_type' => 0, 'browser' => '', 'os' => ''];

            $ins->execute([
                $t, $date,
                (int) $d['device_type'],
                (string) ($d['browser'] ?? ''),
                (string) ($d['os'] ?? ''),
                $a['visitors'], $a['orders'], $a['revenue'], $prov,
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * The checkout micro-funnel and abandonment.
     *
     * Stages 3-6 do not exist in Shopify's own abandoned-checkout records,
     * which only begin once contact details are submitted. A merchant told
     * "you had 40 abandoned checkouts" by Shopify and 180 by us will assume we
     * are wrong, so shopify_records carries the number that reconciles with
     * their admin, beside the fuller picture.
     *
     * ADVANCED IS MEASURED PER VISITOR, NOT PER STAGE
     * Shoppers skip stages. A returning customer with a saved address goes
     * from checkout_started straight to payment, firing nothing in between.
     * Counting "advanced" as however many people entered the NEXT stage then
     * reports more people leaving a stage than ever arrived at it — stage 5
     * with 0 entered and 1 advanced — which is the same inversion the main
     * funnel avoids with its strict path. So advanced means: of the visitors
     * who reached THIS stage, how many went on to reach ANY later one. That
     * is monotone by construction, and abandoned is the remainder.
     */
    private static function abandon(int $t, string $date, string $from, string $to, int $prov): int
    {
        $stages = EventType::ABANDON_STAGES;

        $cases = [];
        $args  = [];
        foreach ($stages as $n => $type) {
            $cases[] = "MAX(event_type = :s{$n}) AS s{$n}";
            $args[":s{$n}"] = $type;
        }

        $rows = self::shardAll(
            $t,
            'SELECT visitor_key, ' . implode(', ', $cases) . '
               FROM events
              WHERE tenant_id = :tenant_id AND occurred_at >= :from AND occurred_at < :to
              GROUP BY visitor_key',
            $from,
            $to,
            $args
        );

        $keys     = array_keys($stages);
        $entered  = array_fill_keys($keys, 0);
        $advanced = array_fill_keys($keys, 0);

        foreach ($rows as $r) {
            foreach ($keys as $i => $stage) {
                if (empty($r["s{$stage}"])) {
                    continue;
                }

                $entered[$stage]++;

                foreach (array_slice($keys, $i + 1) as $later) {
                    if (!empty($r["s{$later}"])) {
                        $advanced[$stage]++;
                        break;
                    }
                }
            }
        }

        // The last stage is completion: reaching it IS advancing, and nobody
        // abandons from it.
        $last            = $keys[count($keys) - 1];
        $advanced[$last] = $entered[$last];

        $stmt = Db::core()->prepare(
            'SELECT COUNT(*) AS records, COALESCE(SUM(total_minor), 0) AS value_minor
               FROM abandoned_checkouts
              WHERE tenant_id = :tenant_id
                AND created_at >= :from AND created_at < :to
                AND completed_at IS NULL'
        );
        $stmt->execute([':tenant_id' => $t, ':from' => $from, ':to' => $to]);
        $shopify = $stmt->fetch() ?: ['records' => 0, 'value_minor' => 0];

        $ins = Db::core()->prepare(
            'INSERT INTO rollup_daily_abandon
                (tenant_id, stat_date, stage, entered, advanced, abandoned, value_minor,
                 shopify_records, is_provisional, computed_at)
             VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                entered = VALUES(entered), advanced = VALUES(advanced),
                abandoned = VALUES(abandoned), value_minor = VALUES(value_minor),
                shopify_records = VALUES(shopify_records),
                is_provisional = VALUES(is_provisional), computed_at = UTC_TIMESTAMP()'
        );

        foreach ($keys as $stage) {
            $ins->execute([
                $t, $date, $stage,
                $entered[$stage],
                $advanced[$stage],
                $entered[$stage] - $advanced[$stage],
                // Value at risk is only known for the records Shopify gives
                // us, so it is attached to the stage those records begin at
                // rather than spread across stages we would be guessing at.
                $stage === 3 ? (int) $shopify['value_minor'] : 0,
                $stage === 3 ? (int) $shopify['records'] : 0,
                $prov,
            ]);
        }

        return count($keys);
    }
    // -----------------------------------------------------------------
    // Snapshot rollups
    // -----------------------------------------------------------------

    /**
     * Repeat purchase by acquisition month.
     *
     * The headline retention number. A cohort is everyone whose FIRST order
     * fell in that month; reordered is how many placed another within N days
     * of that first order.
     */
    private static function cohortRepeat(int $tenantId): int
    {
        $pdo = Db::core();
        $ins = $pdo->prepare(
            'INSERT INTO rollup_cohort_repeat
                (tenant_id, cohort_month, days_bucket, cohort_size, reordered, computed_at)
             VALUES (?,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                cohort_size = VALUES(cohort_size), reordered = VALUES(reordered),
                computed_at = UTC_TIMESTAMP()'
        );

        $n = 0;
        foreach (self::COHORT_BUCKETS as $days) {
            $stmt = $pdo->prepare(
                'SELECT DATE_FORMAT(f.first_at, "%Y-%m-01") AS cohort_month,
                        COUNT(*) AS cohort_size,
                        SUM(f.next_at IS NOT NULL AND f.next_at <= f.first_at + INTERVAL :days DAY) AS reordered
                   FROM (
                        SELECT o.person_id,
                               MIN(o.created_at) AS first_at,
                               MIN(CASE WHEN o.order_sequence = 2 THEN o.created_at END) AS next_at
                          FROM orders o
                         WHERE o.tenant_id = :tenant_id
                           AND o.person_id IS NOT NULL
                           AND o.cancelled_at IS NULL
                         GROUP BY o.person_id
                   ) f
                  GROUP BY cohort_month'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':days' => $days]);

            foreach ($stmt->fetchAll() as $r) {
                $ins->execute([
                    $tenantId, $r['cohort_month'], $days,
                    (int) $r['cohort_size'], (int) $r['reordered'],
                ]);
                $n++;
            }
        }

        return $n;
    }

    /**
     * The same curve, split by the campaign that acquired the cohort.
     *
     * This is the question worth more than any single-day figure: which
     * campaigns bring buyers who come back. A campaign with a good cost per
     * first order and a terrible 90-day repeat rate is losing money slowly,
     * and nothing on a daily dashboard shows it.
     */
    private static function cohortCampaign(int $tenantId): int
    {
        $pdo = Db::core();
        $ins = $pdo->prepare(
            'INSERT INTO rollup_campaign_cohort
                (tenant_id, campaign_id, cohort_month, days_bucket, cohort_size, reordered, computed_at)
             VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                cohort_size = VALUES(cohort_size), reordered = VALUES(reordered),
                computed_at = UTC_TIMESTAMP()'
        );

        $n = 0;
        foreach (self::COHORT_BUCKETS as $days) {
            $stmt = $pdo->prepare(
                'SELECT COALESCE(f.campaign_id, 0) AS campaign_id,
                        DATE_FORMAT(f.first_at, "%Y-%m-01") AS cohort_month,
                        COUNT(*) AS cohort_size,
                        SUM(f.next_at IS NOT NULL AND f.next_at <= f.first_at + INTERVAL :days DAY) AS reordered
                   FROM (
                        SELECT o.person_id,
                               MIN(o.created_at) AS first_at,
                               MIN(CASE WHEN o.order_sequence = 2 THEN o.created_at END) AS next_at,
                               MIN(CASE WHEN o.order_sequence = 1 THEN a.campaign_id END) AS campaign_id
                          FROM orders o
                          LEFT JOIN order_attribution a
                                 ON a.tenant_id = o.tenant_id
                                AND a.order_id  = o.order_id
                                AND a.model     = "pixel_first"
                         WHERE o.tenant_id = :tenant_id
                           AND o.person_id IS NOT NULL
                           AND o.cancelled_at IS NULL
                         GROUP BY o.person_id
                   ) f
                  GROUP BY COALESCE(f.campaign_id, 0), cohort_month'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':days' => $days]);

            foreach ($stmt->fetchAll() as $r) {
                $ins->execute([
                    $tenantId, (int) $r['campaign_id'], $r['cohort_month'], $days,
                    (int) $r['cohort_size'], (int) $r['reordered'],
                ]);
                $n++;
            }
        }

        return $n;
    }

    /** How many people have bought once, twice, three times, four or more. */
    private static function personOrders(int $tenantId): int
    {
        $pdo   = Db::core();
        $today = self::today($tenantId);

        $stmt = $pdo->prepare(
            'SELECT LEAST(cnt, 4) AS bucket, COUNT(*) AS persons
               FROM (
                    SELECT person_id, COUNT(*) AS cnt
                      FROM orders
                     WHERE tenant_id = :tenant_id
                       AND person_id IS NOT NULL
                       AND cancelled_at IS NULL
                     GROUP BY person_id
               ) p
              GROUP BY LEAST(cnt, 4)'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        $ins = $pdo->prepare(
            'INSERT INTO rollup_person_orders
                (tenant_id, as_of_date, order_count_bucket, persons, computed_at)
             VALUES (?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE persons = VALUES(persons), computed_at = UTC_TIMESTAMP()'
        );

        $n = 0;
        foreach ($stmt->fetchAll() as $r) {
            $ins->execute([$tenantId, $today, (int) $r['bucket'], (int) $r['persons']]);
            $n++;
        }

        return $n;
    }

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

    /**
     * Sessions, split on a gap of SESSION_GAP_MIN with no activity.
     *
     * A session that runs across the local midnight is counted in both days.
     * That is the conventional treatment and the alternative — assigning it
     * wholly to one day — makes daily visitor and session counts disagree.
     */
    private static function sessions(int $tenantId, string $from, string $to): int
    {
        $rows = self::shardAll(
            $tenantId,
            'SELECT visitor_key, occurred_at
               FROM events
              WHERE tenant_id = :tenant_id AND occurred_at >= :from AND occurred_at < :to
              ORDER BY visitor_key, occurred_at',
            $from,
            $to
        );

        $sessions = 0;
        $lastKey  = null;
        $lastAt   = null;

        foreach ($rows as $r) {
            $key = (int) $r['visitor_key'];
            $at  = strtotime((string) $r['occurred_at']);

            if ($key !== $lastKey || $at - (int) $lastAt > self::SESSION_GAP_MIN * 60) {
                $sessions++;
            }

            $lastKey = $key;
            $lastAt  = $at;
        }

        return $sessions;
    }

    /** @return array<int,int> campaign_id => sessions */
    private static function sessionsByCampaign(int $tenantId, string $from, string $to): array
    {
        $rows = self::shardAll(
            $tenantId,
            'SELECT COALESCE(campaign_id, 0) AS campaign_id, visitor_key, occurred_at
               FROM events
              WHERE tenant_id = :tenant_id AND occurred_at >= :from AND occurred_at < :to
              ORDER BY COALESCE(campaign_id, 0), visitor_key, occurred_at',
            $from,
            $to
        );

        $out     = [];
        $lastKey = null;
        $lastAt  = null;

        foreach ($rows as $r) {
            $cid = (int) $r['campaign_id'];
            $key = $cid . ':' . (int) $r['visitor_key'];
            $at  = strtotime((string) $r['occurred_at']);

            if ($key !== $lastKey || $at - (int) $lastAt > self::SESSION_GAP_MIN * 60) {
                $out[$cid] = ($out[$cid] ?? 0) + 1;
            }

            $lastKey = $key;
            $lastAt  = $at;
        }

        return $out;
    }

    /** @return array<int,array{orders:int,revenue:int}> visitor_key => totals */
    private static function ordersByVisitor(int $tenantId, string $from, string $to): array
    {
        $stmt = Db::core()->prepare(
            'SELECT visitor_key, COUNT(*) AS orders, COALESCE(SUM(total_minor), 0) AS revenue
               FROM orders
              WHERE tenant_id = :tenant_id
                AND created_at >= :from AND created_at < :to
                AND cancelled_at IS NULL
                AND visitor_key IS NOT NULL
              GROUP BY visitor_key'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':from' => $from, ':to' => $to]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['visitor_key']] = [
                'orders'  => (int) $r['orders'],
                'revenue' => (int) $r['revenue'],
            ];
        }

        return $out;
    }

    /**
     * Roll up one event-side dimension, attributing orders through the
     * visitor who placed them.
     *
     */
    private static function visitorDimension(
        int $t,
        string $date,
        string $from,
        string $to,
        int $prov,
        string $column,
        string $table
    ): int {
        $rows = self::shardAll(
            $t,
            "SELECT {$column} AS dim, visitor_key
               FROM events
              WHERE tenant_id = :tenant_id AND occurred_at >= :from AND occurred_at < :to
                AND {$column} IS NOT NULL",
            $from,
            $to
        );

        $dimByVisitor  = [];
        $visitorsByDim = [];
        foreach ($rows as $r) {
            $dim = (int) $r['dim'];
            $v   = (int) $r['visitor_key'];

            $dimByVisitor[$v]       = $dim;
            $visitorsByDim[$dim][$v] = true;
        }

        $agg = [];
        foreach ($visitorsByDim as $dim => $visitors) {
            $agg[$dim] = ['visitors' => count($visitors), 'orders' => 0, 'revenue' => 0];
        }

        foreach (self::ordersByVisitor($t, $from, $to) as $v => $o) {
            $dim = $dimByVisitor[$v] ?? null;
            if ($dim === null) {
                continue;
            }
            $agg[$dim]['orders']  += $o['orders'];
            $agg[$dim]['revenue'] += $o['revenue'];
        }

        $ins = Db::core()->prepare(
            "INSERT INTO {$table}
                (tenant_id, stat_date, {$column}, visitors, orders, revenue_minor,
                 is_provisional, computed_at)
             VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                visitors = VALUES(visitors), orders = VALUES(orders),
                revenue_minor = VALUES(revenue_minor),
                is_provisional = VALUES(is_provisional), computed_at = UTC_TIMESTAMP()"
        );

        foreach ($agg as $dim => $a) {
            $ins->execute([$t, $date, $dim, $a['visitors'], $a['orders'], $a['revenue'], $prov]);
        }

        return count($agg);
    }

    /**
     * The UTC window covering one of the merchant's local days.
     *
     * @return array{0:string,1:string}
     */
    private static function utcRange(int $tenantId, string $localDate): array
    {
        $tz  = self::timezone($tenantId);
        $utc = new DateTimeZone('UTC');

        $start = new DateTimeImmutable($localDate . ' 00:00:00', $tz);
        $end   = $start->modify('+1 day');

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    private static function timezone(int $tenantId): DateTimeZone
    {
        $name = (string) (Tenant::find($tenantId)['iana_timezone'] ?? 'UTC');

        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            // A bad timezone must not stop the rollup; UTC is wrong by hours,
            // a missing rollup is wrong by everything.
            return new DateTimeZone('UTC');
        }
    }

    private static function today(int $tenantId): string
    {
        return (new DateTimeImmutable('now', self::timezone($tenantId)))->format('Y-m-d');
    }

    private static function isProvisional(int $tenantId, string $localDate): bool
    {
        $tz    = self::timezone($tenantId);
        $day   = new DateTimeImmutable($localDate, $tz);
        $close = (new DateTimeImmutable('now', $tz))->modify('-' . self::RECLOSE_DAYS . ' days');

        return $day > $close;
    }

    private static function firstDataDay(int $tenantId, DateTimeZone $tz): DateTimeImmutable
    {
        $stmt = Db::core()->prepare(
            'SELECT installed_at FROM tenants WHERE tenant_id = ?'
        );
        $stmt->execute([$tenantId]);
        $at = $stmt->fetchColumn();

        // Browsing precedes buying, so the first order is not necessarily the
        // first day with data — but it is a floor we can trust, and install
        // day is the other. Whichever is earlier starts the backfill.
        $stmt = Db::core()->prepare(
            'SELECT MIN(created_at) FROM orders WHERE tenant_id = ?'
        );
        $stmt->execute([$tenantId]);
        $firstOrder = $stmt->fetchColumn();

        if ($firstOrder !== false && $firstOrder !== null && (string) $firstOrder < (string) $at) {
            $at = $firstOrder;
        }

        $utc = new DateTimeImmutable(
            $at !== false && $at !== null ? (string) $at : 'now',
            new DateTimeZone('UTC')
        );

        return new DateTimeImmutable($utc->setTimezone($tz)->format('Y-m-d'), $tz);
    }

    /**
     * Run a query against every shard the window touches and merge the rows.
     *
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private static function shardAll(int $tenantId, string $sql, string $from, string $to, array $params = []): array
    {
        $out = [];

        foreach (Shard::forRange(substr($from, 0, 10), substr($to, 0, 10)) as $shard) {
            try {
                $pdo = Shard::connectionForDate((string) $shard['date_from']);
            } catch (Throwable) {
                continue;
            }

            $stmt = Db::tenantQuery(
                $pdo,
                $sql,
                $tenantId,
                $params + [':from' => $from, ':to' => $to]
            );

            foreach ($stmt->fetchAll() as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * A single aggregate row. A window crossing a year boundary produces one
     * row per shard, so the columns are summed.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function shardRow(int $tenantId, string $sql, string $from, string $to, array $params = []): array
    {
        $rows = self::shardAll($tenantId, $sql, $from, $to, $params);

        if ($rows === []) {
            return [];
        }
        if (count($rows) === 1) {
            return $rows[0];
        }

        $merged = [];
        foreach ($rows as $row) {
            foreach ($row as $k => $v) {
                $merged[$k] = ($merged[$k] ?? 0) + (int) $v;
            }
        }

        return $merged;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private static function pick(array $rows, string $column, int $value): array
    {
        foreach ($rows as $row) {
            if ((int) $row[$column] === $value) {
                return $row;
            }
        }

        return [];
    }
}
