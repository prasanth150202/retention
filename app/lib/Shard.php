<?php
/**
 * Routes a date to the event database that holds it.
 *
 * Raw events are split across one database per calendar year because
 * Hostinger caps each at 3 GB. shard_registry (in core) is the authoritative
 * map from date range to physical database name; the DB_SHARD pattern is only
 * used to propose a name for a year not yet registered.
 *
 * Routing failures are loud on purpose. An event that cannot be routed must
 * stop the import so its spool file is retried, never be dropped - losing
 * pixel data is unrecoverable, because unlike orders it cannot be backfilled.
 */

declare(strict_types=1);

final class Shard
{
    private static ?array $registry = null;

    /**
     * All provisioned shards, newest first.
     *
     * @return array<int,array{shard_name:string,physical_name:string,date_from:string,date_to:string,is_writable:int}>
     */
    public static function all(bool $refresh = false): array
    {
        if (self::$registry !== null && !$refresh) {
            return self::$registry;
        }

        $stmt = Db::core()->query(
            'SELECT shard_name, physical_name, date_from, date_to, is_writable,
                    bytes_used, bytes_limit, bytes_checked_at
             FROM shard_registry
             WHERE is_provisioned = 1
             ORDER BY date_from DESC'
        );

        return self::$registry = $stmt->fetchAll();
    }

    /**
     * The shard covering a given date.
     *
     * @param string|DateTimeInterface $date
     * @return array{shard_name:string,physical_name:string,date_from:string,date_to:string,is_writable:int}
     */
    public static function forDate(string|DateTimeInterface $date): array
    {
        $day = $date instanceof DateTimeInterface
            ? $date->format('Y-m-d')
            : substr($date, 0, 10);

        foreach (self::all() as $shard) {
            if ($day >= $shard['date_from'] && $day <= $shard['date_to']) {
                return $shard;
            }
        }

        $year = (int) substr($day, 0, 4);
        [$proposed] = Db::shardTarget($year);

        throw new RuntimeException(
            "No event database is registered for {$day}.\n"
            . "Expected something like '{$proposed}' covering {$year}.\n\n"
            . "Create the database in hPanel, add its credentials to .env as\n"
            . "DB_SHARD_{$year}_USER / DB_SHARD_{$year}_PASS, then run:\n"
            . "  php bin/migrate.php\n\n"
            . "Ingest is halted rather than discarding events - spool files are "
            . "retained and will be retried once the database exists."
        );
    }

    /** Connection to the shard covering a date. */
    public static function connectionForDate(string|DateTimeInterface $date): PDO
    {
        $shard = self::forDate($date);
        $year  = (int) substr($shard['date_from'], 0, 4);
        [, $user, $pass] = Db::shardTarget($year);

        return Db::connect($shard['physical_name'], $user, $pass);
    }

    /** The shard that new events land in right now. */
    public static function current(): array
    {
        return self::forDate(gmdate('Y-m-d'));
    }

    /**
     * Shards covering a date range, oldest first.
     *
     * A range spanning a year boundary touches two databases. There is no
     * JOIN across them: each is queried separately and the results merged by
     * the caller.
     *
     * @return array<int,array>
     */
    public static function forRange(string $from, string $to): array
    {
        $from = substr($from, 0, 10);
        $to   = substr($to, 0, 10);

        $hits = array_filter(
            self::all(),
            static fn(array $s): bool => $s['date_from'] <= $to && $s['date_to'] >= $from
        );

        usort($hits, static fn($a, $b) => $a['date_from'] <=> $b['date_from']);

        return array_values($hits);
    }

    /**
     * Measure a shard's real size and record it.
     *
     * Called by health_check. The 3 GB ceiling is enforced by the host's
     * quota, not by MySQL, so it cannot be read from a variable - it has to
     * be measured from information_schema.
     *
     * @return array{bytes:int,limit:int,ratio:float,over_warn:bool}
     */
    public static function measure(array $shard): array
    {
        $year = (int) substr($shard['date_from'], 0, 4);
        [, $user, $pass] = Db::shardTarget($year);
        $pdo = Db::connect($shard['physical_name'], $user, $pass);

        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS bytes
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db'
        );
        $stmt->execute([':db' => $shard['physical_name']]);
        $bytes = (int) $stmt->fetchColumn();

        $limit = (int) ($shard['bytes_limit'] ?: Config::get('storage.shard_bytes_limit', 3221225472));
        $ratio = $limit > 0 ? $bytes / $limit : 0.0;

        $upd = Db::core()->prepare(
            'UPDATE shard_registry
                SET bytes_used = :bytes, bytes_checked_at = UTC_TIMESTAMP()
              WHERE shard_name = :name'
        );
        $upd->execute([':bytes' => $bytes, ':name' => $shard['shard_name']]);

        return [
            'bytes'     => $bytes,
            'limit'     => $limit,
            'ratio'     => $ratio,
            'over_warn' => $ratio >= (float) Config::get('storage.shard_warn_ratio', 0.80),
        ];
    }

    public static function reset(): void
    {
        self::$registry = null;
    }
}
