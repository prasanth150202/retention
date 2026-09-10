<?php
/**
 * Database connections.
 *
 * The core database and each yearly event shard are SEPARATE databases with
 * separate credentials, because Hostinger issues one credential per database.
 * This class owns the pool and the credential resolution.
 *
 * No query ever spans two databases. Event rows store only integer ids, and
 * the strings those ids refer to live in core, so each database is read on
 * its own connection and results are combined in PHP. That is what makes the
 * one-credential-per-database limit harmless. See TECHNICAL_PLAN.md 6.1.
 *
 * Multi-tenancy is enforced here, in code, because MySQL has no row-level
 * security. tenantQuery() is the guarded path and should be used for every
 * read of a tenant-scoped table.
 */

declare(strict_types=1);

final class Db
{
    /** @var array<string,PDO> keyed by physical database name */
    private static array $pool = [];

    /**
     * Tables carrying a tenant_id that must always be filtered.
     *
     * Dimension tables are included: dim_path and friends are per-tenant, and
     * leaking one client's URL paths or search terms into another's dashboard
     * would be a real breach, not a cosmetic bug.
     */
    private const TENANT_SCOPED = [
        'events', 'orders', 'order_line_items', 'order_attribution',
        'order_journey_moments', 'customers', 'abandoned_checkouts',
        'abandoned_checkout_items', 'products', 'product_variants',
        'persons', 'identity_keys', 'person_merges', 'channel_rules',
        'sync_cursors', 'import_log',
        'dim_visitor', 'dim_path', 'dim_referrer', 'dim_campaign',
        'dim_useragent', 'dim_search_term', 'dim_click_target',
        'rollup_daily_kpi', 'rollup_daily_funnel', 'rollup_daily_campaign',
        'rollup_daily_channel', 'rollup_daily_landing', 'rollup_daily_product',
        'rollup_daily_geo', 'rollup_daily_device', 'rollup_daily_abandon',
        'rollup_cohort_repeat', 'rollup_campaign_cohort', 'rollup_person_orders',
    ];

    // -----------------------------------------------------------------
    // Connections
    // -----------------------------------------------------------------

    public static function core(): PDO
    {
        return self::connect(
            Config::require('db.core'),
            Config::require('db.user'),
            (string) Config::get('db.pass', '')
        );
    }

    /**
     * Connection to the shard holding a given year.
     *
     * Prefer Shard::forDate() in application code - it consults
     * shard_registry, which is authoritative once a shard is provisioned.
     */
    public static function shard(int $year): PDO
    {
        [$name, $user, $pass] = self::shardTarget($year);
        return self::connect($name, $user, $pass);
    }

    /**
     * Resolve the database name and credentials for a shard year.
     *
     * A year with no DB_SHARD_<YEAR>_* block falls back to the core
     * credentials, which covers hosts that allow one user across several
     * databases.
     *
     * @return array{0:string,1:string,2:string}
     */
    public static function shardTarget(int $year): array
    {
        $overrides = Config::get('db.shard_overrides', []);
        $o         = $overrides[$year] ?? [];

        $name = $o['name']
            ?? str_replace('{year}', (string) $year, Config::require('db.shard'));

        return [
            $name,
            $o['user'] ?? Config::require('db.user'),
            $o['pass'] ?? (string) Config::get('db.pass', ''),
        ];
    }

    public static function connect(string $database, string $user, string $pass): PDO
    {
        if (isset(self::$pool[$database])) {
            return self::$pool[$database];
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            Config::get('db.host', 'localhost'),
            (int) Config::get('db.port', 3306),
            $database,
            Config::get('db.charset', 'utf8mb4')
        );

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // Never let the password reach a log or an error page.
            throw new RuntimeException(
                "Cannot connect to database '{$database}' as '{$user}': " . $e->getMessage(),
                (int) $e->getCode()
            );
        }

