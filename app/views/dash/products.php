<?php
/**
 * Products — most viewed, most abandoned, and what converts.
 *
 * The view-to-purchase column is the one worth reading: a product with heavy
 * traffic and a poor rate is usually a pricing, photo or stock problem, and it
 * is invisible on any report that ranks by revenue alone.
 *
 * @var array $tenant
 * @var array $stats
 * @var array $range
 * @var array $products
 * @var bool  $hasData
 */

$cur  = (string) ($tenant['currency'] ?? 'INR');
$page = 'products';

/** @param array<int,array<string,mixed>> $rows */
$table = static function (array $rows, string $cur): void {
    $maxViews = max(1, max(array_column($rows, 'views') ?: [1]));
    ?>
    <table class="data">
      <thead>
        <tr>
          <th>Product</th>
          <th class="n">Views</th>
          <th class="n">Added to cart</th>
          <th class="n">View → cart</th>
          <th class="n">View → order</th>
          <th class="n">Units</th>
          <th class="n">Revenue</th>
          <th class="n">Left in carts</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $p): ?>
        <tr>
          <td class="name" title="<?= Fmt::e((string) $p['name']) ?>">
            <?= Fmt::e(Fmt::clip((string) $p['name'], 46)) ?>
            <?php if (!empty($p['handle'])): ?>
              <div class="sub2">/<?= Fmt::e(Fmt::clip((string) $p['handle'], 40)) ?></div>
            <?php endif; ?>
          </td>
          <td class="n">
            <?= Fmt::num((int) $p['views']) ?>
            <div class="minibar" style="width:<?= Fmt::barWidth((int) $p['views'], $maxViews, 4) ?>%;margin-left:auto"></div>
          </td>
          <td class="n"><?= Fmt::num((int) $p['atc']) ?></td>
          <td class="n"><?= Fmt::pct($p['view_to_atc']) ?></td>
          <td class="n"><?= Fmt::pct($p['view_to_buy'], 2) ?></td>
          <td class="n"><?= Fmt::num((int) $p['units']) ?></td>
          <td class="n"><?= Fmt::e(Fmt::money((int) $p['revenue_minor'], $cur)) ?></td>
          <td class="n"><?= Fmt::num((int) $p['abandons']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
};
?>

<div class="page-head">
<div class="eyebrow">Catalogue</div>
<h1>Products</h1>
  <p class="sub">What people look at, what they buy, and what they leave behind.</p>
</div>

<?php require __DIR__ . '/_toolbar.php'; ?>

<?php if ($products === []): ?>
  <?php $what = 'product activity'; require __DIR__ . '/_empty.php'; ?>
<?php else: ?>

  <?php
  // Ranked separately rather than sorted in place: "most viewed" and "most
  // abandoned" are different questions and a merchant asks them one at a time.
  $abandoned = array_values(array_filter($products, static fn($p) => (int) $p['abandons'] > 0));
  usort($abandoned, static fn($a, $b) => (int) $b['abandons'] <=> (int) $a['abandons']);

  $leaky = array_values(array_filter(
      $products,
      // Enough traffic for a rate to mean anything, and a poor one.
      static fn($p) => (int) $p['views'] >= 20 && $p['view_to_buy'] !== null && $p['view_to_buy'] < 1.0
  ));
  usort($leaky, static fn($a, $b) => (int) $b['views'] <=> (int) $a['views']);
  ?>

  <h2>Most viewed</h2>
  <div class="panel" style="padding:14px 16px">
    <?php $table(array_slice($products, 0, 25), $cur); ?>
  </div>

  <?php if ($abandoned !== []): ?>
    <h2>Most often left in a cart</h2>
    <div class="panel" style="padding:14px 16px">
      <?php $table(array_slice($abandoned, 0, 15), $cur); ?>
      <p class="hint" style="margin:12px 0 0">
        Counted from checkouts that were started and never completed — not from
        add-to-carts that later converted.
      </p>
    </div>
  <?php endif; ?>

  <?php if ($leaky !== []): ?>
    <h2>Plenty of interest, few orders</h2>
    <div class="panel" style="padding:14px 16px">
      <?php $table(array_slice($leaky, 0, 10), $cur); ?>
      <p class="hint" style="margin:12px 0 0">
        Products with at least 20 views converting under 1%. Usually price, photography
        or an out-of-stock variant rather than demand.
      </p>
    </div>
  <?php endif; ?>

<?php endif; ?>
