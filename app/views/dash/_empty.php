<?php
/**
 * What a tab shows before there is anything to show.
 *
 * An empty dashboard and a broken one look identical, and a merchant who
 * cannot tell them apart assumes the worst. So this says which it is: whether
 * tracking is live, whether orders are syncing, and when numbers will appear.
 *
 * @var array $tenant
 * @var array $stats  from storeStats()
 * @var string $what  the tab's own noun, e.g. 'funnel'
 */

$live   = ($stats['events'] ?? 0) > 0 || ($stats['spool'] ?? 0) > 0;
$synced = ($stats['orders'] ?? 0) > 0;
?>
<div class="panel empty">
  <?php if (!$live && !$synced): ?>
    <strong>Waiting for the first visitor</strong>
    Tracking is installed and running. Figures appear as people browse the store,
    usually within an hour of the first visit.
    <div class="hint" style="margin-top:10px">
      There is nothing else to set up — no snippet to paste, no theme change to make.
    </div>
  <?php elseif ($live): ?>
    <strong>Collecting data</strong>
    <?= (int) $stats['visitors'] > 0
        ? number_format((int) $stats['visitors']) . ' visitor(s) tracked so far.'
        : 'Events are arriving and being processed.' ?>
    The <?= Fmt::e($what) ?> is built once per hour, so the first figures appear
    shortly after the first full hour of traffic.
  <?php else: ?>
    <strong>No <?= Fmt::e($what) ?> for this period</strong>
    There is data for this store, but nothing in the dates selected. Try a wider range.
  <?php endif; ?>
</div>
