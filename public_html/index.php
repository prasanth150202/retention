<?php
/**
 * retention-dashboard — front controller.
 *
 * Serves two audiences from one entry point:
 *
 *   MERCHANTS arrive from the Shopify admin. The app is not embedded, so
 *   Shopify navigates here with a signed query string; verifying it proves who
 *   they are and which store they own. They see exactly one store.
 *
 *   DIGIFYCE STAFF sign in with a password and see every store.
 *
 * The two session types are deliberately separate (Merchant vs Auth).
 * Conflating them is how a merchant ends up looking at someone else's revenue.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/lib/bootstrap.php';
require_once $root . '/app/lib/Auth.php';
require_once $root . '/app/lib/Merchant.php';
require_once $root . '/app/lib/Snippet.php';

try {
    odysseus_boot();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Retention Dashboard</title>';
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
$rawQs = (string) ($_SERVER['QUERY_STRING'] ?? '');

// ---------------------------------------------------------------------
// A signed request from Shopify takes precedence over any existing session:
// it is the merchant telling us, with proof, which store they are looking at.
// Without this, a staff member and a merchant sharing a browser would see
// whichever session was created last.
// ---------------------------------------------------------------------
if (isset($_GET['shop'], $_GET['hmac'])) {
    $resolved = Merchant::fromSignedRequest($_GET, $rawQs);

    if ($resolved !== null) {
        // Strip the signed parameters from the URL. They are a bearer
        // credential and should not sit in history, bookmarks or a Referer.
        header('Location: /');
        exit;
    }

    // Signed but unknown, or unsigned. Either way the fix is the same: send
    // them through installation. Under managed installation an
    // already-installed store passes straight through without a second
    // consent screen.
    if (ShopifyOAuth::configured()) {
        header('Location: ' . Merchant::loginRedirect((string) $_GET['shop']));
        exit;
    }
}

// A bare ?shop= with no signature is how the App Store sends a merchant to
// install for the first time.
if (isset($_GET['shop']) && !Merchant::check() && ShopifyOAuth::configured() && $page === '') {
    header('Location: ' . Merchant::loginRedirect((string) $_GET['shop']));
    exit;
}

// ---------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------
switch ($page) {

    case 'logout':
        Merchant::logout();
        Auth::logout();
        header('Location: /');
        exit;

    case 'no-shop':
        render('Retention Dashboard', 'none', static function (): void {
            require dirname(__DIR__) . '/app/views/landing.php';
        });
        break;

    // --- Staff -------------------------------------------------------
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
            $flash = ['err', 'Those details were not recognised.'];
        }
        render('Sign in', 'login', static function () use ($flash): void {
            require dirname(__DIR__) . '/app/views/login.php';
        });
        break;

    case 'stores':
        Auth::require();
        $stores = Db::core()->query(
            "SELECT tenant_id, shop_domain, display_name, status, installed_at,
                    backfill_state, admin_token_enc IS NOT NULL AS connected
               FROM tenants ORDER BY installed_at DESC"
        )->fetchAll();

        render('Stores', 'stores', static function () use ($stores, $flash): void {
            require dirname(__DIR__) . '/app/views/stores.php';
        });
        break;

    case 'store':
        Auth::require();
        $tenant = Tenant::find((int) ($_GET['id'] ?? 0));
        if (!$tenant) {
            header('Location: /?p=stores');
            exit;
        }
        $stats = storeStats((int) $tenant['tenant_id']);

        render((string) $tenant['display_name'], 'stores', static function () use ($tenant, $stats): void {
            require dirname(__DIR__) . '/app/views/store.php';
        });
        break;

    // --- Merchant ----------------------------------------------------
    default:
        if (Merchant::check()) {
            $tenant = Merchant::tenant();

            if (!$tenant || $tenant['status'] === 'uninstalled') {
                Merchant::logout();
                header('Location: /?p=no-shop');
                exit;
            }

            $stats = storeStats((int) $tenant['tenant_id']);

            render((string) $tenant['display_name'], 'dashboard',
                static function () use ($tenant, $stats): void {
                    require dirname(__DIR__) . '/app/views/dashboard.php';
                });
            break;
        }

        if (Auth::check()) {
            header('Location: /?p=stores');
            exit;
        }

        render('Retention Dashboard', 'none', static function (): void {
            require dirname(__DIR__) . '/app/views/landing.php';
        });
        break;
}


// =====================================================================

function render(string $title, string $nav, callable $content): void
{
    require dirname(__DIR__) . '/app/views/layout.php';
}

/**
 * Is data actually arriving?
 *
 * The question that immediately follows an install, and one that is otherwise
 * answered by staring at an empty dashboard unable to tell a broken setup from
 * a quiet afternoon.
 *
 * @return array{events:int,last_event:?string,visitors:int,spool:int,orders:int,revenue_minor:int}
 */
function storeStats(int $tenantId): array
{
    $out = [
        'events' => 0, 'last_event' => null, 'visitors' => 0,
        'spool' => 0, 'orders' => 0, 'revenue_minor' => 0,
    ];

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

    try {
        $stmt = Db::tenantQuery(
            Db::core(),
            'SELECT COUNT(*) AS n, COALESCE(SUM(total_minor), 0) AS rev
               FROM orders WHERE tenant_id = :tenant_id AND cancelled_at IS NULL',
            $tenantId
        );
        $row = $stmt->fetch();

        $out['orders']        = (int) ($row['n'] ?? 0);
        $out['revenue_minor'] = (int) ($row['rev'] ?? 0);
    } catch (Throwable) {
    }

    // Events accepted but not yet imported — the difference between "nothing
    // is arriving" and "it is arriving and waiting".
    foreach (glob(Config::get('paths.spool') . '/' . $tenantId . '/*.ndjson') ?: [] as $f) {
        $out['spool'] += max(0, (int) @filesize($f));
    }

    return $out;
}
