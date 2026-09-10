<?php
/**
 * Report — what the dashboard reads.
 *
 * Every method here reads rollup_* tables (plus the dim_* tables that turn an
 * interned id back into a name). Nothing touches raw events, which is what
 * keeps a page load fast once a store has years of history, and is the seam
 * that lets event storage change later without touching the UI.
 *
 * NUMBERS THAT ARE NOT KNOWN ARE RETURNED AS NULL, NOT ZERO.
 * A conversion rate with no visitors is not 0% — it is unknown, and showing
 * 0% invites a merchant to go looking for a problem that does not exist. The
 * views render null as a dash.
 *
 * PROVISIONAL DATA IS FLAGGED, NOT HIDDEN.
 * Recent days are still settling. Every range says whether it contains any,
 * so the page can say so rather than quietly changing its numbers overnight.
 */

declare(strict_types=1);

final class Report
{
    /** How many days a range covers by default. */
    public const DEFAULT_DAYS = 30;

    /** The attribution model the UI uses unless asked otherwise. */
    public const DEFAULT_MODEL = 'pixel_last';

    public const MODELS = ['pixel_first', 'pixel_last', 'shopify_first', 'shopify_last'];

    /**
     * Resolve a requested date range against the store's own calendar.
     *
     * @return array{from:string,to:string,days:int,label:string,prev_from:string,prev_to:string}
     */
    public static function range(int $tenantId, ?string $from, ?string $to): array
    {
        $tz    = self::timezone($tenantId);
        $today = new DateTimeImmutable('now', $tz);

        $end   = self::parseDate($to, $tz) ?? $today;
        $start = self::parseDate($from, $tz) ?? $end->modify('-' . (self::DEFAULT_DAYS - 1) . ' days');

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        // A range longer than a couple of years is almost always a typo, and
        // it is the one input that can make a rollup query slow.
        if ($start < $end->modify('-800 days')) {
            $start = $end->modify('-800 days');
        }

        $days = (int) $start->diff($end)->days + 1;

        return [
            'from'      => $start->format('Y-m-d'),
            'to'        => $end->format('Y-m-d'),
            'days'      => $days,
            'label'     => self::label($start, $end, $today, $days),
            // The immediately preceding stretch of equal length, for
            // "vs previous period".
            'prev_from' => $start->modify('-' . $days . ' days')->format('Y-m-d'),
            'prev_to'   => $start->modify('-1 day')->format('Y-m-d'),
        ];
    }

    /**
     * Headline numbers, with the same numbers for the previous period.
     *
     * @return array<string,mixed>
     */
    public static function summary(int $tenantId, array $range): array
    {
        $now  = self::kpiTotals($tenantId, $range['from'], $range['to']);
        $prev = self::kpiTotals($tenantId, $range['prev_from'], $range['prev_to']);

        $metrics = [];
        foreach ([
            'visitors', 'sessions', 'pageviews', 'orders', 'units',
            'revenue_minor', 'refunded_minor', 'new_customers', 'repeat_customers',
        ] as $k) {
            $metrics[$k] = [
                'value'  => $now[$k],
                'change' => self::change($now[$k], $prev[$k]),
            ];
        }

        // Derived figures, each undefined rather than zero when its
        // denominator is missing.
        $metrics['aov_minor'] = [
            'value'  => self::divide($now['revenue_minor'], $now['orders']),
            'change' => self::change(
                self::divide($now['revenue_minor'], $now['orders']),
                self::divide($prev['revenue_minor'], $prev['orders'])
            ),
        ];
        $metrics['conversion_pct'] = [
            'value'  => self::percent($now['purchasers'], $now['visitors']),
            'change' => self::change(
                self::percent($now['purchasers'], $now['visitors']),
                self::percent($prev['purchasers'], $prev['visitors'])
            ),
        ];
        $metrics['repeat_pct'] = [
            'value'  => self::percent(
                $now['repeat_customers'],
                $now['new_customers'] + $now['repeat_customers']
            ),
            'change' => self::change(
                self::percent($now['repeat_customers'], $now['new_customers'] + $now['repeat_customers']),
                self::percent($prev['repeat_customers'], $prev['new_customers'] + $prev['repeat_customers'])
            ),
        ];

        $metrics['provisional'] = $now['provisional'];
        $metrics['has_data']    = $now['days'] > 0;

        return $metrics;
    }

