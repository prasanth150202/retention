<?php
/**
 * Schema migration engine.
 *
 * Extracted from bin/migrate.php so the same code drives both the CLI runner
 * and the browser-based setup page. Shared hosting often has no shell, and a
 * second implementation of "apply the schema" would eventually disagree with
 * this one about which database a year belongs to.
 *
 * Returns structured results rather than printing, so the caller decides how
 * to render them.
 *
 * Cannot create databases: shared hosting does not permit CREATE DATABASE
 * over SQL. Missing databases are reported with the exact name to create.
 */

declare(strict_types=1);

final class Migrator
{
    /** Days before year-end at which next year's shard stops being optional. */
    private const SHARD_LEAD_DAYS = 60;

    private string $migrationDir;

    public function __construct(?string $migrationDir = null)
    {
        $this->migrationDir = $migrationDir ?? dirname(__DIR__, 2) . '/db/migrations';
    }

    /**
     * @return array{
     *   ok:bool, dry_run:bool, core:array, shards:array,
     *   missing:array, warned:array, days_left_in_year:int, error:?string
     * }
     */
    public function run(bool $dryRun = false, ?int $extraYear = null): array
    {
        $result = [
            'ok'                => false,
            'dry_run'           => $dryRun,
            'core'              => [],
            'shards'            => [],
            'missing'           => [],
            'warned'            => [],
            'days_left_in_year' => 0,
            'error'             => null,
        ];

        $coreName = Config::require('db.core');

        if (!str_contains(Config::require('db.shard'), '{year}')) {
            $result['error'] = 'DB_SHARD must contain the literal {year} placeholder. Got: '
                . Config::require('db.shard');
            return $result;
        }

        try {
            $core = Db::core();
        } catch (Throwable $e) {
            $result['error'] = "Cannot connect to core database '{$coreName}': " . $e->getMessage();
            return $result;
        }

        $this->ensureMigrationTable($core);

        [$coreFiles, $shardFiles] = $this->migrationFiles();

        if ($coreFiles === [] && $shardFiles === []) {
            $result['error'] = "No migration files found in {$this->migrationDir}";
            return $result;
        }

        foreach ($coreFiles as $file) {
            $result['core'][] = $this->apply($core, $file, $coreName, $dryRun);
        }

        // -------------------------------------------------------------
        // Shards. Only the year covering today is genuinely required;
        // next year's is a note until the year is nearly over, because
        // blocking a January migration on a database not needed for
        // eleven months is pointless friction.
        // -------------------------------------------------------------
        $thisYear = (int) gmdate('Y');
        $nextYear = $thisYear + 1;

        $daysLeft = (int) ((strtotime(($thisYear + 1) . '-01-01') - time()) / 86400);
        $result['days_left_in_year'] = $daysLeft;

        $years = [$thisYear => true, $nextYear => $daysLeft <= self::SHARD_LEAD_DAYS];
        if ($extraYear !== null) {
            $years[$extraYear] = true;   // explicitly requested, so required
        }
        ksort($years);

        foreach ($years as $year => $required) {
            $target   = $this->shardTarget($year);
            $physical = $target['name'];
            $logical  = 'ev_' . $year;

            try {
                $shard = Db::connect($physical, $target['user'], $target['pass']);
            } catch (Throwable $e) {
                $entry = [
                    'year'     => $year,
                    'database' => $physical,
                    'logical'  => $logical,
                    'own_cred' => $target['own'],
                    'reason'   => $e->getMessage(),
                ];
                if ($required) {
                    $result['missing'][] = $entry;
                } else {
                    $result['warned'][] = $entry;
                }
                continue;
            }

            $this->ensureMigrationTable($shard);

            foreach ($shardFiles as $file) {
                $result['shards'][] = $this->apply($shard, $file, $physical, $dryRun)
                    + ['year' => $year];
            }

            if (!$dryRun) {
                $this->register($core, $logical, $physical, $year);
                $result['shards'][] = [
                    'file'     => '(registry)',
                    'database' => $physical,
                    'status'   => 'registered',
                    'detail'   => "{$logical}: {$year}-01-01 .. {$year}-12-31",
                    'year'     => $year,
                ];
            }
        }

        $result['ok'] = $result['missing'] === [];
        return $result;
    }

