<?php
/**
 * Shopify OAuth callback — where an install completes.
 *
 * Must match the Allowed redirection URL in the Partner Dashboard exactly.
 * Shopify refuses the install otherwise and does not say which part differs.
 *
 * Deliberately outside the console's authentication: the merchant installing
 * the app is not a Digifyce staff member and has no session here. Security
 * comes from the state nonce and the HMAC, both verified in ShopifyOAuth.
 *
 * By the time this returns, the store is connected AND tracking. The merchant
 * has done nothing but press Install.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/lib/bootstrap.php';
require_once $root . '/app/lib/Merchant.php';

odysseus_boot();

function page(string $title, string $body, int $status = 200): never
{
    http_response_code($status);
    echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . htmlspecialchars($title) . ' — Retention Dashboard</title>';
    echo '<style>:root{color-scheme:light dark}body{font:15px/1.6 system-ui,sans-serif;'
       . 'max-width:620px;margin:12vh auto;padding:0 24px}h1{font-size:20px;margin-bottom:6px}'
       . 'code{background:#8881;padding:2px 6px;border-radius:4px}'
       . '.ok{color:#157f3b}.bad{color:#b3241e}.warn{color:#8a6100}'
       . 'a.btn{display:inline-block;margin-top:18px;padding:10px 18px;border:1px solid #8886;'
       . 'border-radius:8px;text-decoration:none;color:inherit;font-weight:550}</style>';
    echo '<h1>' . htmlspecialchars($title) . '</h1>' . $body;
    exit;
}

// The merchant declined, or Shopify refused. Not our failure.
//
// This branch answers before anything is verified — it has to, since a
// cancelled install carries no code to verify — so the error code is a string
// from whoever made the request, not necessarily from Shopify. It is escaped,
// so there is no injection here. But quoting it back under "Shopify reported"
// would let anyone put their own sentence on our domain, attributed to
// Shopify, on the page merchants are told to expect during install. That is a
// phishing surface rather than a bug, and it costs one array to close.
//
// RFC 6749 §4.1.2.1 defines the whole set an authorization server may send.
if (isset($_GET['error'])) {
    $known = [
        'access_denied', 'invalid_request', 'unauthorized_client',
        'unsupported_response_type', 'invalid_scope', 'server_error',
        'temporarily_unavailable',
    ];
    $code = (string) $_GET['error'];

    page('Installation cancelled',
        (in_array($code, $known, true)
            ? '<p>Shopify reported: <code>' . htmlspecialchars($code) . '</code></p>'
            : '<p>The installation did not complete.</p>')
        . '<p>Nothing was changed and no data was collected.</p>');
}

try {
    $result = ShopifyOAuth::completeInstall($_GET, (string) ($_SERVER['QUERY_STRING'] ?? ''));
} catch (Throwable $e) {
    page('Installation failed',
        '<p class="bad">' . htmlspecialchars($e->getMessage()) . '</p>'
        . '<p>No access token was stored.</p>', 400);
}

// ---------------------------------------------------------------------
// Store the tokens. A reinstall keeps its existing tenant_id and history.
// ---------------------------------------------------------------------
$tenantId = Tenant::upsert($result['shop'], $result, $result['scope']);
$tenant   = Tenant::find($tenantId);

// ---------------------------------------------------------------------
// Activate the web pixel.
//
// The reason this app exists in this shape. No theme editing, no snippet to
// paste, no settings page — tracking starts here.
//
// A failure is reported but does not fail the install: the store is connected
// and orders will still sync. Better a working app with a fixable pixel than
// an install that appears to have failed entirely.
// ---------------------------------------------------------------------
$pixelNote = '';

try {
    $api    = ShopifyApi::forTenant($tenantId);
    $pixel  = $api->upsertWebPixel(['writeKey' => (string) $tenant['write_key']]);
    $status = $pixel['action'];

    Db::core()->prepare(
        "INSERT INTO job_runs (job_name, tenant_id, started_at, finished_at, status, message)
         VALUES ('web_pixel', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'ok', ?)"
    )->execute([$tenantId, "Web pixel {$status}: {$pixel['id']}"]);

    $pixelNote = '<p class="ok">Tracking is active. Nothing further to install.</p>';
} catch (Throwable $e) {
    Db::core()->prepare(
        "INSERT INTO job_runs (job_name, tenant_id, started_at, finished_at, status, message)
         VALUES ('web_pixel', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'failed', ?)"
    )->execute([$tenantId, substr($e->getMessage(), 0, 2000)]);

    $pixelNote =
        '<p class="warn">The store is connected, but the tracking pixel could not be '
      . 'activated automatically. Order data will still sync. '
      . '<br><small>' . htmlspecialchars($e->getMessage()) . '</small></p>';
}

// Queue the historical order backfill.
Db::core()->prepare(
    "INSERT INTO sync_cursors (tenant_id, resource, last_run_at)
     VALUES (?, 'orders', NULL)
     ON DUPLICATE KEY UPDATE resource = resource"
)->execute([$tenantId]);

// ---------------------------------------------------------------------
// read_all_orders is the one scope whose absence is silent: the install
// succeeds, the API quietly returns 60 days, and every cohort chart comes out
// empty months later with nothing pointing back here.
// ---------------------------------------------------------------------
$scopes  = ShopifyOAuth::verifyScopes($result['scope']);
$warning = '';

if ($scopes['history_limited']) {
    $warning =
        '<p class="warn"><strong>Limited order history.</strong> This app was not granted '
      . 'access to orders older than 60 days, so retention and repeat-purchase figures will '
      . 'be incomplete until that is approved. Everything else works normally.</p>';
}

// The write key exists as of now, so put it where the ingest endpoint can see
// it. Without this the first events from a store installed in the minute after
// an import run are rejected, and sendBeacon does not retry.
Tenant::refreshWriteKeyCache();

// Sign the merchant in so they land on their dashboard rather than a login.
Merchant::startSession($tenantId, $result['shop']);

page('Connected',
    '<p class="ok">' . htmlspecialchars($result['shop']) . ' is connected.</p>'
    . $pixelNote
    . $warning
    . '<p>Order history is syncing in the background. Behavioural data appears as '
    . 'visitors browse the store.</p>'
    . '<a class="btn" href="/">Open the dashboard</a>');
