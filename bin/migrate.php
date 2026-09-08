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

require_once $root . '/app/lib/bootstrap.php';

$opts      = getopt('', ['dry-run', 'year::', 'env::']);
$dryRun    = array_key_exists('dry-run', $opts);
$extraYear = isset($opts['year']) ? (int) $opts['year'] : null;

// --env lets one checkout target several environments (local vs production)
// without swapping files around.
$envFile = odysseus_env_arg() ?? $root . '/.env';

if (!is_file($envFile)) {
    fwrite(STDERR, "ERROR: environment file not found: {$envFile}\n");
    fwrite(STDERR, "Copy .env.example to .env and fill in the database credentials.\n");
    exit(1);
}

try {
    odysseus_boot($envFile);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR loading configuration:\n  " . $e->getMessage() . "\n");
    exit(1);
}

echo "Environment file: {$envFile}\n";

$coreName = Config::require('db.core');
$dbUser   = Config::require('db.user');

if (!str_contains(Config::require('db.shard'), '{year}')) {
    fwrite(STDERR, "ERROR: DB_SHARD must contain the literal {year} placeholder.\n");
    fwrite(STDERR, 'Got: ' . Config::require('db.shard') . "\n");
    fwrite(STDERR, "Example: u123456789_odys_ev_{year}\n");
    exit(1);
}

/**
 * Shard name and credentials for a year.
 *
 * Delegates to Db::shardTarget() so this tool and the application can never
 * disagree about which database a year lives in — a disagreement here would
 * apply the schema to one database while the app wrote to another.
 *
 * @return array{name:string,user:string,pass:string,own:bool}
 */
function shardTarget(int $year): array
{
    [$name, $user, $pass] = Db::shardTarget($year);

    return [
        'name' => $name,
        'user' => $user,
        'pass' => $pass,
        'own'  => isset((Config::get('db.shard_overrides', [])[$year] ?? [])['user']),
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

/**
 * Delegates to Db::connect() so this tool uses exactly the pooling, UTC
 * session and error handling the application uses at runtime.
 */
function connect(string $database, string $user, string $pass): PDO
{
    return Db::connect($database, $user, $pass);
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
    $core = connect($coreName, $dbUser, (string) Config::get('db.pass', ''));
} catch (Throwable $e) {
    fwrite(STDERR, "\nCannot connect to core database '{$coreName}'.\n");
    fwrite(STDERR, "Create it in hPanel -> Databases, then grant '{$dbUser}' access to it.\n\n");
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
    $cred     = shardTarget($year);
    $physical = $cred['name'];
    $logical  = shardLogical($year);

    try {
        $shard = connect($physical, $cred['user'], $cred['pass']);
    } catch (Throwable $e) {
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
            Config::get('storage.shard_bytes_limit', 3221225472),
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
            echo "    Then EITHER grant user '{$dbUser}' access to it,\n";
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
