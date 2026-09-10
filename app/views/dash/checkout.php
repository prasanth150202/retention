<?php
/**
 * Checkout — where orders are lost.
 *
 * Shopify's own abandoned-checkout records only begin once a shopper submits
 * contact details, so a merchant is used to a number that counts only the last
 * part of the process. This tab shows the whole of it, and puts Shopify's
 * figure alongside so the two can be reconciled rather than argued about.
 *
 * @var array $tenant
 * @var array $stats
 * @var array $range
 * @var array $abandon
 * @var bool  $hasData
 */

$cur  = (string) ($tenant['currency'] ?? 'INR');
$page = 'checkout';

$stages = $abandon['stages'] ?? [];
$top    = $stages === [] ? 0 : max(1, max(array_column($stages, 'entered')));
?>

<div class="page-head">
<div class="eyebrow">Lost revenue</div>
<h1>Checkout</h1>
  <p class="sub">Where checkouts are lost, and how much is sitting in them.</p>
</div>

<?php require __DIR__ . '/_toolbar.php'; ?>

<?php if ($stages === [] || $top === 0): ?>
  <?php $what = 'checkout activity'; require __DIR__ . '/_empty.php'; ?>
<?php else: ?>

  <div class="tiles">
    <div class="tile">
      <div class="k">Checkouts started</div>
      <div class="v"><?= Fmt::num((int) $abandon['started']) ?></div>
      <div class="d">in this period</div>
    </div>
    <div class="tile">
      <div class="k">Completed</div>
      <div class="v"><?= Fmt::num((int) $abandon['completed']) ?></div>
      <div class="d">reached the confirmation page</div>
    </div>
    <div class="tile accent">
      <div class="k">Abandonment rate</div>
      <div class="v"><?= Fmt::pct($abandon['rate']) ?></div>
      <div class="d">started but not finished</div>
    </div>
    <div class="tile">
      <div class="k">Value left behind</div>
      <div class="v"><?= Fmt::e(Fmt::moneyShort((int) $abandon['value_minor'], $cur)) ?></div>
      <div class="d">across <?= Fmt::num((int) $abandon['shopify_records']) ?> recoverable carts</div>
    </div>
  </div>

  <h2>Stage by stage</h2>
  <div class="panel">
    <div class="funnel">
      <?php foreach ($stages as $s): ?>
        <div class="fstep">
          <div class="lbl">
            <?= Fmt::e($s['name']) ?>
            <?php if ($s['abandoned'] > 0): ?>
              <div class="fdrop">
                <?= Fmt::num($s['abandoned']) ?> left here
                <?= $s['drop_pct'] !== null ? '(' . Fmt::pct($s['drop_pct'], 0) . ')' : '' ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="fbar">
            <span style="width:<?= max(0.4, $s['entered'] / $top * 100) ?>%"></span>
          </div>
          <div class="n"><b><?= Fmt::num($s['entered']) ?></b></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <p class="note">
    <b>Why this differs from Shopify's abandoned checkouts.</b> Shopify only records a
    checkout as abandoned once the shopper has submitted contact details, so its count
    covers the last few steps here — <?= Fmt::num((int) $abandon['shopify_records']) ?>
    in this period, which is the figure that reconciles with your Shopify admin. The
    earlier stages are not in Shopify's records at all, and they are usually where the
    larger losses are: someone who leaves before entering an email is invisible to a
    recovery campaign but is still a lost sale.
  </p>

  <h2>The same figures as a table</h2>
  <div class="panel" style="padding:14px 16px">
    <table class="data">
      <thead>
        <tr>
          <th>Stage</th>
          <th class="n">Reached it</th>
          <th class="n">Went further</th>
          <th class="n">Left here</th>
          <th class="n">Drop-off</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($stages as $s): ?>
        <tr>
          <td class="name"><?= Fmt::e($s['name']) ?></td>
          <td class="n"><?= Fmt::num($s['entered']) ?></td>
          <td class="n"><?= Fmt::num($s['advanced']) ?></td>
          <td class="n"><?= Fmt::num($s['abandoned']) ?></td>
          <td class="n"><?= Fmt::pct($s['drop_pct']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="hint" style="margin:12px 0 0">
      Shoppers skip stages — a returning customer with a saved address goes from
      starting checkout to paying without touching the steps between. "Went further"
      counts anyone who reached any later stage, so it never exceeds the number who
      reached this one.
    </p>
  </div>

<?php endif; ?>