    /**
     * Shard name and credentials for a year.
     *
     * Delegates to Db so this and the running application can never disagree
     * about which database a year lives in.
     *
     * @return array{name:string,user:string,pass:string,own:bool}
     */
    public function shardTarget(int $year): array
    {
        [$name, $user, $pass] = Db::shardTarget($year);

        return [
            'name' => $name,
            'user' => $user,
            'pass' => $pass,
            'own'  => isset((Config::get('db.shard_overrides', [])[$year] ?? [])['user']),
        ];
    }

    // -----------------------------------------------------------------

    private function ensureMigrationTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                filename   VARCHAR(191) NOT NULL,
                applied_at DATETIME     NOT NULL,
                checksum   CHAR(64)     NOT NULL,
                PRIMARY KEY (filename)
             ) ENGINE=InnoDB DEFAULT CHARSET=ascii'
        );
    }

    /** @return array{0:string[],1:string[]} core files, shard files */
    private function migrationFiles(): array
    {
        $files = glob($this->migrationDir . '/*.sql') ?: [];
        sort($files);

        return [
            array_values(array_filter($files, fn($f) => !str_contains(basename($f), '_shard'))),
            array_values(array_filter($files, fn($f) =>  str_contains(basename($f), '_shard'))),
        ];
    }

    private function register(PDO $core, string $logical, string $physical, int $year): void
    {
        $stmt = $core->prepare(
            'INSERT INTO shard_registry
                (shard_name, physical_name, date_from, date_to, is_writable,
                 is_provisioned, bytes_limit)
             VALUES (:name, :physical, :from, :to, 1, 1, :limit)
             ON DUPLICATE KEY UPDATE
                physical_name  = VALUES(physical_name),
                is_provisioned = 1'
        );
        $stmt->execute([
            ':name'     => $logical,
            ':physical' => $physical,
            ':from'     => sprintf('%d-01-01', $year),
            ':to'       => sprintf('%d-12-31', $year),
            ':limit'    => Config::get('storage.shard_bytes_limit', 3221225472),
        ]);
    }

    /**
     * @return array{file:string,database:string,status:string,detail:string}
     */
    private function apply(PDO $pdo, string $file, string $label, bool $dryRun): array
    {
        $name     = basename($file);
        $sql      = (string) file_get_contents($file);
        $checksum = hash('sha256', $sql);

        $row = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE filename = ?');
        $row->execute([$name]);
        $existing = $row->fetchColumn();

        if ($existing === $checksum) {
            return ['file' => $name, 'database' => $label, 'status' => 'skip', 'detail' => 'already applied'];
        }

        $statements = self::splitStatements($sql);
        $changed    = $existing !== false;

        if ($dryRun) {
            return [
                'file'     => $name,
                'database' => $label,
                'status'   => 'dry',
                'detail'   => count($statements) . ' statement(s)'
                    . ($changed ? ' — file changed since it was applied' : ''),
            ];
        }

        foreach ($statements as $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                throw new RuntimeException(
                    "Failed in {$name} -> {$label}\n\n"
                    . substr($stmt, 0, 400) . "\n\n" . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        $ins = $pdo->prepare(
            'INSERT INTO schema_migrations (filename, applied_at, checksum)
             VALUES (?, UTC_TIMESTAMP(), ?)
             ON DUPLICATE KEY UPDATE applied_at = UTC_TIMESTAMP(), checksum = VALUES(checksum)'
        );
        $ins->execute([$name, $checksum]);

        return [
            'file'     => $name,
            'database' => $label,
            'status'   => 'ok',
            'detail'   => count($statements) . ' statement(s)'
                . ($changed ? ' — re-applied after edit' : ''),
        ];
    }

    /**
     * Split a migration file into statements.
     *
     * Strips `--` comments while respecting single-quoted strings, because
     * several comments in these files contain semicolons and a naive split
     * would cut statements in half.
     *
     * @return string[]
     */
    public static function splitStatements(string $sql): array
    {
        $clean = [];

        foreach (explode("\n", $sql) as $line) {
            $out      = '';
            $inString = false;
            $len      = strlen($line);

            for ($i = 0; $i < $len; $i++) {
                $ch = $line[$i];

                if ($ch === "'" && ($i === 0 || $line[$i - 1] !== '\\')) {
                    $inString = !$inString;
                }
                if (!$inString && $ch === '-' && $i + 1 < $len && $line[$i + 1] === '-') {
                    break;
                }
                $out .= $ch;
            }

            $clean[] = $out;
        }

        $statements = [];
        foreach (explode(';', implode("\n", $clean)) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
        }

        return $statements;
    }
}
