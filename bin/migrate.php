<?php
/**
 * Project Odysseus — migration runner (CLI).
 *
 *   php bin/migrate.php [--dry-run] [--year=2027] [--env=.env.production]
 *
 * A thin wrapper over Migrator. The same engine drives the browser-based
 * setup page, so hosts without shell access get identical behaviour.
 *
 * Cannot create databases — shared hosting does not permit CREATE DATABASE
 * over SQL. A missing database is reported with the exact name to create in
 * hPanel, and the command exits 2 without half-applying anything.
 *
 * Migrations are tracked by filename + checksum. Every statement in
 * db/migrations is idempotent, so editing an applied migration re-runs it —
 * but after go-live, put schema changes in a NEW numbered file.
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

echo "Project Odysseus — migrate" . ($dryRun ? ' (DRY RUN)' : '') . "\n";
echo "Environment: {$envFile}\n";
echo 'Core database: ' . Config::require('db.core') . "\n";

try {
    $r = (new Migrator())->run($dryRun, $extraYear);
} catch (Throwable $e) {
    fwrite(STDERR, "\nMIGRATION FAILED\n" . $e->getMessage() . "\n");
    exit(1);
}

if ($r['error'] !== null) {
    fwrite(STDERR, "\nERROR: {$r['error']}\n");
    exit(1);
}

$line = static function (array $s): void {
    printf("  [%-6s] %-28s -> %s%s\n",
        $s['status'], $s['file'], $s['database'],
        $s['detail'] !== '' ? '  (' . $s['detail'] . ')' : ''
    );
};

echo "\nCore migrations:\n";
foreach ($r['core'] as $s) {
    $line($s);
}

echo "\nEvent shards:\n";
foreach ($r['shards'] as $s) {
    $line($s);
}

foreach ($r['warned'] as $w) {
    printf("  [%-6s] %s not created yet — not needed for %d days\n",
        'later', $w['database'], $r['days_left_in_year']);
    printf("           create it before %d-01-01; this command starts refusing\n", $w['year']);
    printf("           to run 60 days beforehand as a reminder\n");
}

if ($r['missing'] !== []) {
    echo "\n";
    echo str_repeat('=', 69) . "\n";
    echo " ACTION REQUIRED — create these databases in hPanel, then re-run.\n";
    echo " Shared hosting does not allow CREATE DATABASE over SQL.\n";
    echo str_repeat('=', 69) . "\n\n";

    foreach ($r['missing'] as $m) {
        echo "  Year {$m['year']}\n";
        echo "    hPanel -> Databases -> Create new database\n";
        echo "    Full name must end up as:  {$m['database']}\n";
        echo "    (hPanel prepends your account id to what you type and shows\n";
        echo "     the full result — match it to the line above.)\n\n";

        if ($m['own_cred']) {
            echo "    Credentials are already in .env as\n";
            echo "      DB_SHARD_{$m['year']}_USER / DB_SHARD_{$m['year']}_PASS\n\n";
        } else {
            echo "    Then add the new database's own credentials to .env:\n\n";
            echo "      DB_SHARD_{$m['year']}_USER=<the new user>\n";
            echo "      DB_SHARD_{$m['year']}_PASS=<the new password>\n\n";
            echo "    (Or grant an existing user access to it, if your host allows\n";
            echo "     one user across several databases. Hostinger does not.)\n\n";
        }
    }

    echo "  Every database must be reachable by SOME credential this app holds.\n";
    echo "  No query ever JOINs across databases, so they need not share a user.\n\n";
    exit(2);
}

echo "\nDone.\n";
