<?php
/**
 * Project Odysseus — staff console.
 *
 * A small front controller. The analytics dashboard proper arrives in M4;
 * what exists now is what M1 needs to be usable: sign in, connect a store,
 * and copy the two snippets that start data flowing.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/lib/bootstrap.php';
require_once $root . '/app/lib/Auth.php';
require_once $root . '/app/lib/Snippet.php';

try {
    odysseus_boot();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Odysseus</title>';
    echo '<p style="font:15px system-ui;max-width:640px;margin:60px auto;padding:0 24px">';
    echo 'Configuration error. Check that <code>.env</code> exists at the repository root.';
    if (Env::bool('APP_DEBUG', false)) {
        echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    }
    echo '</p>';
    exit;
}

$page  = (string) ($_GET['p'] ?? '');
$flash = null;

// ---------------------------------------------------------------------
// No staff account exists yet — point at setup rather than a login form
// nobody can pass.
// ---------------------------------------------------------------------
try {
    if (Auth::userCount() === 0) {
        render('Set up', 'setup', static function (): void { ?>
            <h1>No staff account yet</h1>
            <p class="sub">Odysseus is installed but has nobody to sign in as.</p>
            <div class="panel">
              <p>Open the setup page and use <strong>Create staff account</strong>:</p>
              <pre>/setup.php?token=&lt;your SETUP_TOKEN&gt;</pre>
              <p class="muted">SETUP_TOKEN lives in <code>.env</code>. Remove that line once
              setup is finished and the page turns itself off.</p>
            </div>
        <?php });
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo '<p style="font:15px system-ui;max-width:640px;margin:60px auto;padding:0 24px">'
       . 'Cannot reach the database. Run the migrations from <code>/setup.php</code>.</p>';
    exit;
}

// ---------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------
switch ($page) {

    case 'logout':
        Auth::logout();
        header('Location: /?p=login');
        exit;

    case 'login':
        if (Auth::check()) {
            header('Location: /?p=stores');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Auth::csrfCheck();
            if (Auth::login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                header('Location: /?p=stores');
                exit;
            }
            // Identical message either way: saying which half was wrong tells
            // an attacker which addresses are real.
            $flash = ['err', 'Those details were not recognised.'];
        }

        render('Sign in', 'login', static function () use ($flash): void {
            require dirname(__DIR__) . '/app/views/login.php';
        });
        break;

    case 'store':
        Auth::require();
        $id = (int) ($_GET['id'] ?? 0);

        $stmt = Db::core()->prepare(
            'SELECT tenant_id, shop_domain, custom_domain, display_name, write_key,
                    status, installed_at, currency, iana_timezone,
                    admin_token_enc, token_scopes
               FROM tenants WHERE tenant_id = ?'
        );
        $stmt->execute([$id]);
        $tenant = $stmt->fetch();

        if (!$tenant) {
            http_response_code(404);
            header('Location: /?p=stores');
            exit;
        }

        $stats = storeStats($id);

        render($tenant['display_name'], 'stores', static function () use ($tenant, $stats): void {
            require dirname(__DIR__) . '/app/views/store.php';
        });
        break;

    case 'connect':
        Auth::require();
        require_once $root . '/app/lib/ShopifyOAuth.php';

        $id   = (int) ($_GET['id'] ?? 0);
        $stmt = Db::core()->prepare(
            'SELECT tenant_id, shop_domain, display_name, token_scopes,
                    admin_token_enc IS NOT NULL AS connected
               FROM tenants WHERE tenant_id = ?'
        );
        $stmt->execute([$id]);
        $tenant = $stmt->fetch();

        if (!$tenant) {
            header('Location: /?p=stores');
            exit;
        }

        $installUrl = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Auth::csrfCheck();
            try {
                if (!Crypto::available()) {
                    throw new RuntimeException(
                        'No encryption key. Generate one from /setup.php before connecting a '
                        . 'store — the Shopify token cannot be stored safely without it.'
                    );
                }
                $installUrl = ShopifyOAuth::beginInstall(
                    (string) $tenant['shop_domain'],
                    trim((string) ($_POST['client_id'] ?? '')),
                    trim((string) ($_POST['client_secret'] ?? ''))
                );
            } catch (Throwable $e) {
                $flash = ['err', $e->getMessage()];
            }
        }

        render('Connect ' . $tenant['display_name'], 'stores',
            static function () use ($tenant, $installUrl, $flash): void {
                require dirname(__DIR__) . '/app/views/connect.php';
            });
        break;

    case 'stores':
    default:
        Auth::require();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_store') {
            Auth::csrfCheck();
            try {
                $id = addStore(
                    (string) ($_POST['shop_domain'] ?? ''),
                    (string) ($_POST['custom_domain'] ?? ''),
                    (string) ($_POST['display_name'] ?? '')
                );
                header('Location: /?p=store&id=' . $id . '&new=1');
                exit;
            } catch (Throwable $e) {
                $flash = ['err', $e->getMessage()];
            }
        }

        $stores = Db::core()->query(
            'SELECT t.tenant_id, t.shop_domain, t.display_name, t.status, t.installed_at,
                    t.backfill_state
               FROM tenants t ORDER BY t.installed_at DESC'
        )->fetchAll();

        render('Stores', 'stores', static function () use ($stores, $flash): void {
            require dirname(__DIR__) . '/app/views/stores.php';
        });
        break;
}


// =====================================================================

function render(string $title, string $nav, callable $content): void
{
    require dirname(__DIR__) . '/app/views/layout.php';
}

/**
 * Connect a store.
 *
 * Creates the tenant row and its write key. It does NOT need an Admin API
 * token: the pixel works without one. Orders and customers arrive later,
 * once OAuth is connected in M2, and there is no reason to make behavioural
 * tracking wait for that.
 */