    /**
     * Daily series, for a sparkline or a table.
     *
     * Every day in the range appears, including days with nothing. A chart
     * that skips empty days compresses a quiet week into a point and makes a
     * fall in traffic look like a flat line.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function trend(int $tenantId, array $range): array
    {
        $stmt = Db::core()->prepare(
            'SELECT stat_date, visitors, sessions, orders, revenue_minor,
                    new_customers, repeat_customers, is_provisional
               FROM rollup_daily_kpi
              WHERE tenant_id = :tenant_id AND stat_date BETWEEN :from AND :to
              ORDER BY stat_date'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':from' => $range['from'], ':to' => $range['to']]);

        $byDate = [];
        foreach ($stmt->fetchAll() as $r) {
            $byDate[(string) $r['stat_date']] = $r;
        }

        $out = [];
        $day = new DateTimeImmutable($range['from']);
        $end = new DateTimeImmutable($range['to']);

        while ($day <= $end) {
            $d = $day->format('Y-m-d');
            $r = $byDate[$d] ?? null;

            $out[] = [
                'date'             => $d,
                'visitors'         => (int) ($r['visitors'] ?? 0),
                'sessions'         => (int) ($r['sessions'] ?? 0),
                'orders'           => (int) ($r['orders'] ?? 0),
                'revenue_minor'    => (int) ($r['revenue_minor'] ?? 0),
                'new_customers'    => (int) ($r['new_customers'] ?? 0),
                'repeat_customers' => (int) ($r['repeat_customers'] ?? 0),
                'provisional'      => (bool) ($r['is_provisional'] ?? false),
                'missing'          => $r === null,
            ];

            $day = $day->modify('+1 day');
        }

        return $out;
    }

    /**
     * The funnel, both ways.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function funnel(int $tenantId, array $range): array
    {
        $stmt = Db::core()->prepare(
            'SELECT step,
                    SUM(reached_visitors) AS reached,
                    SUM(strict_visitors)  AS strict,
                    SUM(events)           AS events
               FROM rollup_daily_funnel
              WHERE tenant_id = :tenant_id AND stat_date BETWEEN :from AND :to
              GROUP BY step ORDER BY step'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':from' => $range['from'], ':to' => $range['to']]);

        $names = [
            1 => 'Visited the store',
            2 => 'Viewed a product',
            3 => 'Added to cart',
            4 => 'Started checkout',
            5 => 'Entered payment details',
            6 => 'Completed the order',
        ];

        $rows  = [];
        $first = null;
        $prev  = null;

        foreach ($stmt->fetchAll() as $r) {
            $step    = (int) $r['step'];
            $reached = (int) $r['reached'];
            $strict  = (int) $r['strict'];

            $first ??= $reached;

            $rows[] = [
                'step'      => $step,
                'name'      => $names[$step] ?? "Step {$step}",
                'reached'   => $reached,
                'strict'    => $strict,
                'events'    => (int) $r['events'],
                // Share of everyone who entered the funnel at all...
                'of_first'  => self::percent($reached, $first),
                // ...and share of the step immediately before, which is where
                // a drop-off actually shows up.
                'of_prev'   => $prev === null ? null : self::percent($reached, $prev),
                'lost'      => $prev === null ? null : max(0, $prev - $reached),
            ];

            $prev = $reached;
        }

        return $rows;
    }

    /**
     * Campaigns, under one attribution model.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function campaigns(int $tenantId, array $range, string $model, int $limit = 100): array
    {
        $model = self::model($model);

        $stmt = Db::core()->prepare(
            'SELECT r.campaign_id,
                    SUM(r.visitors)      AS visitors,
                    SUM(r.sessions)      AS sessions,
                    SUM(r.product_views) AS product_views,
                    SUM(r.atc)           AS atc,
                    SUM(r.orders)        AS orders,
                    SUM(r.revenue_minor) AS revenue_minor,
                    SUM(r.new_customers) AS new_customers,
                    SUM(r.returning_customers) AS returning_customers,
                    c.utm_source, c.utm_medium, c.utm_campaign, c.utm_content, c.utm_term,
                    c.has_gclid, c.has_fbclid
               FROM rollup_daily_campaign r
               LEFT JOIN dim_campaign c
                      ON c.tenant_id = r.tenant_id AND c.campaign_id = r.campaign_id
              WHERE r.tenant_id = :tenant_id
                AND r.model = :model
                AND r.stat_date BETWEEN :from AND :to
              GROUP BY r.campaign_id
              ORDER BY revenue_minor DESC, visitors DESC
              LIMIT ' . (int) $limit
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':model'     => $model,
            ':from'      => $range['from'],
            ':to'        => $range['to'],
        ]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = $r + [
                'name'          => self::campaignName($r),
                'aov_minor'     => self::divide((int) $r['revenue_minor'], (int) $r['orders']),
                'conversion'    => self::percent((int) $r['orders'], (int) $r['visitors']),
                'atc_rate'      => self::percent((int) $r['atc'], (int) $r['visitors']),
            ];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public static function channels(int $tenantId, array $range, string $model): array
    {
        $model = self::model($model);

        $stmt = Db::core()->prepare(
            'SELECT channel,
                    SUM(visitors)      AS visitors,
                    SUM(orders)        AS orders,
                    SUM(revenue_minor) AS revenue_minor
               FROM rollup_daily_channel
              WHERE tenant_id = :tenant_id
                AND model = :model
                AND stat_date BETWEEN :from AND :to
              GROUP BY channel
              ORDER BY revenue_minor DESC, visitors DESC'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':model'     => $model,
            ':from'      => $range['from'],
            ':to'        => $range['to'],
        ]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = $r + [
                'aov_minor'  => self::divide((int) $r['revenue_minor'], (int) $r['orders']),
                'conversion' => self::percent((int) $r['orders'], (int) $r['visitors']),
            ];
        }

        return $out;
    }

    /**
     * How far the models disagree over a range.
     *
     * Belongs on the Campaigns tab, not buried: a merchant comparing this
     * dashboard with Meta's will see different numbers, and the useful answer
     * is "here is how much of your traffic we cannot see", not silence.
     *
     * @return array<string,mixed>
     */
    public static function modelComparison(int $tenantId, array $range): array
    {
        $stmt = Db::core()->prepare(
            'SELECT model,
                    SUM(orders)        AS orders,
                    SUM(revenue_minor) AS revenue_minor,
                    SUM(CASE WHEN channel IN ("Direct/Untracked", "Other") THEN orders ELSE 0 END) AS unattributed
               FROM rollup_daily_channel
              WHERE tenant_id = :tenant_id AND stat_date BETWEEN :from AND :to
              GROUP BY model'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':from' => $range['from'], ':to' => $range['to']]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $orders = (int) $r['orders'];
            $out[(string) $r['model']] = [
                'orders'           => $orders,
                'revenue_minor'    => (int) $r['revenue_minor'],
                'unattributed'     => (int) $r['unattributed'],
                'unattributed_pct' => self::percent((int) $r['unattributed'], $orders),
            ];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public static function products(int $tenantId, array $range, int $limit = 100): array
    {
        $stmt = Db::core()->prepare(
            'SELECT r.product_id,
                    SUM(r.views)         AS views,
                    SUM(r.viewers)       AS viewers,
                    SUM(r.atc)           AS atc,
                    SUM(r.purchases)     AS purchases,
                    SUM(r.units)         AS units,
                    SUM(r.revenue_minor) AS revenue_minor,
                    SUM(r.abandons)      AS abandons,
                    p.title, p.handle
               FROM rollup_daily_product r
               LEFT JOIN products p
                      ON p.tenant_id = r.tenant_id AND p.product_id = r.product_id
              WHERE r.tenant_id = :tenant_id AND r.stat_date BETWEEN :from AND :to
              GROUP BY r.product_id
              ORDER BY views DESC, revenue_minor DESC
              LIMIT ' . (int) $limit
        );
        $stmt->execute([':tenant_id' => $tenantId, ':from' => $range['from'], ':to' => $range['to']]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $views = (int) $r['views'];
            $atc   = (int) $r['atc'];

            $out[] = $r + [
                'name'          => $r['title'] ?? ('Product ' . $r['product_id']),
                'view_to_atc'   => self::percent($atc, $views),
                'view_to_buy'   => self::percent((int) $r['purchases'], $views),
                // Of the carts this product went into, how many were left.
                'abandon_rate'  => self::percent((int) $r['abandons'], $atc),
            ];
        }

        return $out;
    }

    /**
     * The checkout micro-funnel.
     *
     * @return array{stages:array<int,array<string,mixed>>,shopify_records:int,value_minor:int,rate:?float}
     */
    public static function abandonment(int $tenantId, array $range): array
    {
        $stmt = Db::core()->prepare(
            'SELECT stage,
                    SUM(entered)         AS entered,
                    SUM(advanced)        AS advanced,
                    SUM(abandoned)       AS abandoned,
                    SUM(value_minor)     AS value_minor,
                    SUM(shopify_records) AS shopify_records
               FROM rollup_daily_abandon
              WHERE tenant_id = :tenant_id AND stat_date BETWEEN :from AND :to
              GROUP BY stage ORDER BY stage'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':from' => $range['from'], ':to' => $range['to']]);

        $names = [
            2 => 'Started checkout',
            3 => 'Entered contact details',
            4 => 'Entered address',
            5 => 'Chose shipping',
            6 => 'Entered payment details',
            7 => 'Completed the order',
        ];

        $stages   = [];
        $started  = 0;
        $finished = 0;
        $records  = 0;
        $value    = 0;

        foreach ($stmt->fetchAll() as $r) {
            $stage   = (int) $r['stage'];
            $entered = (int) $r['entered'];

            if ($stage === 2) {
                $started = $entered;
            }
            if ($stage === 7) {
                $finished = $entered;
            }

            $records += (int) $r['shopify_records'];
            $value   += (int) $r['value_minor'];

            $stages[] = [
                'stage'     => $stage,
                'name'      => $names[$stage] ?? "Stage {$stage}",
                'entered'   => $entered,
                'advanced'  => (int) $r['advanced'],
                'abandoned' => (int) $r['abandoned'],
                'drop_pct'  => self::percent((int) $r['abandoned'], $entered),
            ];
        }

        return [
            'stages'          => $stages,
            'shopify_records' => $records,
            'value_minor'     => $value,
            // The headline: of everyone who began a checkout, how many left.
            'rate'            => self::percent(max(0, $started - $finished), $started),
            'started'         => $started,
            'completed'       => $finished,
        ];
    }

    /**
     * Retention: repeat cohorts and how many times people have bought.
     *
     * @return array{cohorts:array<int,array<string,mixed>>,buckets:array<int,int>,buckets_total:int}
     */
    public static function retention(int $tenantId, int $months = 12): array
    {
        $stmt = Db::core()->prepare(
            'SELECT cohort_month, days_bucket, cohort_size, reordered
               FROM rollup_cohort_repeat
              WHERE tenant_id = :tenant_id
              ORDER BY cohort_month DESC, days_bucket'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        $byMonth = [];
        foreach ($stmt->fetchAll() as $r) {
            $m = (string) $r['cohort_month'];

            $byMonth[$m]['month']       = $m;
            $byMonth[$m]['size']        = (int) $r['cohort_size'];
            $byMonth[$m]['buckets'][(int) $r['days_bucket']] = [
                'reordered' => (int) $r['reordered'],
                'pct'       => self::percent((int) $r['reordered'], (int) $r['cohort_size']),
            ];
        }

        $cohorts = array_slice(array_values($byMonth), 0, $months);

        $stmt = Db::core()->prepare(
            'SELECT order_count_bucket, persons FROM rollup_person_orders
              WHERE tenant_id = :tenant_id
                AND as_of_date = (SELECT MAX(as_of_date) FROM rollup_person_orders WHERE tenant_id = :t2)
              ORDER BY order_count_bucket'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':t2' => $tenantId]);

        $buckets = [];
        $total   = 0;
        foreach ($stmt->fetchAll() as $r) {
            $buckets[(int) $r['order_count_bucket']] = (int) $r['persons'];
            $total += (int) $r['persons'];
        }

        return ['cohorts' => $cohorts, 'buckets' => $buckets, 'buckets_total' => $total];
    }

    /**
     * Which campaigns bring buyers who come back.
     *
     * Worth more than any single-day figure: a campaign with a good cost per
     * first order and a poor 90-day repeat rate is losing money slowly, and
     * nothing on a daily dashboard shows it.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function campaignRetention(int $tenantId, int $bucket = 90, int $limit = 25): array
    {
        $stmt = Db::core()->prepare(
            'SELECT r.campaign_id,
                    SUM(r.cohort_size) AS cohort_size,
                    SUM(r.reordered)   AS reordered,
                    c.utm_source, c.utm_medium, c.utm_campaign, c.utm_content, c.utm_term,
                    c.has_gclid, c.has_fbclid
               FROM rollup_campaign_cohort r
               LEFT JOIN dim_campaign c
                      ON c.tenant_id = r.tenant_id AND c.campaign_id = r.campaign_id
              WHERE r.tenant_id = :tenant_id AND r.days_bucket = :bucket
              GROUP BY r.campaign_id
              HAVING cohort_size > 0
              ORDER BY cohort_size DESC
              LIMIT ' . (int) $limit
        );
        $stmt->execute([':tenant_id' => $tenantId, ':bucket' => $bucket]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = $r + [
                'name'       => self::campaignName($r),
                'repeat_pct' => self::percent((int) $r['reordered'], (int) $r['cohort_size']),
            ];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public static function geography(int $tenantId, array $range, int $limit = 60): array
    {
        $stmt = Db::core()->prepare(
            'SELECT r.geo_id,
                    SUM(r.visitors)      AS visitors,
                    SUM(r.orders)        AS orders,
                    SUM(r.revenue_minor) AS revenue_minor,
                    g.country, g.region, g.city
               FROM rollup_daily_geo r
               LEFT JOIN dim_geo g ON g.geo_id = r.geo_id
              WHERE r.tenant_id = :tenant_id AND r.stat_date BETWEEN :from AND :to
              GROUP BY r.geo_id
              ORDER BY revenue_minor DESC, visitors DESC
              LIMIT ' . (int) $limit
        );
        $stmt->execute([':tenant_id' => $tenantId, ':from' => $range['from'], ':to' => $range['to']]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $place = array_filter([$r['city'] ?? null, $r['region'] ?? null, $r['country'] ?? null]);

            $out[] = $r + [
                'name'       => $place === [] ? 'Unknown' : implode(', ', $place),
                'conversion' => self::percent((int) $r['orders'], (int) $r['visitors']),
            ];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public static function devices(int $tenantId, array $range): array
    {
        $stmt = Db::core()->prepare(
            'SELECT device_type,
                    SUM(visitors)      AS visitors,
                    SUM(orders)        AS orders,
                    SUM(revenue_minor) AS revenue_minor
               FROM rollup_daily_device
              WHERE tenant_id = :tenant_id AND stat_date BETWEEN :from AND :to
              GROUP BY device_type ORDER BY visitors DESC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':from' => $range['from'], ':to' => $range['to']]);

        $names = [0 => 'Unknown', 1 => 'Mobile', 2 => 'Desktop', 3 => 'Tablet', 4 => 'Bot'];

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = $r + [
                'name'       => $names[(int) $r['device_type']] ?? 'Unknown',
                'conversion' => self::percent((int) $r['orders'], (int) $r['visitors']),
            ];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public static function landingPages(int $tenantId, array $range, int $limit = 40): array
    {
        $stmt = Db::core()->prepare(
            'SELECT r.path_id,
                    SUM(r.visitors)      AS visitors,
                    SUM(r.orders)        AS orders,
                    SUM(r.revenue_minor) AS revenue_minor,
                    p.path, p.page_type
               FROM rollup_daily_landing r
               LEFT JOIN dim_path p
                      ON p.tenant_id = r.tenant_id AND p.path_id = r.path_id
              WHERE r.tenant_id = :tenant_id AND r.stat_date BETWEEN :from AND :to
              GROUP BY r.path_id
              ORDER BY visitors DESC
              LIMIT ' . (int) $limit
        );
        $stmt->execute([':tenant_id' => $tenantId, ':from' => $range['from'], ':to' => $range['to']]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = $r + [
                'name'       => $r['path'] ?? ('#' . $r['path_id']),
                'conversion' => self::percent((int) $r['orders'], (int) $r['visitors']),
            ];
        }

        return $out;
    }

    /**
     * Has anything been rolled up yet?
     *
     * A store installed an hour ago has no rollups, and an empty dashboard is
     * indistinguishable from a broken one unless the page says which it is.
     */
    public static function hasData(int $tenantId): bool
    {
        $stmt = Db::core()->prepare('SELECT 1 FROM rollup_daily_kpi WHERE tenant_id = ? LIMIT 1');
        $stmt->execute([$tenantId]);

        return $stmt->fetchColumn() !== false;
    }

    // -----------------------------------------------------------------

    /** @return array<string,int> */
    private static function kpiTotals(int $tenantId, string $from, string $to): array
    {
        $stmt = Db::core()->prepare(
            'SELECT COALESCE(SUM(visitors), 0)         AS visitors,
                    COALESCE(SUM(sessions), 0)         AS sessions,
                    COALESCE(SUM(pageviews), 0)        AS pageviews,
                    COALESCE(SUM(purchasers), 0)       AS purchasers,
                    COALESCE(SUM(orders), 0)           AS orders,
                    COALESCE(SUM(units), 0)            AS units,
                    COALESCE(SUM(revenue_minor), 0)    AS revenue_minor,
                    COALESCE(SUM(refunded_minor), 0)   AS refunded_minor,
                    COALESCE(SUM(new_customers), 0)    AS new_customers,
                    COALESCE(SUM(repeat_customers), 0) AS repeat_customers,
                    COALESCE(MAX(is_provisional), 0)   AS provisional,
                    COUNT(*)                           AS days
               FROM rollup_daily_kpi
              WHERE tenant_id = :tenant_id AND stat_date BETWEEN :from AND :to'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':from' => $from, ':to' => $to]);

        return array_map('intval', $stmt->fetch() ?: []);
    }

    /**
     * Percentage change, or null when there is nothing to compare against.
     *
     * Growth from zero is not "infinite" or "100%" — it is not a percentage
     * at all, and printing one there is how a dashboard earns a reputation
     * for making things up.
     */
    private static function change(?float $now, ?float $prev): ?float
    {
        if ($now === null || $prev === null || $prev == 0.0) {
            return null;
        }

        return round(($now - $prev) / abs($prev) * 100, 1);
    }

    private static function divide(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? $numerator / $denominator : null;
    }

    private static function percent(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    /** @param array<string,mixed> $r */
    private static function campaignName(array $r): string
    {
        if (empty($r['campaign_id'])) {
            return 'No campaign tag';
        }

        // Each part is clipped rather than the joined string, because the
        // identifying part is the campaign — which comes last. Truncating the
        // whole label from the right would leave every row reading
        // "instagram / social / …" and tell a merchant nothing. UTM values are
        // allowed 191 characters each and ad platforms generate long ones.
        $bits = array_filter([
            Fmt::clip($r['utm_source']   ?? null, 22),
            Fmt::clip($r['utm_medium']   ?? null, 18),
            Fmt::clip($r['utm_campaign'] ?? null, 34),
        ]);

        if ($bits !== []) {
            return implode(' / ', $bits);
        }

        // A click id with no UTM parameters at all: auto-tagging on, manual
        // tagging off. Naming it by the click id is more useful than "#4471".
        if (!empty($r['has_gclid'])) {
            return 'Google Ads (auto-tagged)';
        }
        if (!empty($r['has_fbclid'])) {
            return 'Facebook link (fbclid)';
        }

        return 'Campaign #' . $r['campaign_id'];
    }

    private static function model(string $model): string
    {
        return in_array($model, self::MODELS, true) ? $model : self::DEFAULT_MODEL;
    }

    private static function parseDate(?string $value, DateTimeZone $tz): ?DateTimeImmutable
    {
        if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, $tz);
        } catch (Throwable) {
            return null;
        }
    }

    private static function label(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        DateTimeImmutable $today,
        int $days
    ): string {
        if ($end->format('Y-m-d') === $today->format('Y-m-d')) {
            return match ($days) {
                1       => 'Today',
                7       => 'Last 7 days',
                30      => 'Last 30 days',
                90      => 'Last 90 days',
                default => "Last {$days} days",
            };
        }

        return $start->format('j M Y') . ' – ' . $end->format('j M Y');
    }

    private static function timezone(int $tenantId): DateTimeZone
    {
        $name = (string) (Tenant::find($tenantId)['iana_timezone'] ?? 'UTC');

        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}
