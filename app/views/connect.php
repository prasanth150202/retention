<?php
/**
 * Start a Shopify Admin API connection for one store.
 *
 * @var array $tenant
 * @var string|null $installUrl
 * @var array{0:string,1:string}|null $flash
 */

$redirect = Config::require('hosts.oauth_redirect');
?>

<p style="margin:0 0 6px">
  <a href="/?p=store&amp;id=<?= (int) $tenant['tenant_id'] ?>" class="muted" style="text-decoration:none">
    &larr; <?= htmlspecialchars($tenant['display_name']) ?>
  </a>
</p>
<h1>Connect the Shopify API</h1>
<p class="sub"><?= htmlspecialchars($tenant['shop_domain']) ?></p>

<?php if ($flash): ?>
  <div class="flash <?= htmlspecialchars($flash[0]) ?>"><?= htmlspecialchars($flash[1]) ?></div>
<?php endif; ?>

<?php if ($tenant['connected']): ?>
  <div class="flash ok">
    Already connected. Reconnecting replaces the stored token — useful after adding a scope,
    harmless otherwise.
  </div>
<?php endif; ?>

<?php if ($installUrl !== null): ?>

  <div class="panel">
    <h2 style="margin-top:0">Send this link to the store owner</h2>
    <p>They open it, approve the permissions, and Shopify returns them here. The link is
    <strong>single use and expires in 15 minutes</strong>.</p>
    <pre id="installurl"><?= htmlspecialchars($installUrl) ?></pre>
    <button type="button" onclick="copyUrl(this)">Copy link</button>
    <a class="btn" href="<?= htmlspecialchars($installUrl) ?>" target="_blank" rel="noopener">Open it yourself</a>
    <p class="hint" style="margin-bottom:0">If you have admin access to the store, opening it
    yourself is faster than sending it on.</p>
  </div>

  <script>
  function copyUrl(btn) {
    var t = document.getElementById('installurl').innerText;
    navigator.clipboard && navigator.clipboard.writeText(t).then(function () {
      var was = btn.innerText; btn.innerText = 'Copied';
      setTimeout(function () { btn.innerText = was; }, 1600);
    });
  }
  </script>

<?php else: ?>

  <div class="panel">
    <p style="margin-top:0">Behavioural tracking already works without this. Connecting the API
    adds what the browser cannot report: order totals, refunds, customer history and abandoned
    checkouts — everything revenue and retention are calculated from.</p>
  </div>

  <form method="post" action="/?p=connect&amp;id=<?= (int) $tenant['tenant_id'] ?>" class="panel">
    <?= Auth::csrfField() ?>

    <label for="client_id">Client ID</label>
    <input type="text" id="client_id" name="client_id" required autocomplete="off"
           placeholder="e.g. 1a2b3c4d5e6f...">
    <div class="hint">Partner Dashboard &rarr; your app &rarr; <strong>Client credentials</strong>.</div>

    <label for="client_secret">Client secret</label>
    <input type="password" id="client_secret" name="client_secret" required autocomplete="off">
    <div class="hint">Stored encrypted and only until the install completes. It is needed in the
    callback to verify Shopify's signature, then discarded.</div>

    <p style="margin:22px 0 0"><button type="submit" class="primary">Generate install link</button></p>
  </form>

  <h2>Before this will work</h2>
  <div class="panel">
    <p style="margin-top:0"><strong>1. Set the redirect URL on the app.</strong> In the Partner
    Dashboard under App setup, the allowed redirection URL must be exactly:</p>
    <pre><?= htmlspecialchars($redirect) ?></pre>
    <p class="hint">Shopify rejects the install if this differs by so much as a trailing slash,
    and the error it shows does not say which part is wrong.</p>

    <p><strong>2. Request <code>read_all_orders</code>.</strong> Also under App setup. Without
    it the API returns only the last 60 days of orders — the install still succeeds, and every
    retention, cohort and lifetime-value figure stays empty for a reason nobody would connect
    back to this step. We check after connecting and tell you if it was not granted.</p>

    <p style="margin-bottom:0"><strong>3. Declare protected customer data.</strong>
    <code>read_customers</code> requires a data-use declaration on the app. Neither this nor the
    scope above is instant, so file them before you need them.</p>
  </div>

  <h2>Scopes requested</h2>
  <div class="panel">
    <p style="margin-top:0">Eight, and no more. The store owner sees this list on the consent
    screen, and a request for anything unexplained is a fair reason to refuse.</p>
    <table>
      <?php foreach ([
        'read_all_orders'  => 'Order history beyond 60 days — retention depends on it',
        'read_orders'      => 'Orders, totals, line items',
        'read_customers'   => 'Customer records for first/second/third purchase logic',
        'read_products'    => 'Product titles for the products report',
        'read_checkouts'   => 'Abandoned checkouts',
        'read_inventory'   => 'Stock state, to separate "not selling" from "out of stock"',
        'read_price_rules' => 'Discount attribution',
        'read_locales'     => 'Store currency and timezone',
      ] as $scope => $why): ?>
      <tr>
        <td style="width:200px"><code><?= htmlspecialchars($scope) ?></code></td>
        <td class="muted"><?= htmlspecialchars($why) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

<?php endif; ?>
