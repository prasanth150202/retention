<?php
/**
 * Funnel — where visitors stop.
 *
 * Two bars per step, deliberately. The solid one is everyone who reached that
 * step at all; the faint one behind it is those who took every earlier step
 * first, in order.
 *
 * They differ a lot in practice. Somebody arriving on a product page straight
 * from an ad never fires a plain page view, so they reach "viewed a product"
 * without ever "visiting the store". Showing only the strict path hides an ad
 * that is working; showing only reached produces a funnel with more
 * add-to-carts than product views, which reads as a broken dashboard.
 *
 * @var array $tenant
 * @var array $stats
 * @var array $range
 * @var array $funnel
 * @var bool  $hasData
 */

$page = 'funnel';

$top = $funnel === [] ? 0 : max(1, max(array_column($funnel, 'reached')));
?>

<div class="page-head">
<div class="eyebrow">Behaviour</div>
<h1>Funnel</h1>
  <p class="sub">Where people stop between arriving and buying.</p>
</div>

<?php require __DIR__ . '/_toolbar.php'; ?>

<?php if ($funnel === [] || $top === 0): ?>
  <?php $what = 'funnel'; require __DIR__ . '/_empty.php'; ?>
<?php else: ?>

  <div class="panel">
    <div class="funnel">
      <?php foreach ($funnel as $i => $s): ?>
        <div class="fstep">
          <div class="lbl">
            <?= Fmt::e($s['name']) ?>
            <?php if ($s['lost'] !== null && $s['lost'] > 0): ?>
              <div class="fdrop">
                −<?= Fmt::num($s['lost']) ?> from the step before
                <?= $s['of_prev'] !== null ? '(' . Fmt::pct($s['of_prev'], 0) . ' continued)' : '' ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="fbar">
            <span style="width:<?= Fmt::barWidth($s['reached'], $top) ?>%"></span>
            <em style="width:<?= Fmt::barWidth($s['strict'], $top) ?>%"></em>
          </div>
          <div class="n">
            <b><?= Fmt::num($s['reached']) ?></b>
            <div class="hint"><?= Fmt::pct($s['of_first'], 1) ?> of all visitors</div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <p class="note">
    <b>Two numbers per step.</b> The pale bar is everyone who reached that step at all.
    The solid bar nested inside it is those who took every earlier step first, in order
    — always a subset, never more. A visitor who lands straight on a product page from
    an ad reaches "viewed a product" without ever visiting the home page, so the two
    differ, and the gap between them is roughly how much of your traffic arrives deep
    in the site rather than at the front door.
  </p>

  <h2>The same figures as a table</h2>
  <div class="panel" style="padding:14px 16px">
    <table class="data">
      <thead>
        <tr>
          <th>Step</th>
          <th class="n">Reached it</th>
          <th class="n">In strict order</th>
          <th class="n">Of all visitors</th>
          <th class="n">Continued from previous</th>
          <th class="n">Events</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($funnel as $s): ?>
        <tr>
          <td class="name"><?= Fmt::e($s['name']) ?></td>
          <td class="n"><?= Fmt::num($s['reached']) ?></td>
          <td class="n"><?= Fmt::num($s['strict']) ?></td>
          <td class="n"><?= Fmt::pct($s['of_first']) ?></td>
          <td class="n"><?= Fmt::pct($s['of_prev']) ?></td>
          <td class="n muted"><?= Fmt::num($s['events']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>
