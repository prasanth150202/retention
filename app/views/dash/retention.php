<?php
/**
 * Retention — do customers come back.
 *
 * The reason this product exists. A cohort is everyone whose FIRST order fell
 * in that month, and each column is the share who ordered again within that
 * many days of it.
 *
 * Recent cohorts are necessarily incomplete: a customer acquired last week
 * cannot yet have a 90-day repeat rate. Those cells are blank rather than
 * zero, because a zero there reads as "nobody came back" when it means "not
 * yet possible to know".
 *
 * @var array $tenant
 * @var array $stats
 * @var array $retention
 * @var bool  $hasData
 */

$buckets = [30, 60, 90, 180];
$today   = new DateTimeImmutable('now');
?>

<div class="page-head">
  <div class="eyebrow">Lifetime value</div>
  <h1>Retention</h1>
  <p class="sub">Whether customers come back, and how long they take.</p>
</div>

<?php if (($retention['cohorts'] ?? []) === [] && ($retention['buckets_total'] ?? 0) === 0): ?>
  <?php $what = 'retention data'; require __DIR__ . '/_empty.php'; ?>
<?php else: ?>

  <?php
  $b     = $retention['buckets'] ?? [];
  $total = (int) ($retention['buckets_total'] ?? 0);
  $once  = (int) ($b[1] ?? 0);
  $more  = $total - $once;
  ?>
  <?php if ($total > 0): ?>
    <div class="tiles">
      <div class="tile">
        <div class="k">Customers</div>
        <div class="v"><?= Fmt::num($total) ?></div>
        <div class="d">who have ordered at least once</div>
      </div>
      <div class="tile accent">
        <div class="k">Bought again</div>
        <div class="v"><?= Fmt::pct($total > 0 ? round($more / $total * 100, 1) : null) ?></div>
        <div class="d"><?= Fmt::num($more) ?> of <?= Fmt::num($total) ?></div>
      </div>
      <?php foreach ([2 => 'Two orders', 3 => 'Three orders', 4 => 'Four or more'] as $k => $label): ?>
      <div class="tile">
        <div class="k"><?= $label ?></div>
        <div class="v"><?= Fmt::num((int) ($b[$k] ?? 0)) ?></div>
        <div class="d"><?= Fmt::pct($total > 0 ? round((int) ($b[$k] ?? 0) / $total * 100, 1) : null) ?> of customers</div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2>Repeat rate by the month they first bought</h2>
  <?php if (($retention['cohorts'] ?? []) === []): ?>
    <div class="panel"><p class="muted" style="margin:0">No completed cohorts yet.</p></div>
  <?php else: ?>
    <div class="panel" style="padding:14px 16px;overflow-x:auto">
      <table class="data cohort">
        <thead>
          <tr>
            <th>First bought in</th>
            <th class="n">Customers</th>
            <?php foreach ($buckets as $d): ?>
              <th class="n">Within <?= $d ?> days</th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($retention['cohorts'] as $c):
            $age = (int) (new DateTimeImmutable((string) $c['month']))->diff($today)->days;
          ?>
          <tr>
            <td class="name"><?= Fmt::e(Fmt::month((string) $c['month'])) ?></td>
            <td class="n"><?= Fmt::num((int) $c['size']) ?></td>
            <?php foreach ($buckets as $d):
              $cell = $c['buckets'][$d] ?? null;
              // A cohort younger than the window cannot have a rate yet, and a
              // zero there would read as "nobody came back".
              $ready = $age >= $d;
            ?>
              <td class="c">
                <?php if (!$ready): ?>
                  <span class="muted" style="--w:0">too soon</span>
                <?php else: ?>
                  <span style="--w:<?= min(60, (float) ($cell['pct'] ?? 0)) ?>">
                    <?= Fmt::pct($cell['pct'] ?? null, 0) ?>
                  </span>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="hint" style="margin:12px 0 0">
        Each row is everyone whose <em>first</em> order was in that month. "Too soon"
        means that cohort has not existed long enough for the figure to be knowable yet
        — it is not a zero.
      </p>
    </div>
  <?php endif; ?>

  <p class="note">
    <b>How customers are recognised.</b> Orders are matched into one customer by phone,
    email and Shopify customer id, so a guest checkout and a later account with the same
    phone count as one person rather than two. Grouping by Shopify's customer records
    alone would report a repeat rate well below the truth, because guest checkout leaves
    no customer record at all.
  </p>

<?php endif; ?>
