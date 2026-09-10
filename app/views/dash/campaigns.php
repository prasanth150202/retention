<?php
/**
 * Campaigns & UTM.
 *
 * The tab where this dashboard will most often be checked against Meta Ads
 * Manager or Google Ads, and the numbers will not match. So the disagreement
 * is put on the page rather than left to be discovered: four attribution
 * models, switchable, and a plain statement of how much of the revenue none
 * of them can place.
 *
 * @var array  $tenant
 * @var array  $stats
 * @var array  $range
 * @var string $model
 * @var array  $campaigns
 * @var array  $channels
 * @var array  $comparison
 * @var array  $campaignRetention
 * @var bool   $hasData
 */

$cur  = (string) ($tenant['currency'] ?? 'INR');
$page = 'campaigns';

$modelNames = [
    'pixel_last'    => 'Last touch (ours)',
    'pixel_first'   => 'First touch (ours)',
    'shopify_last'  => 'Last touch (Shopify)',
    'shopify_first' => 'First touch (Shopify)',
];

$keep = ['from' => $range['from'], 'to' => $range['to']];
?>

<div class="page-head">
  <div class="eyebrow">Acquisition</div>
  <h1>Campaigns</h1>
  <p class="sub">Which sources bring visitors, orders and revenue.</p>
</div>

<?php require __DIR__ . '/_toolbar.php'; ?>

<div class="toolbar" style="margin-top:-8px">
  <div class="ranges">
    <?php foreach ($modelNames as $m => $label): ?>
      <a href="<?= Fmt::e('/?p=campaigns&' . http_build_query($keep + ['model' => $m])) ?>"
         class="<?= $model === $m ? 'on' : '' ?>"><?= Fmt::e($label) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!$hasData || ($campaigns === [] && $channels === [])): ?>
  <?php $what = 'campaign data'; require __DIR__ . '/_empty.php'; ?>
<?php else: ?>

  <?php
  $mine    = $comparison['pixel_last'] ?? null;
  $shopify = $comparison['shopify_last'] ?? null;
  ?>
  <?php if ($mine !== null && $shopify !== null && $mine['orders'] > 0 && $shopify['orders'] > 0): ?>
    <p class="note">
      <b>These four models will not agree, and that is expected.</b>
      Our own tracking placed <?= Fmt::pct(100 - (float) ($mine['unattributed_pct'] ?? 0)) ?>
      of orders to a campaign; Shopify's own attribution placed
      <?= Fmt::pct(100 - (float) ($shopify['unattributed_pct'] ?? 0)) ?>.
      We see more of the journey than Shopify's summary does, but lose whole visitors
      to ad blockers and refused cookie consent. Where a campaign shows a large gap
      between the two, that gap is usually consent, not a reporting fault.
    </p>
  <?php endif; ?>

  <h2>By channel</h2>
  <div class="panel" style="padding:14px 16px">
    <table class="data">
      <thead>
        <tr>
          <th>Channel</th>
          <th class="n">Visitors</th>
          <th class="n">Orders</th>
          <th class="n">Conversion</th>
          <th class="n">Revenue</th>
          <th class="n">Avg order</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($channels as $c): ?>
        <tr>
          <td class="name"><?= Fmt::e((string) $c['channel']) ?></td>
          <td class="n"><?= Fmt::num((int) $c['visitors']) ?></td>
          <td class="n"><?= Fmt::num((int) $c['orders']) ?></td>
          <td class="n"><?= Fmt::pct($c['conversion'], 2) ?></td>
          <td class="n"><?= Fmt::e(Fmt::money((int) $c['revenue_minor'], $cur)) ?></td>
          <td class="n"><?= Fmt::e(Fmt::money(
            $c['aov_minor'] === null ? null : (int) round((float) $c['aov_minor']), $cur
          )) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="hint" style="margin:12px 0 0">
      <b>Other</b> means the visit carried a campaign tag no rule recognises — tracked
      traffic with a rule missing, not untracked traffic. <b>Direct/Untracked</b> means
      there was no tag and no referrer at all.
    </p>
  </div>

  <h2>By campaign</h2>
  <?php if ($campaigns === []): ?>
    <div class="panel"><p class="muted" style="margin:0">No tagged campaigns in this period.</p></div>
  <?php else: ?>
    <div class="panel" style="padding:14px 16px">
      <table class="data">
        <thead>
          <tr>
            <th>Campaign</th>
            <th class="n">Visitors</th>
            <th class="n">Add to cart</th>
            <th class="n">Orders</th>
            <th class="n">Revenue</th>
            <th class="n">New</th>
            <th class="n">Returning</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($campaigns as $c): ?>
          <tr>
            <td class="name">
              <?= Fmt::e((string) $c['name']) ?>
              <?php if (!empty($c['utm_content']) || !empty($c['utm_term'])): ?>
                <div class="sub2"><?= Fmt::e(implode(' · ', array_filter([
                    $c['utm_content'] ?? null, $c['utm_term'] ?? null,
                ]))) ?></div>
              <?php endif; ?>
            </td>
            <td class="n"><?= Fmt::num((int) $c['visitors']) ?></td>
            <td class="n"><?= Fmt::num((int) $c['atc']) ?></td>
            <td class="n"><?= Fmt::num((int) $c['orders']) ?></td>
            <td class="n"><?= Fmt::e(Fmt::money((int) $c['revenue_minor'], $cur)) ?></td>
            <td class="n"><?= Fmt::num((int) $c['new_customers']) ?></td>
            <td class="n"><?= Fmt::num((int) $c['returning_customers']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <h2>Which campaigns bring buyers who come back</h2>
  <?php if ($campaignRetention === []): ?>
    <div class="panel">
      <p class="muted" style="margin:0">
        Not enough history yet. This needs customers acquired at least 90 days ago.
      </p>
    </div>
  <?php else: ?>
    <div class="panel" style="padding:14px 16px">
      <table class="data">
        <thead>
          <tr>
            <th>Campaign that brought them</th>
            <th class="n">First-time buyers</th>
            <th class="n">Bought again within 90 days</th>
            <th class="n">Repeat rate</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($campaignRetention as $c): ?>
          <tr>
            <td class="name"><?= Fmt::e((string) $c['name']) ?></td>
            <td class="n"><?= Fmt::num((int) $c['cohort_size']) ?></td>
            <td class="n"><?= Fmt::num((int) $c['reordered']) ?></td>
            <td class="n"><?= Fmt::pct($c['repeat_pct']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="hint" style="margin:12px 0 0">
        This is the figure that decides whether a campaign is worth its cost. A campaign
        with a cheap first order and a poor repeat rate loses money slowly, and nothing
        on a daily report shows it.
      </p>
    </div>
  <?php endif; ?>

<?php endif; ?>
