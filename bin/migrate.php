<?php
/**
 * Project Odysseus — migration runner.
 *
 *   php bin/migrate.php [--dry-run] [--year=2027]
 *
 * Applies db/migrations/*.sql. Filenames containing "_shard" are applied to
 * every event shard; all others are applied to the core database.
 *
 * IMPORTANT — this runner cannot create databases. Hostinger shared hosting
 * does not permit CREATE DATABASE over SQL. When a shard is missing it
 * prints the exact name to create in hPanel, plus the grant reminder, and
 * continues with everything else.
 *
 * Migrations are tracked by filename + checksum in core.schema_migrations.
 * Editing an already-applied migration re-runs it, which is safe because
 * every statement in db/migrations is idempotent (CREATE TABLE IF NOT
 * EXISTS / INSERT ... ON DUPLICATE KEY). After go-live, make schema changes
 * in a NEW numbered file rather than editing an old one.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

if (!is_file($root . '/.env')) {
    fwrite(STDERR, "ERROR: .env not found at the repo root.\n");
    fwrite(STDERR, "Copy .env.example to .env and fill in the database credentials.\n");
    exit(1);
}

try {
    $cfg = require $root . '/config/config.php';
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR loading configuration:\n  " . $e->getMessage() . "\n");
    exit(1);
}

$opts     = getopt('', ['dry-run', 'year::']);
$dryRun   = array_key_exists('dry-run', $opts);
$extraYear = isset($opts['year']) ? (int) $opts['year'] : null;

$prefix   = $cfg['db']['prefix'] ?? '';
$coreName = $prefix . ($cfg['db']['core'] ?? 'odys_core');

/** Physical database name for an event shard year. */
function shardPhysical(array $cfg, int $year): string
{
    $pattern = $cfg['db']['shard_pattern'] ?? 'odys_ev_{year}';
    return ($cfg['db']['prefix'] ?? '') . str_replace('{year}', (string) $year, $pattern);
}

/** Logical shard name, as recorded in shard_registry and the plan document. */
function shardLogical(array $cfg, int $year): string
{
    $pattern = $cfg['db']['shard_pattern'] ?? 'odys_ev_{year}';
    return str_replace('{year}', (string) $year, $pattern);
}

function connect(array $cfg, string $database): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['db']['host'],
        (int) ($cfg['db']['port'] ?? 3306),
        $database,
        $cfg['db']['charset'] ?? 'utf8mb4'
    );

    return new PDO($dsn, $cfg['db']['user'], $cfg['db']['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

/**
 * Split a migration file into statements.
 *
 * Strips `--` comments (respecting single-quoted strings, since several
 * comments in these files contain semicolons) and splits on `;`.
 */
function splitStatements(string $sql): array
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
                break; // rest of the line is a comment
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

function applyMigration(PDO $pdo, string $file, string $label, bool $dryRun): bool
{
    $name     = basename($file);
    $sql      = file_get_contents($file);
    $checksum = hash('sha256', $sql);

    $row = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE filename = ?');
    $row->execute([$name]);
    $existing = $row->fetchColumn();

    if ($existing === $checksum) {
        echo "  [skip]  {$name} -> {$label}\n";
        return false;
    }
    if ($existing !== false) {
        echo "  [WARN]  {$name} changed since it was applied; re-running (statements are idempotent)\n";
    }

    $statements = splitStatements($sql);

    if ($dryRun) {
        echo "  [dry]   {$name} -> {$label} (" . count($statements) . " statements)\n";
        return false;
    }

    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            fwrite(STDERR, "\nFAILED in {$name} -> {$label}\n");
            fwrite(STDERR, substr($stmt, 0, 400) . "\n\n");
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
    }

    $ins = $pdo->prepare(
        'INSERT INTO schema_migrations (filename, applied_at, checksum)
         VALUES (?, UTC_TIMESTAMP(), ?)
         ON DUPLICATE KEY UPDATE applied_at = UTC_TIMESTAMP(), checksum = VALUES(checksum)'
    );
    $ins->execute([$name, $checksum]);

    echo "  [ok]    {$name} -> {$label} (" . count($statements) . " statements)\n";
    return true;
}

// ---------------------------------------------------------------------
// Core
// ---------------------------------------------------------------------

echo "Project Odysseus — migrate" . ($dryRun ? " (DRY RUN)" : "") . "\n\n";
echo "Core database: {$coreName}\n";

try {
    $core = connect($cfg, $coreName);
} catch (PDOException $e) {
    fwrite(STDERR, "\nCannot connect to core database '{$coreName}'.\n");
    fwrite(STDERR, "Create it in hPanel -> Databases, then grant '{$cfg['db']['user']}' access to it.\n\n");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

// schema_migrations must exist before anything can be tracked.
$core->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        filename   VARCHAR(191) NOT NULL,
        applied_at DATETIME     NOT NULL,
        checksum   CHAR(64)     NOT NULL,
        PRIMARY KEY (filename)
     ) ENGINE=InnoDB DEFAULT CHARSET=ascii'
);

