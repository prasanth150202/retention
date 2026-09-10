<?php
/**
 * The merchant's view of their own store.
 *
 * A shell for now: the analytics that make this worth paying for arrive in
 * P4. What it does today is answer "is this working?", which is the only
 * question a merchant has in the first hour after installing.
 *
 * @var array $tenant
 * @var array{events:int,last_event:?string,visitors:int,spool:int,orders:int,revenue_minor:int} $stats
 */

$scopes  = ShopifyOAuth::verifyScopes((string) ($tenant['token_scopes'] ?? ''));
$live    = $stats['events'] > 0 || $stats['spool'] > 0;
$hasApi  = !empty($tenant['admin_token_enc']);
?>

<h1><?= htmlspecialchars($tenant['display_name']) ?></h1>
<p class="sub"><?= htmlspecialchars($tenant['shop_domain']) ?></p>

<?php if (!$live): ?>
  <div class="panel">
    <h2 style="margin-top:0">Waiting for the first visitor</h2>
    <p>Tracking is installed and running. Data appears as people browse the store —
    usually within a few minutes of the first visit.</p>
    <p class="hint" style="margin-bottom:0">Nothing else to set up. There is no snippet to
    paste and no theme change to make.</p>
  </div>
<?php endif; ?>

<div class="panel" style="padding:6px 4px">
  <table>
    <tr>
      <td style="width:220px"><strong>Visitors tracked</strong></td>
      <td>
        <?php if ($stats['visitors'] > 0): ?>
          <span class="pill live"><?= number_format($stats['visitors']) ?></span>
          across <?= number_format($stats['events']) ?> events
          <div class="hint">Most recent activity: <?= htmlspecialchars((string) $stats['last_event']) ?> UTC</div>
        <?php elseif ($stats['spool'] > 0): ?>
          <span class="pill">arriving</span>
          <div class="hint">Events have been received and are being processed.</div>
        <?php else: ?>
          <span class="muted">none yet</span>
        <?php endif; ?>
      </td>
    </tr>
    <tr>
      <td><strong>Orders synced</strong></td>
      <td>
        <?php if ($stats['orders'] > 0): ?>
          <span class="pill live"><?= number_format($stats['orders']) ?></span>
          &middot; &#8377;<?= number_format($stats['revenue_minor'] / 100, 2) ?>
        <?php elseif ($hasApi): ?>
          <span class="muted">syncing</span>
          <div class="hint">Order history is pulled hourly and can take a while on a
          store with a long history.</div>
        <?php else: ?>
          <span class="muted">not connected</span>
        <?php endif; ?>
      </td>
    </tr>
  </table>
</div>

<?php if ($scopes['history_limited']): ?>
  <div class="panel" style="border-left:3px solid var(--warn)">
    <strong>Limited order history</strong>
    <p style="margin-bottom:0">This app currently has access to the last 60 days of orders
    only, so repeat-purchase and cohort figures will be incomplete. Everything else works
    normally. We are resolving this with Shopify.</p>
  </div>
<?php endif; ?>

<h2>Coming next</h2>
<div class="panel">
  <p style="margin-top:0" class="muted">These are being built. The data behind them is
  already being collected, so they will fill in with your real history rather than
  starting from zero.</p>
  <table>
    <?php foreach ([
      'Funnel'      => 'Where visitors drop off between browsing and buying',
      'Retention'   => 'Repeat purchase rate, and how long customers take to come back',
      'Campaigns'   => 'Which sources bring customers who buy again, not just once',
      'Products'    => 'Most viewed, most abandoned, and view-to-purchase rates',
      'Abandonment' => 'Where checkouts are lost, and how much is sitting in them',
    ] as $name => $what): ?>
    <tr>
      <td style="width:180px"><strong><?= $name ?></strong></td>
      <td class="muted"><?= $what ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
