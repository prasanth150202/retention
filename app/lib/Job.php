<?php
/**
 * Cron job bookkeeping.
 *
 * Every scheduled entry point opens a job_runs row and closes it. That table
 * is what health_check reads to answer "has the importer stopped?" — silence
 * is otherwise indistinguishable from success, and on this platform a job can
 * simply stop being invoked without anything raising an error.
 *
 * Also provides the run-lock. Cron will start a second copy of a job while the
 * first is still going, and two importers reading the same spool file would
 * double-insert everything the dedup key does not catch.
 */

declare(strict_types=1);

final class Job
{
    /** @var array<string,resource> held for the life of the process */
    private static array $locks = [];

    /**
     * Take an exclusive lock for a job name.
     *
     * Returns false when another copy holds it — the caller should exit
     * quietly, not error. Overlapping cron runs are expected, not a fault.
     */
    public static function lock(string $name): bool
    {
        $dir = Config::get('paths.locks');

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create lock directory: {$dir}");
        }

        $fh = @fopen($dir . '/' . $name . '.lock', 'c');
        if ($fh === false) {
            throw new RuntimeException("Cannot open lock file for {$name}");
        }

        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return false;
        }

        self::$locks[$name] = $fh;
        return true;
    }

    public static function unlock(string $name): void
    {
        if (isset(self::$locks[$name])) {
            flock(self::$locks[$name], LOCK_UN);
            fclose(self::$locks[$name]);
            unset(self::$locks[$name]);
        }
    }

    public static function start(string $name, ?int $tenantId = null): int
    {
        $pdo = Db::core();
        $pdo->prepare(
            "INSERT INTO job_runs (job_name, tenant_id, started_at, status)
             VALUES (?, ?, UTC_TIMESTAMP(), 'running')"
        )->execute([$name, $tenantId]);

        return (int) $pdo->lastInsertId();
    }

    public static function finish(int $id, string $status, int $in = 0, int $out = 0, ?string $message = null): void
    {
        Db::core()->prepare(
            'UPDATE job_runs
                SET finished_at = UTC_TIMESTAMP(), status = ?, rows_in = ?, rows_out = ?, message = ?
              WHERE job_run_id = ?'
        )->execute([
            $status,
            $in,
            $out,
            $message !== null ? substr($message, 0, 2000) : null,
            $id,
        ]);
    }

    /** Most recent successful run of a job, or null if it has never succeeded. */
    public static function lastSuccess(string $name): ?string
    {
        $stmt = Db::core()->prepare(
            "SELECT MAX(finished_at) FROM job_runs WHERE job_name = ? AND status = 'ok'"
        );
        $stmt->execute([$name]);
        $v = $stmt->fetchColumn();

        return $v === false || $v === null ? null : (string) $v;
    }
}