$files = glob($root . '/db/migrations/*.sql') ?: [];
sort($files);

$coreFiles  = array_values(array_filter($files, fn($f) => !str_contains(basename($f), '_shard')));
$shardFiles = array_values(array_filter($files, fn($f) =>  str_contains(basename($f), '_shard')));

echo "\nCore migrations:\n";
foreach ($coreFiles as $file) {
    applyMigration($core, $file, $coreName, $dryRun);
}

// ---------------------------------------------------------------------
// Shards — current year and next year, so rotation never happens late.
// ---------------------------------------------------------------------

$years = [(int) gmdate('Y'), (int) gmdate('Y') + 1];
if ($extraYear !== null && !in_array($extraYear, $years, true)) {
    $years[] = $extraYear;
}
sort($years);

echo "\nEvent shards:\n";

$missing = [];

foreach ($years as $year) {
    $physical = shardPhysical($cfg, $year);
    $logical  = shardLogical($cfg, $year);

    try {
        $shard = connect($cfg, $physical);
    } catch (PDOException $e) {
        echo "  [MISSING] {$physical}\n";
        $missing[] = [$year, $physical, $logical];
        continue;
    }

    $shard->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            filename   VARCHAR(191) NOT NULL,
            applied_at DATETIME     NOT NULL,
            checksum   CHAR(64)     NOT NULL,
            PRIMARY KEY (filename)
         ) ENGINE=InnoDB DEFAULT CHARSET=ascii'
    );

    foreach ($shardFiles as $file) {
        applyMigration($shard, $file, $physical, $dryRun);
    }

    if (!$dryRun) {
        $reg = $core->prepare(
            'INSERT INTO shard_registry
                (shard_name, physical_name, date_from, date_to, is_writable,
                 is_provisioned, bytes_limit)
             VALUES (?, ?, ?, ?, 1, 1, ?)
             ON DUPLICATE KEY UPDATE
                physical_name  = VALUES(physical_name),
                is_provisioned = 1'
        );
        $reg->execute([
            $logical,
            $physical,
            sprintf('%d-01-01', $year),
            sprintf('%d-12-31', $year),
            $cfg['storage']['shard_bytes_limit'] ?? 3221225472,
        ]);
        echo "  [reg]   {$logical} registered {$year}-01-01 .. {$year}-12-31\n";
    }
}

// ---------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------

if ($missing !== []) {
    echo "\n";
    echo "=====================================================================\n";
    echo " ACTION REQUIRED — create these databases in hPanel, then re-run.\n";
    echo " Shared hosting does not allow CREATE DATABASE over SQL.\n";
    echo "=====================================================================\n\n";

    foreach ($missing as [$year, $physical, $logical]) {
        $suffix = $prefix !== '' && str_starts_with($physical, $prefix)
            ? substr($physical, strlen($prefix))
            : $physical;
        echo "  Year {$year}\n";
        echo "    hPanel -> Databases -> Create new database\n";
        echo "    Name field: {$suffix}      (hPanel prepends '{$prefix}')\n";
        echo "    Then grant user '{$cfg['db']['user']}' access to it.\n\n";
    }

    echo "  The grant matters: one MySQL user must reach the core database AND\n";
    echo "  every shard, or cross-database queries fail at runtime.\n\n";
    exit(2);
}

echo "\nDone.\n";
