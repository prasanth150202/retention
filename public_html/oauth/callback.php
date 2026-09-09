<?php
/**
 * Shopify OAuth redirect target.
 *
 * This URL must match the "Allowed redirection URL" in the Partner Dashboard
 * app configuration exactly — scheme, host and path. Shopify refuses the
 * install otherwise, and the error it shows does not say which part differs.
 *
 * Deliberately outside the console's authentication: the merchant approving
 * the app is not a Digifyce staff member and has no session here. Security
 * comes from the state nonce and the HMAC, both verified in ShopifyOAuth.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/lib/bootstrap.php';
require_once $root . '/app/lib/ShopifyOAuth.php';

odysseus_boot();

function page(string $title, string $body, int $status = 200): never
{
    http_response_code($status);
    echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex">';
    echo '<title>' . htmlspecialchars($title) . ' — Odysseus</title>';
    echo '<style>:root{color-scheme:light dark}body{font:15px/1.6 system-ui,sans-serif;'
       . 'max-width:620px;margin:12vh auto;padding:0 24px}h1{font-size:20px;margin-bottom:6px}'
       . 'code{background:#8881;padding:2px 6px;border-radius:4px}'
       . '.ok{color:#157f3b}.bad{color:#b3241e}.warn{color:#8a6100}'
       . 'a.btn{display:inline-block;margin-top:18px;padding:9px 16px;border:1px solid #8886;'
       . 'border-radius:7px;text-decoration:none;color:inherit}</style>';
    echo '<h1>' . htmlspecialchars($title) . '</h1>' . $body;
    exit;
}

if (isset($_GET['error'])) {
    // The merchant declined, or Shopify refused. Not our failure.
    page('Install cancelled',
        '<p>Shopify reported: <code>' . htmlspecialchars((string) $_GET['error']) . '</code></p>'
        . '<p>Nothing was changed. You can start the connection again from the console.</p>'
        . '<a class="btn" href="/?p=stores">Back to stores</a>');
}

try {
    $result = ShopifyOAuth::completeInstall($_GET, (string) ($_SERVER['QUERY_STRING'] ?? ''));
} catch (Throwable $e) {
    page('Connection failed',
        '<p class="bad">' . htmlspecialchars($e->getMessage()) . '</p>'
        . '<p>No token was stored.</p>'
        . '<a class="btn" href="/?p=stores">Back to stores</a>', 400);
}

// ---------------------------------------------------------------------
// Store the token against the tenant, creating it if this shop is new.
// ---------------------------------------------------------------------
$pdo  = Db::core();
$stmt = $pdo->prepare('SELECT tenant_id FROM tenants WHERE shop_domain = ?');
$stmt->execute([$result['shop']]);
$tenantId = $stmt->fetchColumn();

$encrypted = Crypto::encrypt($result['token']);

if ($tenantId === false) {
    require_once $root . '/app/lib/Snippet.php';

    $pdo->prepare(
        "INSERT INTO tenants
            (shop_domain, display_name, admin_token_enc, token_scopes, write_key,
             pii_salt_ref, currency, iana_timezone, status, installed_at)
         VALUES (?, ?, ?, ?, ?, 'tenant', 'INR', 'Asia/Kolkata', 'active', UTC_TIMESTAMP())"
    )->execute([
        $result['shop'],
        explode('.', $result['shop'])[0],
        $encrypted,
        $result['scopes'],
        Snippet::generateWriteKey(),
    ]);

    $tenantId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT IGNORE INTO channel_rules
            (tenant_id, priority, match_field, match_op, match_value, channel, enabled)
         SELECT ?, priority, match_field, match_op, match_value, channel, enabled
           FROM channel_rules WHERE tenant_id = 0'
    )->execute([$tenantId]);
} else {
    $tenantId = (int) $tenantId;
    $pdo->prepare(
        "UPDATE tenants
            SET admin_token_enc = ?, token_scopes = ?, status = 'active',
                backfill_state = 'pending'
          WHERE tenant_id = ?"
    )->execute([$encrypted, $result['scopes'], $tenantId]);
}

// Queue the historical backfill.
$pdo->prepare(
    "INSERT INTO sync_cursors (tenant_id, resource, last_run_at)
     VALUES (?, 'orders', NULL)
     ON DUPLICATE KEY UPDATE resource = resource"
)->execute([$tenantId]);

$scopes  = ShopifyOAuth::verifyScopes($result['scopes']);
$warning = '';

if ($scopes['history_limited']) {
    // Worth stopping on. The install "succeeded", and every retention metric
    // will be empty for reasons nobody would connect to this moment.
    $warning =
        '<p class="warn"><strong>read_all_orders was not granted.</strong> The Admin API will '
      . 'return only the last 60 days of orders, so retention, cohorts and lifetime value will '
      . 'stay empty regardless of how much history the store has.</p>'
      . '<p>Request it in the Partner Dashboard under App setup, then reconnect this store. '
      . 'Behavioural tracking is unaffected — the pixel does not depend on it.</p>';
} elseif ($scopes['missing'] !== []) {
    $warning = '<p class="warn">Not granted: <code>'
             . htmlspecialchars(implode(', ', $scopes['missing'])) . '</code></p>';
}

page('Store connected',
    '<p class="ok">' . htmlspecialchars($result['shop']) . ' is connected.</p>'
    . $warning
    . '<p>Order history will begin syncing on the next scheduled run.</p>'
    . '<a class="btn" href="/?p=store&id=' . $tenantId . '">Open store</a>');
