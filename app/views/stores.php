<?php
/** @var array $stores */
/** @var array{0:string,1:string}|null $flash */
?>
<h1>Stores</h1>
<p class="sub">Connected Shopify stores and the state of their data.</p>

<?php if ($flash): ?>
  <div class="flash <?= htmlspecialchars($flash[0]) ?>"><?= htmlspecialchars($flash[1]) ?></div>
<?php endif; ?>

<?php if ($stores === []): ?>
  <div class="panel">
    <p class="muted" style="margin:0">No stores connected yet. Add one below — you can install
    the pixel straight away; the Shopify API connection comes later and is not needed for
    behavioural tracking.</p>
  </div>
<?php else: ?>
  <div class="panel" style="padding:6px 4px">
    <table>
      <tr><th>Store</th><th>Domain</th><th>Status</th><th>Connected</th><th></th></tr>
      <?php foreach ($stores as $s): ?>
      <tr>
        <td><strong><?= htmlspecialchars($s['display_name']) ?></strong></td>
        <td class="muted"><?= htmlspecialchars($s['shop_domain']) ?></td>
        <td>
          <span class="pill <?= $s['status'] === 'active' ? 'live' : '' ?>">
            <?= htmlspecialchars($s['status']) ?>
          </span>
        </td>
        <td class="muted"><?= htmlspecialchars(substr((string) $s['installed_at'], 0, 10)) ?></td>
        <td style="text-align:right">
          <a class="btn" href="/?p=store&amp;id=<?= (int) $s['tenant_id'] ?>">Snippets &amp; status</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>

<h2>Connect a store</h2>
<form method="post" action="/?p=stores" class="panel">
  <?= Auth::csrfField() ?>
  <input type="hidden" name="action" value="add_store">

  <label for="shop_domain">Shopify domain</label>
  <input type="text" id="shop_domain" name="shop_domain" placeholder="example.myshopify.com" required>
  <div class="hint">The permanent <code>.myshopify.com</code> address, not the custom domain.
  Checkout always runs on this one, so it is what the pixel reports.</div>

  <label for="custom_domain">Storefront domain <span class="muted">(optional)</span></label>
  <input type="text" id="custom_domain" name="custom_domain" placeholder="example.com">
  <div class="hint">If the shop is browsed on its own domain, add it here — otherwise its
  events are rejected as coming from the wrong origin.</div>

  <label for="display_name">Name <span class="muted">(optional)</span></label>
  <input type="text" id="display_name" name="display_name" placeholder="Example Store">

  <p style="margin:20px 0 0"><button type="submit" class="primary">Connect store</button></p>
</form>
