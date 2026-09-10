<?php
/**
 * Geography and devices.
 *
 * Location comes from the IP address at the time of the visit, resolved
 * against an offline city database. No IP address is stored, and the result is
 * a city, not a person — see the privacy policy.
 *
 * @var array $tenant
 * @var array $stats
 * @var array $range
 * @var array $geography
 * @var array $devices
 * @var array $landing
 * @var bool  $hasData
 */

$cur  = (string) ($tenant['currency'] ?? 'INR');
$page = 'geography';
?>

<div class="page-head">
<div class="eyebrow">Audience</div>
<h1>Geography</h1>
  <p class="sub">Where visitors are, what they browse on, and where they land.</p>
</div>

<?php require __DIR__ . '/_toolbar.php'; ?>

<?php if ($geography === [] && $devices === [] && $landing === []): ?>
  <?php $what = 'location data'; require __DIR__ . '/_empty.php'; ?>
<?php else: ?>

  <?php if ($devices !== []): ?>
    <h2>Devices</h2>
    <div class="panel" style="padding:14px 16px">
      <?php $maxD = max(1, max(array_column($devices, 'visitors'))); ?>
      <table class="data">
        <thead>
          <tr>
            <th>Device</th>
            <th class="n">Visitors</th>
            <th class="n">Orders</th>
            <th class="n">Conversion</th>
            <th class="n">Revenue</th>
            <th style="width:24%"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($devices as $d): ?>
          <tr>
            <td class="name"><?= Fmt::e((string) $d['name']) ?></td>
            <td class="n"><?= Fmt::num((int) $d['visitors']) ?></td>
            <td class="n"><?= Fmt::num((int) $d['orders']) ?></td>
            <td class="n"><?= Fmt::pct($d['conversion'], 2) ?></td>
            <td class="n"><?= Fmt::e(Fmt::money((int) $d['revenue_minor'], $cur)) ?></td>
            <td><div class="minibar" style="width:<?= Fmt::barWidth((int) $d['visitors'], $maxD) ?>%"></div></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="hint" style="margin:12px 0 0">
        A gap between mobile and desktop conversion is normal; a large one usually means
        something on the mobile checkout is in the way.
      </p>
    </div>
  <?php endif; ?>

  <?php if ($geography !== []): ?>
    <h2>Where visitors are</h2>
    <div class="panel" style="padding:14px 16px">
      <?php
      // The bar sits beside Revenue and the table is ordered by Revenue, so
      // it has to encode Revenue. Sizing it by visitors made the bars look
      // shuffled: a city with more visitors but less revenue drew a longer
      // bar below one with a shorter bar.
      $maxG = max(1, max(array_column($geography, 'revenue_minor')));
      ?>
      <table class="data">
        <thead>
          <tr>
            <th>Place</th>
            <th class="n">Visitors</th>
            <th class="n">Orders</th>
            <th class="n">Conversion</th>
            <th class="n">Revenue</th>
            <th style="width:24%"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($geography as $g): ?>
          <tr>
            <td class="name" title="<?= Fmt::e((string) $g['name']) ?>"><?= Fmt::e(Fmt::clip((string) $g['name'], 40)) ?></td>
            <td class="n"><?= Fmt::num((int) $g['visitors']) ?></td>
            <td class="n"><?= Fmt::num((int) $g['orders']) ?></td>
            <td class="n"><?= Fmt::pct($g['conversion'], 2) ?></td>
            <td class="n"><?= Fmt::e(Fmt::money((int) $g['revenue_minor'], $cur)) ?></td>
            <td><div class="minibar" style="width:<?= Fmt::barWidth((int) $g['revenue_minor'], $maxG) ?>%"></div></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="hint" style="margin:12px 0 0">
        Worked out from the visitor's network location at the time of the visit, using an
        offline database. No IP address is kept.
      </p>
    </div>
  <?php endif; ?>

  <?php if ($landing !== []): ?>
    <h2>Where visitors land</h2>
    <div class="panel" style="padding:14px 16px">
      <table class="data">
        <thead>
          <tr>
            <th>Page</th>
            <th class="n">Visitors</th>
            <th class="n">Orders</th>
            <th class="n">Conversion</th>
            <th class="n">Revenue</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($landing as $l): ?>
          <tr>
            <td class="name"><code><?= Fmt::e(Fmt::path((string) $l['name'])) ?></code></td>
            <td class="n"><?= Fmt::num((int) $l['visitors']) ?></td>
            <td class="n"><?= Fmt::num((int) $l['orders']) ?></td>
            <td class="n"><?= Fmt::pct($l['conversion'], 2) ?></td>
            <td class="n"><?= Fmt::e(Fmt::money((int) $l['revenue_minor'], $cur)) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php endif; ?>
