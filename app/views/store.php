<?php
/**
 * One store: install instructions, the two snippets, and whether data is
 * actually arriving.
 *
 * The live-status panel is here because it answers the question that
 * immediately follows pasting a snippet — "is this working?" — which is
 * otherwise answered by staring at an empty dashboard, unable to tell a
 * broken install from a quiet afternoon.
 *
 * @var array $tenant
 * @var array{events:int,last_event:?string,spool:int,visitors:int} $stats
 */

$isNew   = isset($_GET['new']);
$pixel   = Snippet::pixel($tenant);
$liquid  = Snippet::liquid($tenant);
$arrived = $stats['events'] > 0 || $stats['spool'] > 0;
?>

<p style="margin:0 0 6px"><a href="/?p=stores" class="muted" style="text-decoration:none">&larr; Stores</a></p>
<h1><?= htmlspecialchars($tenant['display_name']) ?></h1>
<p class="sub"><?= htmlspecialchars($tenant['shop_domain']) ?><?php
    if ($tenant['custom_domain']) {
        echo ' &middot; ' . htmlspecialchars($tenant['custom_domain']);
    } ?></p>

<?php if ($isNew): ?>
  <div class="flash ok">
    Store connected. Install the two snippets below and events start arriving immediately —
    no Shopify API connection is needed for that.
  </div>
<?php endif; ?>

<!-- ---------------------------------------------------------------- -->
<h2>Is data arriving?</h2>
<div class="panel">
  <table>
    <tr>
      <td style="width:200px"><strong>Events stored</strong></td>
      <td>
        <?php if ($stats['events'] > 0): ?>
          <span class="pill live"><?= number_format($stats['events']) ?></span>
          from <?= number_format($stats['visitors']) ?> visitor(s)
          <div class="hint">Most recent: <?= htmlspecialchars((string) $stats['last_event']) ?> UTC</div>
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
          <div class="hint">Events have arrived and are queued. The importer runs every few
          minutes; the current hour's file is deliberately left open until the hour ends.</div>
        <?php else: ?>
          <span class="muted">nothing queued</span>
        <?php endif; ?>
      </td>
    </tr>
  </table>

  <?php if (!$arrived): ?>
    <p class="hint" style="margin:14px 0 0">
      Nothing has arrived yet. That is expected until the snippets are installed and someone
      visits the store. If it stays empty after a visit, the usual cause is the storefront
      domain above not matching where the store is actually browsed — events from an unknown
      origin are rejected.
    </p>
  <?php endif; ?>
</div>

<!-- ---------------------------------------------------------------- -->
<h2>1. Custom Pixel &mdash; the funnel</h2>
<div class="panel">
  <p style="margin-top:0">In Shopify admin:
    <strong>Settings &rarr; Customer events &rarr; Add custom pixel</strong>.
    Name it <code>Odysseus</code>, paste this, <strong>Save</strong>, then <strong>Connect</strong>.</p>

  <p class="hint">This is the only source that can see checkout, so it owns every funnel step
  from page view to purchase. It subscribes to thirteen events and deliberately omits the
  <code>input_*</code> ones, which carry raw email addresses and phone numbers.</p>

  <pre id="pixel"><?= htmlspecialchars($pixel) ?></pre>
  <button type="button" onclick="copyBlock('pixel', this)">Copy pixel code</button>
</div>

<!-- ---------------------------------------------------------------- -->
<h2>2. Theme snippet &mdash; identity and clicks</h2>
<div class="panel">
  <p style="margin-top:0">In Shopify admin:
    <strong>Online Store &rarr; Themes &rarr; &hellip; &rarr; Edit code &rarr; <code>theme.liquid</code></strong>.
    Paste immediately before <code>&lt;/body&gt;</code> and save.</p>

  <p class="hint">Optional but worth installing. The pixel runs in a sandbox with no access to
  Liquid, so a logged-in returning customer is anonymous to it until they reach checkout. This
  snippet identifies them while they are still browsing, which is what makes
  &ldquo;what did my repeat buyers look at before buying?&rdquo; answerable. It never reports
  page or product views — those would double-count against the pixel.</p>

  <pre id="liquid"><?= htmlspecialchars($liquid) ?></pre>
  <button type="button" onclick="copyBlock('liquid', this)">Copy theme snippet</button>
</div>

<!-- ---------------------------------------------------------------- -->
<h2>Details</h2>
<div class="panel">
  <table>
    <tr>
      <td style="width:200px"><strong>Write key</strong></td>
      <td>
        <code><?= htmlspecialchars($tenant['write_key']) ?></code>
        <div class="hint">Public by design — it is served to every visitor inside the snippet
        and is readable with View Source. It identifies which store an event belongs to; it is
        not a password. Events are actually protected by an origin check against the domains
        above, a rate limit, and a size cap.</div>
      </td>
    </tr>
    <tr>
      <td><strong>Reporting timezone</strong></td>
      <td><?= htmlspecialchars($tenant['iana_timezone']) ?>
        <div class="hint">Events are stored in UTC and converted for display, so changing this
        never moves historical events between days.</div>
      </td>
    </tr>
    <tr>
      <td><strong>Currency</strong></td>
      <td><?= htmlspecialchars($tenant['currency']) ?></td>
    </tr>
    <tr>
      <td><strong>Shopify API</strong></td>
      <td>
        <?php if (!empty($tenant['admin_token_enc'])): ?>
          <span class="pill live">connected</span>
          <?php if ($tenant['token_scopes'] ?? ''):
              $sc = ShopifyOAuth::verifyScopes((string) $tenant['token_scopes']);
              if ($sc['history_limited']): ?>
            <div class="hint" style="color:var(--warn)">
              <strong>read_all_orders was not granted.</strong> Only the last 60 days of orders
              are available, so retention and cohort figures will stay empty. Request the scope
              in the Partner Dashboard and reconnect.
            </div>
          <?php endif; endif; ?>
          <div class="hint"><a href="/?p=connect&amp;id=<?= (int) $tenant['tenant_id'] ?>">Reconnect</a>
          — needed after adding a scope.</div>
        <?php else: ?>
          <span class="muted">not connected</span>
          <div class="hint">Orders, customers and abandoned checkouts arrive once the Admin API
          is connected. Until then the funnel is complete but revenue is not, because purchase
          totals come from the API rather than the browser.</div>
          <p style="margin:10px 0 0">
            <a class="btn" href="/?p=connect&amp;id=<?= (int) $tenant['tenant_id'] ?>">Connect the Shopify API</a>
          </p>
        <?php endif; ?>
      </td>
    </tr>
  </table>
</div>

<script>
function copyBlock(id, btn) {
  var text = document.getElementById(id).innerText;
  var done = function () {
    var was = btn.innerText;
    btn.innerText = 'Copied';
    setTimeout(function () { btn.innerText = was; }, 1600);
  };
  if (navigator.clipboard) {
    navigator.clipboard.writeText(text).then(done, function () { fallback(text, done); });
  } else {
    fallback(text, done);
  }
}
function fallback(text, done) {
  var ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.select();
  try { document.execCommand('copy'); done(); } catch (e) {}
  document.body.removeChild(ta);
}
</script>
