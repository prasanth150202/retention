<?php
/**
 * Project Odysseus — generate the master encryption key and per-tenant salts.
 *
 *   php bin/keygen.php            create secrets/master.key if absent
 *   php bin/keygen.php --force    regenerate (DESTRUCTIVE, see below)
 *
 * The master key encrypts stored Shopify Admin API tokens (AES-256-GCM).
 *
 * WARNING — regenerating this key does NOT re-encrypt existing tokens. Every
 * token already in the database becomes permanently undecryptable and every
 * store must be re-onboarded through OAuth. --force refuses to run if any
 * tenant already holds an encrypted token.
 *
 * Back the key up OFFLINE. It is gitignored on purpose: a key committed to
 * the repo protects nothing.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!extension_loaded('openssl')) {
    fwrite(STDERR, "ERROR: the openssl extension is required and is not loaded.\n");
    fwrite(STDERR, "Token encryption cannot work without it.\n");
    exit(1);
}

$root  = dirname(__DIR__);
$opts  = getopt('', ['force', 'env::']);
$force = array_key_exists('force', $opts);

require_once $root . '/app/lib/bootstrap.php';

try {
    odysseus_boot(odysseus_env_arg());
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR loading configuration:\n  " . $e->getMessage() . "\n");
    exit(1);
}

// Read the location from configuration rather than assuming repo/secrets.
// An earlier version hardcoded that path, which on any server setting
// SECRETS_PATH wrote the key somewhere the application would never look for
// it — producing a key that exists and yet cannot decrypt anything.
$secretsDir = (string) Config::get('paths.secrets');
$saltsDir   = (string) Config::get('secrets.salt_dir');
$keyFile    = (string) Config::get('secrets.master_key_file');

echo "Secrets directory: {$secretsDir}\n\n";

foreach ([$secretsDir, $saltsDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        fwrite(STDERR, "ERROR: cannot create {$dir}\n");
        exit(1);
    }
    @chmod($dir, 0700);
}

if (is_file($keyFile) && !$force) {
    echo "master.key already exists — nothing to do.\n";
    echo "Use --force only if you understand that every stored Shopify token\n";
    echo "becomes permanently undecryptable.\n";
    exit(0);
}

if (is_file($keyFile) && $force) {
    // Refuse to destroy live tokens. Check the database before overwriting.
    if (is_file($root . '/.env')) {
        try {
            $n = (int) Db::core()->query(
                'SELECT COUNT(*) FROM tenants WHERE admin_token_enc IS NOT NULL'
            )->fetchColumn();

            if ($n > 0) {
                fwrite(STDERR, "REFUSING: {$n} tenant(s) hold encrypted tokens.\n");
                fwrite(STDERR, "Regenerating the key would orphan them permanently.\n");
                fwrite(STDERR, "Re-onboard those stores first, or restore the old key.\n");
                exit(1);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "Could not verify tenant tokens before --force:\n");
            fwrite(STDERR, "  " . $e->getMessage() . "\n");
            fwrite(STDERR, "Refusing to overwrite the key while this is unknown.\n");
            exit(1);
        }
    }

    $backup = $keyFile . '.' . gmdate('Ymd-His') . '.bak';
    if (!copy($keyFile, $backup)) {
        fwrite(STDERR, "ERROR: could not back up the existing key. Aborting.\n");
        exit(1);
    }
    @chmod($backup, 0600);
    echo "Existing key backed up to " . basename($backup) . "\n";
}

$key = random_bytes(32);   // AES-256

if (file_put_contents($keyFile, base64_encode($key) . "\n", LOCK_EX) === false) {
    fwrite(STDERR, "ERROR: cannot write {$keyFile}\n");
    exit(1);
}
@chmod($keyFile, 0600);

echo "Wrote secrets/master.key (32 random bytes, base64, mode 0600)\n\n";
echo "NEXT:\n";
echo "  1. Back this file up OFFLINE. It is not in git and cannot be recovered.\n";
echo "  2. Confirm secrets/ is above public_html and not web-reachable.\n";
echo "  3. Fetch the geo database (setup page, Step 5) before the first import.\n";
