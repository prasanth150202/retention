<?php
/**
 * Every store that has installed the app.
 *
 * There is no "add store" form any more. Merchants install from the Shopify
 * App Store and appear here on their own — which was the point of going
 * public. Staff observe; they do not onboard.
 *
 * @var array $stores
 * @var array{0:string,1:string}|null $flash
 */
?>
<h1>Stores</h1>
<p class="sub"><?= count($stores) ?> installed.</p>

<?php if ($flash): ?>
  <div class="flash <?= htmlspecialchars($flash[0]) ?>"><?= htmlspecialchars($flash[1]) ?></div>
<?php endif; ?>

<?php if ($stores === []): ?>
  <div class="panel">
    <p style="margin:0" class="muted">No installs yet. Stores appear here the moment a
    merchant installs the app — there is nothing to set up from this side.</p>
  </div>
<?php else: ?>
  <div class="panel" style="padding:6px 4px">
    <table>
      <tr><th>Store</th><th>Status</th><th>API</th><th>Backfill</th><th>Installed</th><th></th></tr>
      <?php foreach ($stores as $s): ?>
      <tr>
        <td>
          <strong><?= htmlspecialchars($s['display_name']) ?></strong>
          <div class="hint"><?= htmlspecialchars($s['shop_domain']) ?></div>
        </td>
        <td>
          <span class="pill <?= $s['status'] === 'active' ? 'live' : '' ?>">
            <?= htmlspecialchars($s['status']) ?>
          </span>
        </td>
        <td class="muted"><?= $s['connected'] ? 'connected' : '—' ?></td>
        <td class="muted"><?= htmlspecialchars((string) $s['backfill_state']) ?></td>
        <td class="muted"><?= htmlspecialchars(substr((string) $s['installed_at'], 0, 10)) ?></td>
        <td style="text-align:right">
          <a class="btn" href="/?p=store&amp;id=<?= (int) $s['tenant_id'] ?>">Open</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>
