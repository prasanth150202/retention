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
/**
 * When was this file last deployed?
 *
 * Shown on every page, including the ones behind the token gate, so "did my
 * push actually reach the server" is answerable without logging in anywhere.
 * A file modification time reveals nothing sensitive.
 */
function deployedAt(): string
{
    $t = @filemtime(__FILE__);
    return $t ? gmdate('Y-m-d H:i:s', $t) . ' UTC' : 'unknown';
}

/**
 * Stop, and say why.
 *
 * The status is a parameter because these are not all the same kind of stop.
 * Refusing a request is not a server fault, and reporting one as the other
 * sends whoever is reading the logs looking for a broken application instead
 * of a mistyped token — while burying the 500s that do mean something.
 */
function fail(string $title, string $body, int $status = 500): never
{
    http_response_code($status);
    echo '<!doctype html><meta charset="utf-8"><title>Odysseus setup</title>';
    echo '<style>body{font:15px/1.6 system-ui,sans-serif;max-width:760px;margin:60px auto;padding:0 24px;color:#111}'
       . 'h1{font-size:20px}code{background:#f4f4f5;padding:2px 6px;border-radius:4px}'
       . 'pre{background:#f4f4f5;padding:14px;border-radius:6px;overflow:auto}'
       . 'footer{margin-top:40px;font-size:12px;opacity:.6}</style>';
    echo '<h1>' . htmlspecialchars($title) . '</h1>' . $body;
    echo '<footer>setup.php last deployed: ' . htmlspecialchars(deployedAt())
       . '<br>If that is older than your last push, the deployment has not run yet.</footer>';
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
    fail(
        'Setup is disabled',
        <<<HTML
        <p>This page does nothing unless <code>SETUP_TOKEN</code> is set in
        <code>.env</code>. That is the intended resting state.</p>
        <p>To run setup, add a long random value to <code>.env</code>:</p>
        <pre>SETUP_TOKEN=<?= '' ?>choose-a-long-random-string</pre>
        <p>then open <code>/setup.php?token=that-value</code>. Remove the line
        again when you are finished.</p>
    HTML,
        403
    );
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
    fail('Forbidden', '<p>Missing or incorrect setup token.</p>', 403);
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
                $want = [
                    'spool'     => Config::get('paths.spool'),
                    'processed' => Config::get('paths.processed'),
                    'failed'    => Config::get('paths.failed'),
                    'locks'     => Config::get('paths.locks'),
                    'logs'      => Config::get('paths.logs'),
                    'secrets'   => Config::get('paths.secrets'),
                    'salts'     => Config::get('secrets.salt_dir'),
                ];

                foreach ($want as $label => $dir) {
                    if (is_dir($dir)) {
                        continue;
                    }
                    // Recursive: creates parents too, so nothing has to be
                    // made by hand in File Manager first.
                    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
                        $why = 'PHP could not create it.';
                        $ob  = trim((string) ini_get('open_basedir'));
                        if ($ob !== '') {
                            $why .= ' This host restricts PHP to open_basedir (' . htmlspecialchars($ob)
                                  . '), so the path must sit inside one of those directories.';
                        }
                        $why .= ' Either pick a path inside the allowed area, or create the folder'
                              . ' once in hPanel File Manager and press this button again.';
                        throw new RuntimeException("Could not create {$dir}. {$why}");
                    }
                    $made[] = $label;
                }

                $results[] = ['ok', 'Runtime directories',
                    ($made === [] ? 'All already present.' : 'Created: ' . implode(', ', $made))
                    . '<br><small>storage: <code>' . htmlspecialchars(Config::get('paths.storage'))
                    . '</code><br>secrets: <code>' . htmlspecialchars(Config::get('paths.secrets'))
                    . '</code></small>'];
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

            case 'staff':
                // The only way to create the first account: there is nobody to
                // sign in as yet, so this cannot live behind the login.
                require_once $root . '/app/lib/Auth.php';

                $email = trim((string) ($_POST['email'] ?? ''));
                $name  = trim((string) ($_POST['name'] ?? ''));
                $pass  = (string) ($_POST['password'] ?? '');

                $id = Auth::createUser($email, $pass, $name);

                $results[] = ['ok', 'Staff account',
                    'Created <code>' . htmlspecialchars($email) . '</code> (id ' . $id . '). '
                    . 'Sign in at <a href="/">the console</a>.'];
                break;

            case 'geoip':
                // DB-IP City Lite rather than MaxMind GeoLite2: same MMDB
                // format and same reader, but a direct download with no
                // account, no licence key and no sales funnel to navigate.
                //
                // Licensed CC-BY 4.0, which obliges us to credit DB-IP on any
                // page that displays results from it. The Geography tab must
                // carry "IP Geolocation by DB-IP" linking to https://db-ip.com.
                $dest = Config::get('paths.geoip');
                $dir  = dirname($dest);

                if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                    throw new RuntimeException("Run Step 2 first — {$dir} does not exist.");
                }
                if (!extension_loaded('curl')) {
                    throw new RuntimeException('The curl extension is not loaded.');
                }

                @set_time_limit(600);

                // Published monthly. Early in a month the new file may not be
                // out yet, so fall back to the previous one.
                $urls = [];
                foreach ([0, 1, 2] as $back) {
                    $m = gmdate('Y-m', strtotime("-{$back} month"));
                    $urls[] = "https://download.db-ip.com/free/dbip-city-lite-{$m}.mmdb.gz";
                }

                $tmpGz    = $dest . '.download.gz';
                $chosen   = null;
                $lastErr  = '';

                foreach ($urls as $url) {
                    $fh = @fopen($tmpGz, 'wb');
                    if ($fh === false) {
                        throw new RuntimeException("Cannot write to {$dir}. Check permissions.");
                    }
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_FILE           => $fh,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_TIMEOUT        => 540,
                        CURLOPT_CONNECTTIMEOUT => 20,
                        CURLOPT_FAILONERROR    => true,
                    ]);
                    $ok   = curl_exec($ch);
                    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $err  = curl_error($ch);
                    curl_close($ch);
                    fclose($fh);

                    if ($ok && $code === 200 && filesize($tmpGz) > 1_000_000) {
                        $chosen = $url;
                        break;
                    }
                    $lastErr = $err !== '' ? $err : "HTTP {$code}";
                    @unlink($tmpGz);
                }

                if ($chosen === null) {
                    throw new RuntimeException(
                        'Could not download the geo database (' . htmlspecialchars($lastErr) . '). '
                        . 'If outbound HTTP is blocked, download it yourself from '
                        . 'https://db-ip.com/db/download/ip-to-city-lite, unzip it, and upload '
                        . 'the .mmdb as <code>' . htmlspecialchars(basename($dest)) . '</code>.'
                    );
                }

                // Decompress in chunks; the file is ~60 MB packed and larger
                // unpacked, so it must never be held in memory whole.
                $in  = @gzopen($tmpGz, 'rb');
                $out = @fopen($dest . '.tmp', 'wb');
                if ($in === false || $out === false) {
                    @unlink($tmpGz);
                    throw new RuntimeException('Downloaded the file but could not unpack it.');
                }
                $bytes = 0;
                while (!gzeof($in)) {
                    $chunk = gzread($in, 1 << 18);
                    if ($chunk === false) {
                        break;
                    }
                    $bytes += (int) fwrite($out, $chunk);
                }
                gzclose($in);
                fclose($out);
                @unlink($tmpGz);

                if ($bytes < 1_000_000) {
                    @unlink($dest . '.tmp');
                    throw new RuntimeException('Unpacked file looks truncated. Try again.');
                }

                // Atomic-ish swap so a half-written file is never in place.
                if (!@rename($dest . '.tmp', $dest)) {
                    @unlink($dest . '.tmp');
                    throw new RuntimeException("Could not move the database into {$dest}.");
                }
                @chmod($dest, 0600);

                $results[] = ['ok', 'Geo database',
                    'Downloaded and unpacked — ' . number_format($bytes / 1048576, 1) . ' MB.<br>'
                    . '<small>Source: ' . htmlspecialchars(basename($chosen))
                    . ' (DB-IP City Lite, CC-BY 4.0). The Geography tab must credit '
                    . '<a href="https://db-ip.com">DB-IP</a>.</small>'];
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

