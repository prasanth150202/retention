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
require_once $root . '/app/lib/Fmt.php';

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

    // --- Billing -----------------------------------------------------
    case 'plan':
        merchantPage('plan');
        break;

    case 'billing-return':
        // Where Shopify sends the merchant after they approve or decline a
        // charge. It carries no proof of the outcome, so nothing here trusts
        // it — it is a prompt to go and ask Shopify what happened.
        if (!Merchant::check()) {
            header('Location: /?p=no-shop');
            exit;
        }

        try {
            Billing::syncFromShopify(Merchant::tenantId() ?? 0);
        } catch (Throwable $e) {
            error_log('billing return sync failed: ' . $e->getMessage());
        }

        header('Location: ' . (Billing::hasAccess(Merchant::tenantId() ?? 0) ? '/' : '/?p=plan'));
        exit;

    // --- Merchant analytics ------------------------------------------
    case 'funnel':
    case 'campaigns':
    case 'products':
    case 'retention':
    case 'checkout':
    case 'geography':
        merchantPage($page);
        break;

    // --- Merchant ----------------------------------------------------
    default:
        if (Merchant::check()) {
            merchantPage('');
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

/**
 * Render one analytics tab for the signed-in merchant.
 *
 * Every tab goes through here, so the session check and the store lookup
 * happen in exactly one place. A tab that forgot them would be a tab showing
 * one merchant another's revenue.
 *
 * Each tab loads only what it displays. The overview does not pay for the
 * cohort queries and the retention tab does not pay for the daily series.
 */
function merchantPage(string $page): void
{
    if (!Merchant::check()) {
        header('Location: /?p=no-shop');
        exit;
    }

    $tenant = Merchant::tenant();

    if (!$tenant || $tenant['status'] === 'uninstalled') {
        Merchant::logout();
        header('Location: /?p=no-shop');
        exit;
    }

    $tenantId = (int) $tenant['tenant_id'];
    $billing  = Billing::state($tenantId);

    // Billing gates the dashboard and nothing else. Tracking, syncing and
    // rollups carry on for a lapsed store: data is cheap and a gap in history
    // is permanent, so a merchant who subscribes two weeks late should find
    // those two weeks waiting rather than missing.
    if (!$billing['access'] && $page !== 'plan') {
        header('Location: /?p=plan');
        exit;
    }

    if ($page === 'plan') {
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Merchant::csrfCheck();

            try {
                $started = Billing::subscribe(
                    $tenantId,
                    (string) ($_POST['plan'] ?? ''),
                    appBaseUrl() . '/?p=billing-return'
                );

                // Only Shopify can take the approval, so the merchant goes to
                // Shopify. Nothing about this should be disguised.
                header('Location: ' . $started['confirm_url']);
                exit;
            } catch (Throwable $e) {
                error_log('subscribe failed: ' . $e->getMessage());
                $error = 'That could not be started just now. '
                       . 'Nothing has been charged — please try again in a moment.';
            }

            $billing = Billing::state($tenantId, false);
        }

        render('Plan', 'plan', static function () use ($tenant, $billing, $error, $tenantId): void {
            $plans   = Billing::plans();
            $history = Billing::history($tenantId);
            require dirname(__DIR__) . '/app/views/dash/plan.php';
        });

        return;
    }

    $stats    = storeStats($tenantId);
    $hasData  = Report::hasData($tenantId);
    $range    = Report::range($tenantId, $_GET['from'] ?? null, $_GET['to'] ?? null);
    $view     = $page === '' ? 'overview' : $page;
    $nav      = $page === '' ? 'dashboard' : $page;

    $titles = [
        'overview'   => (string) $tenant['display_name'],
        'funnel'     => 'Funnel',
        'campaigns'  => 'Campaigns',
        'products'   => 'Products',
        'retention'  => 'Retention',
        'checkout'   => 'Checkout',
        'geography'  => 'Geography',
    ];

    $data = [];

    switch ($view) {
        case 'funnel':
            $data['funnel'] = Report::funnel($tenantId, $range);
            break;

        case 'campaigns':
            $model = (string) ($_GET['model'] ?? Report::DEFAULT_MODEL);
            $model = in_array($model, Report::MODELS, true) ? $model : Report::DEFAULT_MODEL;

            $data['model']             = $model;
            $data['campaigns']         = Report::campaigns($tenantId, $range, $model);
            $data['channels']          = Report::channels($tenantId, $range, $model);
            $data['comparison']        = Report::modelComparison($tenantId, $range);
            $data['campaignRetention'] = Report::campaignRetention($tenantId);
            break;

        case 'products':
            $data['products'] = Report::products($tenantId, $range);
            break;

        case 'retention':
            $data['retention'] = Report::retention($tenantId);
            break;

        case 'checkout':
            $data['abandon'] = Report::abandonment($tenantId, $range);
            break;

        case 'geography':
            $data['geography'] = Report::geography($tenantId, $range);
            $data['devices']   = Report::devices($tenantId, $range);
            $data['landing']   = Report::landingPages($tenantId, $range);
            break;

        default:
            $view              = 'overview';
            $data['summary']   = Report::summary($tenantId, $range);
            $data['trend']     = Report::trend($tenantId, $range);
            $data['channels']  = Report::channels($tenantId, $range, Report::DEFAULT_MODEL);
            break;
    }

    render($titles[$view] ?? 'Dashboard', $nav,
        static function () use ($tenant, $stats, $range, $hasData, $data, $view): void {
            extract($data, EXTR_SKIP);
            require dirname(__DIR__) . '/app/views/dash/' . $view . '.php';
        });
}

function render(string $title, string $nav, callable $content): void
{
    require dirname(__DIR__) . '/app/views/layout.php';
}

/**
 * The app's own public address.
 *
 * Configured value wins; otherwise it comes from the request. Always https —
 * Shopify rejects a plain-http return URL, and this app is only ever served
 * over TLS, so a scheme guessed from $_SERVER would be wrong more often than
 * right behind a proxy that terminates it.
 */
function appBaseUrl(): string
{
    $configured = rtrim((string) Config::get('app.url', ''), '/');

    if ($configured !== '') {
        return $configured;
    }

    return 'https://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
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
