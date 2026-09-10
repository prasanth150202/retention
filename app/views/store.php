<?php
/**
 * One store, from the staff side.
 *
 * Diagnostic rather than analytical: whether data is arriving, whether the
 * token is healthy, whether the pixel activated. The analytics a merchant
 * cares about live on their own dashboard.
 *
 * No snippets here any more. The pixel is installed by the app through
 * webPixelCreate, so there is nothing for anyone to copy or paste.
 *
 * @var array $tenant
 * @var array{events:int,last_event:?string,visitors:int,spool:int,orders:int,revenue_minor:int} $stats
 */

$scopes = ShopifyOAuth::verifyScopes((string) ($tenant['token_scopes'] ?? ''));
$hasApi = !empty($tenant['admin_token_enc']);

$tokenExpiry  = $tenant['token_expires_at'] ?? null;
$tokenSeconds = $tokenExpiry !== null ? strtotime($tokenExpiry . ' UTC') - time() : null;

$pixelStmt = Db::core()->prepare(
    "SELECT status, message, started_at FROM job_runs
      WHERE tenant_id = ? AND job_name = 'web_pixel'
      ORDER BY started_at DESC LIMIT 1"
);
$pixelStmt->execute([(int) $tenant['tenant_id']]);
$pixel = $pixelStmt->fetch();
?>

<p style="margin:0 0 6px"><a href="/?p=stores" class="muted" style="text-decoration:none">&larr; Stores</a></p>
<h1><?= htmlspecialchars($tenant['display_name']) ?></h1>
<p class="sub"><?= htmlspecialchars($tenant['shop_domain']) ?></p>

<?php if ($tenant['status'] === 'uninstalled'): ?>
  <div class="flash err">
    Uninstalled <?= htmlspecialchars(substr((string) $tenant['uninstalled_at'], 0, 16)) ?> UTC.
    <?php if (!empty($tenant['purged_at'])): ?>
      Data was purged <?= htmlspecialchars(substr((string) $tenant['purged_at'], 0, 16)) ?> UTC.
    <?php elseif (!empty($tenant['purge_after'])): ?>
      Raw events are scheduled for deletion after
      <?= htmlspecialchars(substr((string) $tenant['purge_after'], 0, 16)) ?> UTC.
    <?php endif; ?>
  </div>
<?php endif; ?>

<h2>Data</h2>
<div class="panel" style="padding:6px 4px">
  <table>
    <tr>
      <td style="width:220px"><strong>Events</strong></td>
      <td>
        <?php if ($stats['events'] > 0): ?>
          <span class="pill live"><?= number_format($stats['events']) ?></span>
          from <?= number_format($stats['visitors']) ?> visitor(s)
          <div class="hint">Last: <?= htmlspecialchars((string) $stats['last_event']) ?> UTC</div>
        <?php else: ?>
          <span class="muted">none yet</span>
        <?php endif; ?>
      </td>
    </tr>
    <tr>
      <td><strong>Waiting to import</strong></td>
      <td>
        <?php if ($stats['spool'] > 0): ?>
          <?= number_format($stats['spool'] / 1024, 1) ?> KB spooled
        <?php else: ?>
          <span class="muted">nothing queued</span>
        <?php endif; ?>
      </td>
    </tr>
    <tr>
      <td><strong>Orders</strong></td>
      <td>
        <?= number_format($stats['orders']) ?>
        <?php if ($stats['orders'] > 0): ?>
          &middot; &#8377;<?= number_format($stats['revenue_minor'] / 100, 2) ?>
        <?php endif; ?>
        <div class="hint">Backfill: <?= htmlspecialchars((string) $tenant['backfill_state']) ?></div>
      </td>
    </tr>
  </table>
</div>

<h2>Connection</h2>
<div class="panel" style="padding:6px 4px">
  <table>
    <tr>
      <td style="width:220px"><strong>Access token</strong></td>
      <td>
        <?php if (!$hasApi): ?>
          <span class="muted">none</span>
          <div class="hint">The store has uninstalled, or the token was revoked.</div>
        <?php elseif ($tokenSeconds === null): ?>
          <span class="pill live">non-expiring</span>
          <div class="hint">Connected before the public app. Public apps must move to
          expiring tokens before 1 January 2027.</div>
        <?php else: ?>
          <span class="pill live">valid</span>
          <div class="hint">
            Expires in <?= max(0, (int) round($tokenSeconds / 60)) ?> minute(s), refreshed
            automatically. Refresh window ends
            <?= htmlspecialchars(substr((string) ($tenant['refresh_expires_at'] ?? '-'), 0, 10)) ?>.
          </div>
        <?php endif; ?>
      </td>
    </tr>
    <tr>
      <td><strong>Granted scopes</strong></td>
      <td>
        <?php if ($scopes['history_limited']): ?>
          <span class="pill" style="color:var(--warn);border-color:var(--warn)">read_all_orders missing</span>
          <div class="hint">Only 60 days of orders are reachable, so retention and cohort
          figures stay incomplete. Requires a reviewed access request in the Partner
          Dashboard.</div>
        <?php elseif ($scopes['missing'] !== []): ?>
          <span class="muted">missing: <?= htmlspecialchars(implode(', ', $scopes['missing'])) ?></span>
        <?php else: ?>
          <span class="pill live">all granted</span>
        <?php endif; ?>
      </td>
    </tr>
    <tr>
      <td><strong>Write key</strong></td>
      <td>
        <code><?= htmlspecialchars((string) $tenant['write_key']) ?></code>
        <div class="hint">Public by design — it travels in the pixel and identifies which
        store an event belongs to. Events are protected by the origin check, the rate
        limit and the size cap, not by this.</div>
      </td>
    </tr>
  </table>
</div>

<h2>Pixel</h2>
<div class="panel">
  <?php if (!$pixel): ?>
    <p style="margin:0" class="muted">No pixel activation recorded yet.</p>
  <?php elseif ($pixel['status'] === 'ok'): ?>
    <p style="margin:0">
      <span class="pill live">active</span>
      <span class="muted"><?= htmlspecialchars((string) $pixel['message']) ?></span>
    </p>
    <p class="hint" style="margin-bottom:0">Installed by the app on connect. The merchant
    pasted nothing and cannot accidentally remove it by changing themes.</p>
  <?php else: ?>
    <p style="margin:0">
      <span class="pill" style="color:var(--danger);border-color:var(--danger)">failed</span>
    </p>
    <pre style="margin-bottom:0"><?= htmlspecialchars((string) $pixel['message']) ?></pre>
  <?php endif; ?>
</div>