function addStore(string $shopDomain, string $customDomain, string $displayName): int
{
    $shop = strtolower(trim($shopDomain));
    $shop = preg_replace('~^https?://~', '', $shop) ?? $shop;
    $shop = rtrim((string) $shop, '/');

    if (!str_contains($shop, '.')) {
        $shop .= '.myshopify.com';
    }
    if (!preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', $shop)) {
        throw new InvalidArgumentException(
            "'{$shopDomain}' is not a valid myshopify.com domain. Use the store's permanent "
            . 'address (Settings -> Domains -> "myshopify.com domain"), not its custom domain — '
            . 'checkout always runs on the myshopify.com one.'
        );
    }

    $custom = strtolower(trim($customDomain));
    $custom = preg_replace('~^https?://~', '', $custom) ?? $custom;
    $custom = rtrim((string) $custom, '/') ?: null;

    $pdo  = Db::core();
    $chk  = $pdo->prepare('SELECT tenant_id FROM tenants WHERE shop_domain = ?');
    $chk->execute([$shop]);
    if ($chk->fetch()) {
        throw new RuntimeException("{$shop} is already connected.");
    }

    $pdo->prepare(
        "INSERT INTO tenants
            (shop_domain, custom_domain, display_name, write_key, pii_salt_ref,
             currency, iana_timezone, status, installed_at)
         VALUES (?, ?, ?, ?, ?, 'INR', 'Asia/Kolkata', 'active', UTC_TIMESTAMP())"
    )->execute([
        $shop,
        $custom,
        trim($displayName) !== '' ? trim($displayName) : explode('.', $shop)[0],
        Snippet::generateWriteKey(),
        'tenant',
    ]);

    $id = (int) $pdo->lastInsertId();

    // Seed this tenant's channel rules from the shared defaults (tenant 0), so
    // attribution works immediately and stays editable per store.
    $pdo->prepare(
        'INSERT IGNORE INTO channel_rules
            (tenant_id, priority, match_field, match_op, match_value, channel, enabled)
         SELECT ?, priority, match_field, match_op, match_value, channel, enabled
           FROM channel_rules WHERE tenant_id = 0'
    )->execute([$id]);

    refreshTenantCacheFile();

    return $id;
}

/** Keep c.php's lookup file current the moment a store is added. */
function refreshTenantCacheFile(): void
{
    $map = [];

    foreach (Db::core()->query(
        "SELECT tenant_id, write_key, shop_domain, custom_domain
           FROM tenants WHERE status = 'active'"
    )->fetchAll() as $r) {
        $map[(string) $r['write_key']] = [
            'id'      => (int) $r['tenant_id'],
            'domains' => array_values(array_filter([
                strtolower((string) $r['shop_domain']),
                strtolower((string) ($r['custom_domain'] ?? '')),
            ])),
        ];
    }

    $file = Config::get('paths.storage') . '/tenants.php';
    $tmp  = $file . '.' . getmypid() . '.tmp';

    if (@file_put_contents($tmp, '<?php return ' . var_export($map, true) . ";\n", LOCK_EX) !== false) {
        @rename($tmp, $file);
    }
}

/**
 * Is data actually arriving?
 *
 * The question anyone asks straight after installing a snippet, and the one
 * that is otherwise answered by staring at an empty dashboard wondering
 * whether it is broken or just quiet.
 *
 * @return array{events:int,last_event:?string,spool:int,visitors:int}
 */
function storeStats(int $tenantId): array
{
    $out = ['events' => 0, 'last_event' => null, 'spool' => 0, 'visitors' => 0];

    try {
        $pdo  = Shard::connectionForDate(gmdate('Y-m-d'));
        $stmt = Db::tenantQuery(
            $pdo,
            'SELECT COUNT(*) AS n, MAX(occurred_at) AS last, COUNT(DISTINCT visitor_key) AS v
               FROM events WHERE tenant_id = :tenant_id',
            $tenantId
        );
        $row = $stmt->fetch();

        $out['events']     = (int) ($row['n'] ?? 0);
        $out['last_event'] = $row['last'] ?? null;
        $out['visitors']   = (int) ($row['v'] ?? 0);
    } catch (Throwable) {
        // No shard yet, or unreachable. Not worth failing the page over.
    }

    // Events that have arrived but are not yet imported — the difference
    // between "nothing is coming in" and "it is coming in and waiting".
    $dir = Config::get('paths.spool') . '/' . $tenantId;
    foreach (glob($dir . '/*.ndjson') ?: [] as $f) {
        $out['spool'] += max(0, (int) @filesize($f));
    }

    return $out;
}
