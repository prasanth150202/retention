<?php
/**
 * Project Odysseus — browser-based setup.
 *
 * Shared hosting frequently has no shell, so the one-time setup steps that
 * would normally be CLI commands are exposed here instead: create the runtime
 * directories, generate the encryption key, and apply the schema.
 *
 * It drives the same Migrator the CLI runner uses, so behaviour is identical.
 *
 * ACCESS CONTROL
 *   Inert unless SETUP_TOKEN is set in .env, and every request must present
 *   that token. Remove SETUP_TOKEN from .env when setup is finished and this
 *   page turns itself off.
 *
 *   It never prints credentials, the encryption key, or connection strings.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/app/lib/bootstrap.php';

// ---------------------------------------------------------------------
// Bare-minimum failures, before configuration is even loadable.
// ---------------------------------------------------------------------
function fail(string $title, string $body): never
{
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Odysseus setup</title>';
    echo '<style>body{font:15px/1.6 system-ui,sans-serif;max-width:760px;margin:60px auto;padding:0 24px;color:#111}'
       . 'h1{font-size:20px}code{background:#f4f4f5;padding:2px 6px;border-radius:4px}'
       . 'pre{background:#f4f4f5;padding:14px;border-radius:6px;overflow:auto}</style>';
    echo '<h1>' . htmlspecialchars($title) . '</h1>' . $body;
    exit;
}

if (!is_file($root . '/.env')) {
    fail('No .env file', <<<HTML
        <p>Setup cannot run until the environment file exists.</p>
        <p>In hPanel &rarr; <strong>File Manager</strong>, go to the folder containing
        <code>README.md</code>, copy <code>.env.example</code> to <code>.env</code>,
        and fill in the database values.</p>
        <p><code>.env</code> must sit beside <code>README.md</code>, not inside
        <code>public_html</code>.</p>
    HTML);
}

try {
    odysseus_boot();
} catch (Throwable $e) {
    fail('Configuration error', '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>');
}

// ---------------------------------------------------------------------
// Token gate
// ---------------------------------------------------------------------
$expected = (string) Env::get('SETUP_TOKEN', '');

if ($expected === '') {
    fail('Setup is disabled', <<<HTML
        <p>This page does nothing unless <code>SETUP_TOKEN</code> is set in
        <code>.env</code>. That is the intended resting state.</p>
        <p>To run setup, add a long random value to <code>.env</code>:</p>
        <pre>SETUP_TOKEN=<?= '' ?>choose-a-long-random-string</pre>
        <p>then open <code>/setup.php?token=that-value</code>. Remove the line
        again when you are finished.</p>
    HTML);
}

if (strlen($expected) < 16) {
    fail('SETUP_TOKEN is too short', <<<HTML
        <p>This page can create files and modify the database, so it is reachable
        by anyone who guesses the token. Use at least 16 characters.</p>
    HTML);
}

$supplied = (string) ($_REQUEST['token'] ?? '');

if (!hash_equals($expected, $supplied)) {
    // Slow down guessing a little without holding a worker for long.
    usleep(400_000);
    http_response_code(403);
    fail('Forbidden', '<p>Missing or incorrect setup token.</p>');
}

$action  = (string) ($_POST['action'] ?? '');
$results = [];

// ---------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------
if ($action !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($action) {
            case 'dirs':
                $made = [];
                foreach (['spool', 'processed', 'failed', 'locks', 'logs'] as $key) {
                    $dir = Config::get('paths.' . $key);
                    if (!is_dir($dir)) {
                        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
                            throw new RuntimeException("Could not create {$dir}");
                        }
                        $made[] = basename($dir);
                    }
                }
                $results[] = ['ok', 'Runtime directories',
                    $made === [] ? 'Already present.' : 'Created: ' . implode(', ', $made)];
                break;

            case 'keygen':
                $file = Config::get('secrets.master_key_file');
                if (is_file($file)) {
                    $results[] = ['warn', 'Encryption key',
                        'A key already exists. It was NOT replaced — regenerating it would '
                        . 'make every stored Shopify token permanently unreadable.'];
                    break;
                }
                if (!extension_loaded('openssl')) {
                    throw new RuntimeException('The openssl extension is not loaded.');
                }
                $dir = dirname($file);
                if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                    throw new RuntimeException("Could not create {$dir}");
                }
                if (file_put_contents($file, base64_encode(random_bytes(32)) . "\n", LOCK_EX) === false) {
                    throw new RuntimeException("Could not write {$file}");
                }
                @chmod($file, 0600);
                $results[] = ['ok', 'Encryption key',
                    'Generated. Download a copy and store it somewhere safe — it is not in '
                    . 'git and cannot be recovered. Losing it means re-onboarding every store.'];
                break;

            case 'migrate':
            case 'migrate_dry':
                $r = (new Migrator())->run($action === 'migrate_dry');
                if ($r['error'] !== null) {
                    throw new RuntimeException($r['error']);
                }
                foreach (array_merge($r['core'], $r['shards']) as $s) {
                    $results[] = [
                        $s['status'] === 'ok' || $s['status'] === 'registered' ? 'ok' : 'info',
                        $s['file'] . ' &rarr; ' . $s['database'],
                        ucfirst($s['status']) . ($s['detail'] ? ' — ' . $s['detail'] : ''),
                    ];
                }
                foreach ($r['warned'] as $w) {
                    $results[] = ['info', 'Shard ' . $w['year'],
                        htmlspecialchars($w['database']) . ' not created yet — not needed for '
                        . $r['days_left_in_year'] . ' days.'];
                }
                foreach ($r['missing'] as $m) {
                    $results[] = ['err', 'Missing database for ' . $m['year'],
                        'Create <code>' . htmlspecialchars($m['database']) . '</code> in hPanel, then add '
                        . '<code>DB_SHARD_' . $m['year'] . '_USER</code> and '
                        . '<code>DB_SHARD_' . $m['year'] . '_PASS</code> to .env.'];
                }
                break;

            default:
                throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        $results[] = ['err', 'Failed', htmlspecialchars($e->getMessage())];
    }
}

// ---------------------------------------------------------------------
// Status checks
// ---------------------------------------------------------------------
$checks = [];

$checks[] = ['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.1', '>=')];

foreach (['openssl', 'pdo_mysql', 'mbstring', 'curl'] as $ext) {
    $checks[] = ["Extension: {$ext}", extension_loaded($ext) ? 'loaded' : 'MISSING', extension_loaded($ext)];
}

// The misdeployment we keep hitting: repo dropped straight into public_html,
// which puts app/, config/, db/ and .env on the public internet.
$exposed = is_file($root . '/README.md')
    && str_contains(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/public_html')
    && !is_dir($root . '/public_html');
$checks[] = [
    'Document root',
    $exposed ? 'repository is inside public_html — private files may be reachable' : 'looks correct',
    !$exposed,
];

$keyFile = Config::get('secrets.master_key_file');
$checks[] = ['Encryption key', is_file($keyFile) ? 'present' : 'not generated yet', is_file($keyFile)];

$spool = Config::get('paths.spool');
$checks[] = ['Runtime directories', is_dir($spool) ? 'present' : 'not created yet', is_dir($spool)];

$geo = Config::get('paths.geolite');
$checks[] = ['GeoLite2 database', is_file($geo) ? 'present' : 'not uploaded (needed before ingest)', is_file($geo)];

try {
    $ver = Db::core()->query('SELECT VERSION()')->fetchColumn();
    $n   = (int) Db::core()->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
    )->fetchColumn();
    $checks[] = ['Core database', "connected ({$ver}), {$n} tables", true];
} catch (Throwable $e) {
    $checks[] = ['Core database', 'CANNOT CONNECT — ' . $e->getMessage(), false];
}

$tokenQs = '?token=' . rawurlencode($supplied);
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Odysseus setup</title>
<style>
  :root { color-scheme: light dark; }
  body { font: 15px/1.6 system-ui, -apple-system, Segoe UI, sans-serif;
         max-width: 860px; margin: 40px auto; padding: 0 24px; }
  h1 { font-size: 22px; margin-bottom: 4px; }
  h2 { font-size: 16px; margin-top: 34px; border-bottom: 1px solid #8883; padding-bottom: 6px; }
  .sub { opacity: .7; margin-top: 0; }
  table { border-collapse: collapse; width: 100%; margin-top: 10px; }
  td { padding: 7px 10px; border-bottom: 1px solid #8882; vertical-align: top; }
  td:first-child { width: 210px; font-weight: 600; }
  .ok   { color: #157f3b; } .bad  { color: #b3261e; }
  .warn { color: #8a6100; } .info { opacity: .75; }
  form { display: inline; }
  button { font: inherit; padding: 8px 14px; margin: 4px 6px 4px 0;
           border: 1px solid #8886; border-radius: 6px; background: #8881; cursor: pointer; }
  button:hover { background: #8883; }
  code { background: #8881; padding: 2px 6px; border-radius: 4px; }
  .box { border: 1px solid #8884; border-left: 4px solid #b3261e;
         padding: 12px 16px; border-radius: 6px; margin: 18px 0; }
</style>

<h1>Project Odysseus — setup</h1>
<p class="sub">One-time setup for hosts without shell access.</p>

<?php if ($exposed): ?>
<div class="box">
  <strong>The repository is deployed inside <code>public_html</code>.</strong>
  <p>That puts <code>app/</code>, <code>config/</code>, <code>db/</code> and
  <code>.env</code> on the public internet. The bundled <code>.htaccess</code>
  blocks the worst of it, but the real fix is to point the Hostinger Git
  deployment at the folder <em>above</em> <code>public_html</code>, so only
  <code>public_html/</code> is served.</p>
</div>
<?php endif; ?>

<?php if ($results !== []): ?>
<h2>Result</h2>
<table>
  <?php foreach ($results as [$level, $label, $detail]): ?>
  <tr>
    <td class="<?= $level === 'ok' ? 'ok' : ($level === 'err' ? 'bad' : ($level === 'warn' ? 'warn' : 'info')) ?>">
      <?= htmlspecialchars($label) ?>
    </td>
    <td><?= $detail ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<h2>Status</h2>
<table>
  <?php foreach ($checks as [$label, $value, $good]): ?>
  <tr>
    <td><?= htmlspecialchars($label) ?></td>
    <td class="<?= $good ? 'ok' : 'bad' ?>"><?= htmlspecialchars($value) ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<h2>Actions</h2>
<form method="post" action="setup.php<?= htmlspecialchars($tokenQs) ?>">
  <input type="hidden" name="token" value="<?= htmlspecialchars($supplied) ?>">
  <button name="action" value="dirs">Create runtime directories</button>
  <button name="action" value="keygen">Generate encryption key</button>
  <button name="action" value="migrate_dry">Preview migrations</button>
  <button name="action" value="migrate">Apply migrations</button>
</form>

<h2>When you are finished</h2>
<ol>
  <li>Download <code>secrets/master.key</code> and store it offline. It is not in
      git; losing it means re-onboarding every connected store.</li>
  <li>Upload <code>GeoLite2-City.mmdb</code> into <code>secrets/</code> — needed
      before the first import, not before now.</li>
  <li>Remove <code>SETUP_TOKEN</code> from <code>.env</code>. This page then
      refuses to do anything at all.</li>
</ol>
