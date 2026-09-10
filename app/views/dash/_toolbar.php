<?php
/**
 * Date range chooser, shared by every tab.
 *
 * Presets rather than a calendar. A merchant checking on their store wants
 * "last 7 days", and a date picker is three extra decisions to get there. The
 * explicit ?from=&to= form still works for anyone who wants it.
 *
 * @var array  $range   from Report::range()
 * @var string $page    current ?p= value ('' for the overview)
 * @var array  $tenant
 */

$presets = [7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '12 months'];

$tz    = new DateTimeZone((string) ($tenant['iana_timezone'] ?? 'UTC'));
$today = new DateTimeImmutable('now', $tz);

$base = $page === '' ? '/?' : '/?p=' . urlencode($page) . '&';
?>
<div class="toolbar">
  <div class="ranges">
    <?php foreach ($presets as $days => $label):
      $from = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
      $on   = $range['days'] === $days && $range['to'] === $today->format('Y-m-d');
    ?>
      <a href="<?= Fmt::e($base . http_build_query(['from' => $from, 'to' => $today->format('Y-m-d')])) ?>"
         class="<?= $on ? 'on' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
  <div class="spacer"></div>
  <span class="muted" style="font-size:13px">
    <?= Fmt::e($range['label']) ?>
    <?php if (($tenant['iana_timezone'] ?? '') !== ''): ?>
      · <?= Fmt::e((string) $tenant['iana_timezone']) ?>
    <?php endif; ?>
  </span>
</div>
