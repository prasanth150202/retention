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

$opts      = getopt('', ['dry-run', 'year::', 'env::']);
$dryRun    = array_key_exists('dry-run', $opts);
$extraYear = isset($opts['year']) ? (int) $opts['year'] : null;

// --env lets one checkout target several environments (local vs production)
// without swapping files around. Env::load() is first-wins, so loading here
// means config.php's own load() call becomes a no-op.
$envFile = isset($opts['env']) && $opts['env'] !== false
    ? (string) $opts['env']
    : $root . '/.env';

if (!is_file($envFile)) {
    fwrite(STDERR, "ERROR: environment file not found: {$envFile}\n");
    fwrite(STDERR, "Copy .env.example to .env and fill in the database credentials.\n");
    exit(1);
}

require_once $root . '/app/lib/Env.php';

try {
    Env::load($envFile);
    $cfg = require $root . '/config/config.php';
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR loading configuration:\n  " . $e->getMessage() . "\n");
    exit(1);
}

echo "Environment file: {$envFile}\n";

$coreName = $cfg['db']['core'];

if (!str_contains($cfg['db']['shard'], '{year}')) {
    fwrite(STDERR, "ERROR: DB_SHARD must contain the literal {year} placeholder.\n");
    fwrite(STDERR, "Got: {$cfg['db']['shard']}\n");
    fwrite(STDERR, "Example: u123456789_odys_ev_{year}\n");
    exit(1);
}

/** Real database name for a shard year, e.g. u123456789_odys_ev_2026. */
function shardPhysical(array $cfg, int $year): string
{
    return $cfg['db']['shard_overrides'][$year]['name']
        ?? str_replace('{year}', (string) $year, $cfg['db']['shard']);
}

/**
 * Credentials for a shard year.
 *
 * Falls back to the core credentials when a year has no override, which
 * covers hosts that allow one user across several databases. Hostinger
 * issues one credential per database, so in production each year normally
 * has its own DB_SHARD_<YEAR>_USER / _PASS block.
 */
function shardCredentials(array $cfg, int $year): array
{
    $o = $cfg['db']['shard_overrides'][$year] ?? [];
    return [
        'user' => $o['user'] ?? $cfg['db']['user'],
        'pass' => $o['pass'] ?? $cfg['db']['pass'],
        'own'  => isset($o['user']),
    ];
}

/**
 * Stable logical id recorded in shard_registry.shard_name.
 *
 * Deliberately independent of the hosting account, so the registry stays
 * meaningful if the site is ever migrated to a host with a different
 * database naming scheme. physical_name carries the real name.
 */
function shardLogical(int $year): string
{
    return 'ev_' . $year;
}

function connect(array $cfg, string $database, ?string $user = null, ?string $pass = null): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['db']['host'],
        (int) ($cfg['db']['port'] ?? 3306),
        $database,
        $cfg['db']['charset'] ?? 'utf8mb4'
    );

    return new PDO($dsn, $user ?? $cfg['db']['user'], $pass ?? $cfg['db']['pass'], [
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

$thisYear = (int) gmdate('Y');
$nextYear = $thisYear + 1;

// Only the shard covering today is genuinely required. Next year's is a
// warning until the year is nearly over — there is no sense blocking a
// migration in January on a database not needed for eleven months.
$daysLeftInYear = (int) ((strtotime(($thisYear + 1) . '-01-01') - time()) / 86400);
$nextYearUrgent = $daysLeftInYear <= 60;

$years = [$thisYear => true, $nextYear => $nextYearUrgent];
if ($extraYear !== null) {
    $years[$extraYear] = true;   // explicitly requested, so treat as required
}
ksort($years);

echo "\nEvent shards:\n";

$missing = [];
$warned  = [];

foreach ($years as $year => $required) {
    $physical = shardPhysical($cfg, $year);
    $logical  = shardLogical($year);
    $cred     = shardCredentials($cfg, $year);

    try {
        $shard = connect($cfg, $physical, $cred['user'], $cred['pass']);
    } catch (PDOException $e) {
        if ($required) {
            echo "  [MISSING] {$physical}" . ($cred['own'] ? " (own credentials)" : "") . "\n";
            $missing[] = [$year, $physical, $logical, $cred];
        } else {
            echo "  [later]   {$physical} not created yet — not needed for {$daysLeftInYear} days\n";
            $warned[] = [$year, $physical, $logical, $cred];
        }
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

if ($warned !== []) {
    echo "\n";
    foreach ($warned as [$year, $physical, , ]) {
        echo "  NOTE: create {$physical} before {$year}-01-01. This command will\n";
        echo "        start refusing to run 60 days beforehand as a reminder.\n";
    }
}

if ($missing !== []) {
    echo "\n";
    echo "=====================================================================\n";
    echo " ACTION REQUIRED — create these databases in hPanel, then re-run.\n";
    echo " Shared hosting does not allow CREATE DATABASE over SQL.\n";
    echo "=====================================================================\n\n";

    foreach ($missing as [$year, $physical, $logical, $cred]) {
        echo "  Year {$year}\n";
        echo "    hPanel -> Databases -> Create new database\n";
        echo "    Full name must end up as:  {$physical}\n";
        echo "    (hPanel prepends your account id to whatever you type and\n";
        echo "     shows the full result - match it to the line above.)\n\n";

        if ($cred['own']) {
            echo "    Credentials for it are already in .env as\n";
            echo "      DB_SHARD_{$year}_USER / DB_SHARD_{$year}_PASS\n\n";
        } else {
            echo "    Then EITHER grant user '{$cfg['db']['user']}' access to it,\n";
            echo "    OR — if your host issues one credential per database, as\n";
            echo "    Hostinger does — add the new database's own credentials to\n";
            echo "    .env:\n\n";
            echo "      DB_SHARD_{$year}_USER=<the new user>\n";
            echo "      DB_SHARD_{$year}_PASS=<the new password>\n\n";
        }
    }

    echo "  Each database must be reachable by SOME credential this app holds.\n";
    echo "  Cross-database JOINs are never issued, so the databases do not need\n";
    echo "  to share a user — but every one of them must be reachable.\n\n";
    exit(2);
}

echo "\nDone.\n";
