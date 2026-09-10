<?php
/**
 * Overview — the answer to "how is the store doing".
 *
 * Ordered by what a merchant checks first: money, then whether it is growing,
 * then where it came from. Every tile carries its change against the
 * immediately preceding period of the same length, because a number with
 * nothing to compare it to tells you almost nothing.
 *
 * @var array $tenant
 * @var array $stats
 * @var array $range
 * @var array $summary
 * @var array $trend
 * @var array $channels
 * @var bool  $hasData
 */

$cur  = (string) ($tenant['currency'] ?? 'INR');
$page = '';
?>

<div class="page-head">
  <div class="eyebrow">Shopify Web Pixel &middot; Live Store Analytics</div>
  <h1><?= Fmt::e((string) $tenant['display_name']) ?></h1>
  <p class="sub"><?= Fmt::e((string) $tenant['shop_domain']) ?> &middot; every figure below is
  built from this store's own traffic and orders.</p>
</div>

<?php
require __DIR__ . '/_toolbar.php';

$tile = static function (
    string $label,
    string $value,
    ?float $change,
    string $goodDir = 'up',
    bool $accent = false
): void {
    $d = Fmt::delta($change);
    // The formatter knows the direction, not whether it is welcome. Refunds
    // rising is not good news, so each tile says which way is up for it.
    $cls = $d['dir'] === 'flat' ? 'flat' : ($d['dir'] === $goodDir ? 'up' : 'down');
    ?>
    <div class="tile<?= $accent ? ' accent' : '' ?>">
      <div class="k"><?= Fmt::e($label) ?></div>
      <div class="v"><?= Fmt::e($value) ?></div>
      <div class="d <?= $cls ?>"><?= $d['text'] === '' ? '&nbsp;' : Fmt::e($d['text']) ?></div>
    </div>
    <?php
};
?>

<?php if (!$hasData): ?>
  <?php $what = 'overview'; require __DIR__ . '/_empty.php'; ?>
<?php else: ?>

  <div class="tiles">
    <?php
    // The one number the page is about gets the inverted tile.
    $tile('Revenue', Fmt::moneyShort($summary['revenue_minor']['value'], $cur), $summary['revenue_minor']['change'], 'up', true);
    $tile('Orders', Fmt::num($summary['orders']['value']), $summary['orders']['change']);
    $tile('Average order', Fmt::money(
        $summary['aov_minor']['value'] === null ? null : (int) round($summary['aov_minor']['value']),
        $cur
    ), $summary['aov_minor']['change']);
    $tile('Visitors', Fmt::num($summary['visitors']['value']), $summary['visitors']['change']);
    $tile('Conversion', Fmt::pct($summary['conversion_pct']['value'], 2), $summary['conversion_pct']['change']);
    $tile('Repeat buyers', Fmt::pct($summary['repeat_pct']['value']), $summary['repeat_pct']['change']);
    ?>
  </div>

  <div class="tiles">
    <?php
    $tile('New customers', Fmt::num($summary['new_customers']['value']), $summary['new_customers']['change']);
    $tile('Returning customers', Fmt::num($summary['repeat_customers']['value']), $summary['repeat_customers']['change']);
    $tile('Sessions', Fmt::num($summary['sessions']['value']), $summary['sessions']['change']);
    $tile('Units sold', Fmt::num($summary['units']['value']), $summary['units']['change']);
    $tile('Refunded', Fmt::moneyShort($summary['refunded_minor']['value'], $cur), $summary['refunded_minor']['change'], 'down');
    ?>
  </div>

  <?php if (ShopifyOAuth::verifyScopes((string) ($tenant['token_scopes'] ?? ''))['history_limited']): ?>
    <p class="note" style="border-left:3px solid var(--warn)">
      <b>Limited order history.</b> This app currently has access to your last 60 days of
      orders only, so repeat-purchase and cohort figures are incomplete — a customer whose
      first order predates that window looks like a new customer. Everything else on this
      page is unaffected. We are resolving this with Shopify.
    </p>
  <?php endif; ?>

  <?php if (!empty($summary['provisional'])): ?>
    <p class="note">
      <b>Recent days are still settling.</b> Late events, refunds and Shopify's own
      attribution keep arriving for up to three days, so the most recent figures can
      still move. Anything older than that is final.
    </p>
  <?php endif; ?>

  <h2>Day by day</h2>
  <div class="panel" style="padding:14px 16px">
    <?php
    $maxRev = max(1, max(array_column($trend, 'revenue_minor')));
    $recent = array_slice($trend, -30);
    ?>
    <table class="data">
      <thead>
        <tr>
          <th>Date</th>
          <th class="n">Visitors</th>
          <th class="n">Orders</th>
          <th class="n">Revenue</th>
          <th style="width:34%"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_reverse($recent) as $d): ?>
        <tr>
          <td>
            <?= Fmt::e(Fmt::date($d['date'])) ?>
            <?php if ($d['provisional']): ?><span class="pill">settling</span><?php endif; ?>
          </td>
          <td class="n"><?= $d['missing'] ? '<span class="muted">—</span>' : Fmt::num($d['visitors']) ?></td>
          <td class="n"><?= $d['missing'] ? '<span class="muted">—</span>' : Fmt::num($d['orders']) ?></td>
          <td class="n"><?= Fmt::e(Fmt::money($d['revenue_minor'], $cur)) ?></td>
          <td>
            <div class="minibar" style="width:<?= Fmt::barWidth($d['revenue_minor'], $maxRev) ?>%"></div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h2>Where the revenue came from</h2>
  <?php if ($channels === []): ?>
    <div class="panel"><p class="muted" style="margin:0">No attributed orders in this period yet.</p></div>
  <?php else: ?>
    <div class="panel" style="padding:14px 16px">
      <?php $maxCh = max(1, max(array_column($channels, 'revenue_minor'))); ?>
      <table class="data">
        <thead>
          <tr>
            <th>Channel</th>
            <th class="n">Visitors</th>
            <th class="n">Orders</th>
            <th class="n">Revenue</th>
            <th style="width:28%"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (array_slice($channels, 0, 10) as $c): ?>
          <tr>
            <td class="name"><?= Fmt::e((string) $c['channel']) ?></td>
            <td class="n"><?= Fmt::num((int) $c['visitors']) ?></td>
            <td class="n"><?= Fmt::num((int) $c['orders']) ?></td>
            <td class="n"><?= Fmt::e(Fmt::money((int) $c['revenue_minor'], $cur)) ?></td>
            <td><div class="minibar" style="width:<?= Fmt::barWidth((int) $c['revenue_minor'], $maxCh) ?>%"></div></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="hint" style="margin:12px 0 0">
        Credited to the last campaign we saw before the order. The Campaigns tab shows
        the same period under all four attribution models.
      </p>
    </div>
  <?php endif; ?>

<?php endif; ?>