// Runtime data inside the repository is destroyed by any deploy step that
// runs `git clean`. The master key and unimported pixel events are both
// unrecoverable, so this is checked prominently rather than documented.
$norm       = static fn(string $p): string => rtrim(str_replace('\\', '/', $p), '/');
$storageDir = Config::get('paths.storage');
$secretsDir = Config::get('paths.secrets');
$inRepo     = str_starts_with($norm($storageDir), $norm($root))
           || str_starts_with($norm($secretsDir), $norm($root));

// The genuinely dangerous placement: runtime data under a document root is
// downloadable over HTTP. master.key in particular would be fetchable by
// anyone who guessed the URL. Checked separately from $inRepo because once
// the document root is corrected the two stop being the same directory.
$docRoot   = $norm((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$webExposed = $docRoot !== '' && (
       str_starts_with($norm($storageDir), $docRoot)
    || str_starts_with($norm($secretsDir), $docRoot)
);

// Suggest a location that is outside the repository (so a deploy cannot
// delete it), above the document root (so it is not web-reachable), and
// inside open_basedir if the host sets one.
$openBasedir = trim((string) ini_get('open_basedir'));
$suggested   = dirname($norm($root)) . '/odysseus-data';

if ($openBasedir !== '') {
    $allowed = array_map($norm, explode(PATH_SEPARATOR, $openBasedir));
    $ok      = false;
    foreach ($allowed as $a) {
        if ($a !== '' && str_starts_with($suggested, $a)) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        // Fall back to a sibling of the repository that is definitely inside
        // the sandbox, still outside the git working tree.
        $suggested = $norm($root) . '/../odysseus-data';
        foreach ($allowed as $a) {
            if ($a !== '') {
                $suggested = rtrim($a, '/') . '/odysseus-data';
                break;
            }
        }
    }
    $checks[] = ['open_basedir', $openBasedir, true];
}

$checks[] = [
    'Storage path',
    $storageDir . ($inRepo ? '  — INSIDE the repository' : ''),
    !$inRepo,
];
$checks[] = [
    'Secrets path',
    $secretsDir . ($inRepo ? '  — INSIDE the repository' : ''),
    !$inRepo,
];

// These three are "not done yet", not "broken". Showing them red made it
// look like something had failed when in fact no button had been pressed.
$spool = Config::get('paths.spool');
$checks[] = [
    'Runtime directories',
    is_dir($spool) ? 'present' : 'not created yet — press Step 2 below',
    is_dir($spool) ? true : 'pending',
];

$keyFile = Config::get('secrets.master_key_file');
$checks[] = [
    'Encryption key',
    is_file($keyFile) ? 'present' : 'not generated yet — press Step 3 below',
    is_file($keyFile) ? true : 'pending',
];

$geo = Config::get('paths.geoip');
$checks[] = [
    'Geo database',
    is_file($geo)
        ? 'present — ' . number_format(filesize($geo) / 1048576, 1) . ' MB'
        : 'not downloaded — press Step 5 below (only needed before the first import)',
    is_file($geo) ? true : 'pending',
];

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
<p class="sub">One-time setup for hosts without shell access.<br>
<small>This file last deployed: <strong><?= htmlspecialchars(deployedAt()) ?></strong>
— if that is older than your last push, the deployment has not run yet and you are
looking at old code.</small></p>

<?php if ($webExposed): ?>
<div class="box">
  <strong>Runtime data is inside the document root — it is downloadable over HTTP.</strong>
  <p><code>master.key</code> decrypts every stored Shopify token. If it sits under
  the document root, anyone who guesses the URL can fetch it.</p>
  <p>Move it above the document root now:</p>
  <pre>STORAGE_PATH=<?= htmlspecialchars($suggested) ?>/storage
SECRETS_PATH=<?= htmlspecialchars($suggested) ?>/secrets</pre>
  <p>Then press <strong>Create runtime directories</strong> and
  <strong>Generate encryption key</strong> again, and delete the old folder.</p>
</div>
<?php endif; ?>

<?php if ($inRepo): ?>
<div class="box">
  <strong>Runtime data is stored inside the repository.</strong>
  <p>If your deployment runs <code>git clean</code>, these files are deleted on
  every deploy:</p>
  <ul>
    <li><code>master.key</code> — every stored Shopify token becomes permanently
        unreadable and every store must be re-onboarded.</li>
    <li><code>spool/</code> — pixel events not yet imported. Orders can be
        re-fetched from Shopify; <strong>pixel events cannot be recovered from
        anywhere</strong>.</li>
  </ul>
  <p>Add these two lines to <code>.env</code>, then reload this page and press
  <strong>Create runtime directories</strong> — it creates the whole tree for you,
  parents included. Nothing to make by hand.</p>
  <pre>STORAGE_PATH=<?= htmlspecialchars($suggested) ?>/storage
SECRETS_PATH=<?= htmlspecialchars($suggested) ?>/secrets</pre>
  <p>That path is worked out from where this file actually sits: outside the
  repository so a deploy cannot delete it, above the document root so it is not
  web-reachable<?= $openBasedir !== '' ? ', and inside this host&rsquo;s open_basedir' : '' ?>.</p>
  <p>Do this <em>before</em> generating the encryption key. Generating it first and
  moving it afterwards is exactly the sequence that loses it.</p>
</div>
<?php endif; ?>

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
    <td class="<?= $good === true ? 'ok' : ($good === 'pending' ? 'warn' : 'bad') ?>">
      <?= htmlspecialchars($value) ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<p class="sub" style="font-size:13px">
  Amber means “not done yet”, not “broken”. The buttons below create these.
</p>

<h2>What .env actually says</h2>
<p class="sub" style="font-size:13px">
  If a folder keeps appearing in the wrong place, the cause is almost always
  here: the edit went to a different file, or was not saved. These are the raw
  values this page just read.
</p>
<table>
  <?php
  $envPath = $root . '/.env';
  ?>
  <tr>
    <td>File read</td>
    <td>
      <code><?= htmlspecialchars($envPath) ?></code><br>
      <small class="<?= is_file($envPath) ? 'ok' : 'bad' ?>">
        <?php if (is_file($envPath)): ?>
          last saved <?= htmlspecialchars(date('Y-m-d H:i:s', (int) filemtime($envPath))) ?>
          (server time) — <?= number_format((int) filesize($envPath)) ?> bytes
        <?php else: ?>
          NOT FOUND — this is the only .env this page reads
        <?php endif; ?>
      </small>
    </td>
  </tr>
  <?php foreach (['STORAGE_PATH', 'SECRETS_PATH'] as $var):
      $raw = Env::get($var); ?>
  <tr>
    <td><?= $var ?></td>
    <td>
      <?php if ($raw === null): ?>
        <span class="warn">not set — using the default, which is the correct location for this server</span>
      <?php else: ?>
        <code><?= htmlspecialchars($raw) ?></code>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<p class="sub" style="font-size:13px">
  Those two values are used verbatim. Whatever they say is where the folders get
  created — so if they are not what you typed, the edit did not reach this file.
</p>

<h2>Where things are on disk</h2>
<p class="sub" style="font-size:13px">
  Absolute paths as PHP resolved them. If a folder looks missing in File Manager,
  it is almost always because it was created somewhere other than where you are
  looking — navigate to the exact path below.
</p>
<table>
  <?php
  $keyPath = Config::get('secrets.master_key_file');
  $rows = [
      'Repository root'   => $root,
      'Document root'     => (string) ($_SERVER['DOCUMENT_ROOT'] ?? 'unknown'),
      'This file'         => __FILE__,
      '.env being used'   => $root . '/.env',
      'Storage path'      => $storageDir,
      'Secrets path'      => $secretsDir,
      'Encryption key'    => $keyPath,
  ];
  foreach ($rows as $label => $path):
      $real   = is_dir($path) || is_file($path) ? realpath($path) : false;
      $exists = $real !== false;
  ?>
  <tr>
    <td><?= htmlspecialchars($label) ?></td>
    <td>
      <code><?= htmlspecialchars($exists ? $real : $path) ?></code><br>
      <small class="<?= $exists ? 'ok' : 'warn' ?>">
        <?php if ($exists && is_file($path)): ?>
          exists — <?= number_format(filesize($path)) ?> bytes
        <?php elseif ($exists): ?>
          exists<?= is_writable($path) ? ', writable' : ', NOT writable' ?>
        <?php else: ?>
          does not exist yet
        <?php endif; ?>
      </small>
    </td>
  </tr>
  <?php endforeach; ?>
</table>

<?php
// Listing the secrets directory answers "the folder is not visible" directly:
// either the files are here, or they were written somewhere else entirely.
if (is_dir($secretsDir)):
    $entries = array_values(array_diff(scandir($secretsDir) ?: [], ['.', '..']));
?>
<p><strong>Contents of <code><?= htmlspecialchars((string) realpath($secretsDir)) ?></code>:</strong>
<?php if ($entries === []): ?>
  <em>empty</em> — the folder exists but nothing has been written into it yet.
<?php else: ?>
  <?= htmlspecialchars(implode(', ', $entries)) ?>
<?php endif; ?>
</p>
<?php endif; ?>

<?php if (is_dir($storageDir)):
    $entries = array_values(array_diff(scandir($storageDir) ?: [], ['.', '..']));
?>
<p><strong>Contents of <code><?= htmlspecialchars((string) realpath($storageDir)) ?></code>:</strong>
<?= $entries === [] ? '<em>empty</em>' : htmlspecialchars(implode(', ', $entries)) ?>
</p>
<?php endif; ?>

<h2>Actions — run in order</h2>

<p><strong>Step 1.</strong> Put the two <code>STORAGE_PATH</code> /
<code>SECRETS_PATH</code> lines shown above into <code>.env</code>, then reload
this page. Do this first: it decides <em>where</em> the next two steps write, and
the encryption key must land somewhere a deploy cannot delete.</p>

<form method="post" action="setup.php<?= htmlspecialchars($tokenQs) ?>">
  <input type="hidden" name="token" value="<?= htmlspecialchars($supplied) ?>">

  <p><strong>Step 2.</strong> Creates <code>storage/</code> and
  <code>secrets/</code> and everything under them, parents included. Neither
  folder exists until you press this.<br>
  <button name="action" value="dirs">Create runtime directories</button></p>

  <p><strong>Step 3.</strong> Writes <code>master.key</code> into
  <code>secrets/</code>. Refuses to overwrite an existing key, because
  replacing it makes every stored Shopify token permanently unreadable.<br>
  <button name="action" value="keygen">Generate encryption key</button></p>

  <p><strong>Step 4.</strong> Applies the database schema. Preview first if you
  want to see what it would do without writing anything.<br>
  <button name="action" value="migrate_dry">Preview migrations</button>
  <button name="action" value="migrate">Apply migrations</button></p>

  <p><strong>Step 5.</strong> Downloads the IP-to-location database (about 60&nbsp;MB)
  straight to the server — nothing to sign up for and nothing to upload. It
  turns visitor IP addresses into “Chennai, Tamil Nadu” for the Geography tab.
  The IP itself is never stored. Not needed until the first import runs, and
  it may take a minute.<br>
  <button name="action" value="geoip">Download geo database</button></p>
</form>

<form method="post" action="setup.php<?= htmlspecialchars($tokenQs) ?>">
  <input type="hidden" name="token" value="<?= htmlspecialchars($supplied) ?>">
  <p><strong>Step 6.</strong> Create the first staff account. Nothing can sign in to the
  console until this exists, and it cannot be done from the console itself for the same
  reason.<br>
  <?php
  $staffCount = 0;
  try {
      $staffCount = (int) Db::core()->query('SELECT COUNT(*) FROM staff_users')->fetchColumn();
  } catch (Throwable) {
      // Table not migrated yet; Step 4 comes first.
  }
  ?>
  <?php if ($staffCount > 0): ?>
    <span class="ok"><?= $staffCount ?> account(s) already exist.</span>
    Creating another is fine.
  <?php endif; ?>
  </p>
  <p>
    <input type="email" name="email" placeholder="you@digifyce.com" required
           style="font:inherit;padding:8px 10px;border:1px solid #8886;border-radius:6px;width:250px">
    <input type="text" name="name" placeholder="Your name"
           style="font:inherit;padding:8px 10px;border:1px solid #8886;border-radius:6px;width:170px">
    <input type="password" name="password" placeholder="Password (12+ characters)" required
           minlength="12"
           style="font:inherit;padding:8px 10px;border:1px solid #8886;border-radius:6px;width:230px">
    <button name="action" value="staff">Create staff account</button>
  </p>
  <p class="sub" style="font-size:13px">This account can read every connected store's revenue
  and customer data, so the 12-character minimum is enforced rather than suggested.</p>
</form>

<h2>When you are finished</h2>
<ol>
  <li>Download <code>secrets/master.key</code> and store it offline. It is not in
      git; losing it means re-onboarding every connected store.</li>
  <li>Run Step 5 to fetch the geo database, if you have not already. Only
      needed before the first import.</li>
  <li>Remove <code>SETUP_TOKEN</code> from <code>.env</code>. This page then
      refuses to do anything at all.</li>
</ol>