        // UTC everywhere. Per-tenant display timezone is applied at render
        // time, never in storage, so a store changing timezone cannot
        // retroactively move events between days.
        //
        // STRICT MODE IS NOT OPTIONAL AND IS NOT ASSUMED.
        //
        // The production server runs without it. Without strict mode MySQL
        // does not reject a value that will not fit — it coerces it and
        // carries on: an order total above the column maximum is silently
        // clamped to that maximum, an over-long string is truncated, an
        // impossible date becomes zeroes. Every one of those writes a wrong
        // number with no error anywhere, which is the single worst failure
        // this application can have, because every figure downstream is then
        // confidently incorrect.
        //
        // NO_ZERO_DATE and NO_ZERO_IN_DATE are listed explicitly. STRICT on its
        // own rejects an impossible date such as 2026-02-30, but still accepts
        // a literal 0000-00-00 — which then reads as a real timestamp
        // everywhere downstream and sorts before every genuine row.
        //
        // ONLY_FULL_GROUP_BY is deliberately absent. The campaign and product
        // reports select a dimension's name alongside an aggregate grouped by
        // that dimension's id: functionally dependent, but not in a form MySQL
        // can prove. Assigning the whole mode rather than appending to whatever
        // the host set is what makes that a stated decision rather than a
        // silent dependency on the server happening to leave it off.
        //
        // Set per connection rather than relied upon from the server config,
        // so it holds on any host regardless of how that host is tuned.
        $pdo->exec(
            "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,"
            . "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',"
            . " time_zone = '+00:00'"
        );

        return self::$pool[$database] = $pdo;
    }

    // -----------------------------------------------------------------
    // Guarded queries
    // -----------------------------------------------------------------

    /**
     * Run a SELECT against tenant-scoped tables with the tenant bound.
     *
     * Refuses to execute if the statement touches a tenant-scoped table
     * without constraining tenant_id. This is a heuristic - it inspects the
     * SQL text - but it catches the mistake that actually happens, which is
     * omitting the WHERE clause rather than crafting a devious query.
     *
     * @param array<string,mixed> $params
     */
    public static function tenantQuery(PDO $pdo, string $sql, int $tenantId, array $params = []): PDOStatement
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException(
                'tenantQuery() requires a positive tenant id; got ' . var_export($tenantId, true)
            );
        }

        self::assertTenantScoped($sql);

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);

        foreach ($params as $key => $value) {
            $name = str_starts_with((string) $key, ':') ? (string) $key : ':' . $key;
            if ($name === ':tenant_id') {
                throw new InvalidArgumentException(
                    'Do not pass tenant_id in $params; it is bound from the $tenantId argument.'
                );
            }
            $stmt->bindValue($name, $value, self::pdoType($value));
        }

        $stmt->execute();
        return $stmt;
    }

    /**
     * Throws when SQL reads a tenant-scoped table without filtering tenant_id.
     *
     * Public so tests and one-off scripts can check a query without running it.
     */
    public static function assertTenantScoped(string $sql): void
    {
        $normalised = strtolower($sql);

        // Table names appearing after FROM or JOIN.
        preg_match_all('/\b(?:from|join)\s+`?([a-z_][a-z0-9_]*)`?/i', $normalised, $m);
        $touched = array_intersect($m[1] ?? [], self::TENANT_SCOPED);

        if ($touched === []) {
            return;
        }

        if (!str_contains($normalised, ':tenant_id')) {
            throw new RuntimeException(
                "Refusing to run a query against tenant-scoped table(s) ["
                . implode(', ', array_unique($touched))
                . "] without a :tenant_id placeholder.\n"
                . "MySQL has no row-level security here, so this check is the only "
                . "thing standing between two clients' data.\n"
                . "Query: " . trim(preg_replace('/\s+/', ' ', $sql) ?? '')
            );
        }
    }

    private static function pdoType(mixed $value): int
    {
        return match (true) {
            is_int($value)  => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            is_null($value) => PDO::PARAM_NULL,
            default         => PDO::PARAM_STR,
        };
    }

    /** Closes pooled connections. Useful in long-running cron loops. */
    public static function disconnect(): void
    {
        self::$pool = [];
    }
}
