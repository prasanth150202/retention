<?php
/**
 * Plan and billing.
 *
 * Shown when a merchant chooses a plan, and whenever billing is the reason
 * they cannot see their figures. It says plainly which of those it is: an app
 * that answers "no access" with a generic upsell leaves a merchant whose card
 * simply failed thinking they have been cancelled.
 *
 * @var array  $tenant
 * @var array  $billing
 * @var array  $plans
 * @var array  $history
 * @var ?string $error
 */

$blocked = !$billing['access'];
$status  = (string) $billing['status'];
?>

<div class="eyebrow">Subscription</div>
<h1>Plan</h1>
<p class="sub">
  <?= $blocked ? 'Your figures are waiting — this just needs sorting out first.'
               : 'What this store is subscribed to.' ?>
</p>

<?php if ($error !== null): ?>
  <div class="flash err"><?= Fmt::e($error) ?></div>
<?php endif; ?>

<?php if (!$billing['enabled']): ?>
  <p class="note">
    <b>Billing is switched off on this deployment.</b> Every store has full access.
    Nothing here charges anyone.
  </p>
<?php endif; ?>

<?php if ($billing['test']): ?>
  <p class="note" style="border-left:3px solid var(--warn)">
    <b>This is a test subscription.</b> Shopify is not charging anything for it.
  </p>
<?php endif; ?>

<div class="panel">
  <table>
    <tr>
      <td style="width:200px"><strong>Status</strong></td>
      <td>
        <?php if ($status === 'trial'): ?>
          <span class="pill live">free trial</span>
        <?php elseif ($status === 'active'): ?>
          <span class="pill live">active</span>
        <?php elseif ($status === 'pending'): ?>
          <span class="pill warn">awaiting approval</span>
        <?php elseif ($status === 'frozen'): ?>
          <span class="pill warn">payment failed</span>
        <?php else: ?>
          <span class="pill"><?= Fmt::e($status) ?></span>
        <?php endif; ?>
        <div class="hint"><?= Fmt::e((string) $billing['reason']) ?></div>
      </td>
    </tr>
    <?php if ($billing['trial_ends_at'] !== null && $status === 'trial'): ?>
    <tr>
      <td><strong>Trial ends</strong></td>
      <td><?= Fmt::e(Fmt::date((string) $billing['trial_ends_at'])) ?>
        <div class="hint">You are not charged until then, and you can uninstall at any point before it.</div>
      </td>
    </tr>
    <?php endif; ?>
    <?php if ($billing['current_period_end'] !== null && $status === 'active'): ?>
    <tr>
      <td><strong>Renews</strong></td>
      <td><?= Fmt::e(Fmt::date((string) $billing['current_period_end'])) ?></td>
    </tr>
    <?php endif; ?>
  </table>
</div>

<?php if ($status === 'pending' && !empty($billing['confirm_url'])): ?>
  <div class="panel">
    <h2 style="margin-top:0">One step left</h2>
    <p>The subscription is created but not yet approved. Shopify handles the approval,
    so the button opens their page rather than ours.</p>
    <p style="margin-bottom:0">
      <a class="btn" style="background:var(--accent);border-color:var(--accent);color:#fff"
         href="<?= Fmt::e((string) $billing['confirm_url']) ?>">Approve in Shopify</a>
    </p>
  </div>
<?php endif; ?>

<?php if ($status !== 'active' && $status !== 'trial' && $status !== 'pending'): ?>
  <h2><?= $status === 'none' ? 'Choose a plan' : 'Start again' ?></h2>

  <?php foreach ($plans as $handle => $plan): ?>
    <div class="panel">
      <h2 style="margin-top:0;font-size:17px"><?= Fmt::e((string) $plan['name']) ?></h2>
      <p class="muted" style="margin-top:2px"><?= Fmt::e((string) $plan['blurb']) ?></p>

      <p style="font-size:26px;font-weight:600;letter-spacing:-.02em;margin:14px 0 4px">
        <?= Fmt::e((string) $plan['currency']) ?> <?= Fmt::e((string) $plan['price']) ?>
        <span class="muted" style="font-size:14px;font-weight:400">per month</span>
      </p>

      <?php $trialDays = (int) Config::get('billing.trial_days', 0); ?>
      <?php if ($trialDays > 0): ?>
        <p class="hint" style="margin-top:0"><?= $trialDays ?> days free first. Nothing is charged
        until the trial ends, and uninstalling cancels it.</p>
      <?php endif; ?>

      <ul style="margin:16px 0;padding-left:20px;line-height:1.9">
        <?php foreach ((array) $plan['features'] as $feature): ?>
          <li><?= Fmt::e((string) $feature) ?></li>
        <?php endforeach; ?>
      </ul>

      <form method="post" action="/?p=plan">
        <?= Merchant::csrfField() ?>
        <input type="hidden" name="plan" value="<?= Fmt::e((string) $handle) ?>">
        <button class="primary" type="submit">
          <?= $trialDays > 0 ? 'Start the free trial' : 'Subscribe' ?>
        </button>
        <span class="hint" style="display:inline;margin-left:10px">
          Shopify takes the payment and shows you the charge before anything happens.
        </span>
      </form>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($status === 'frozen'): ?>
  <div class="panel">
    <p style="margin:0">Shopify will retry the payment on its own. Nothing needs doing here —
    updating the payment method on the Shopify account is what fixes it, and access returns
    automatically once a payment goes through.</p>
    <p class="hint" style="margin-bottom:0">Your data is untouched and still being collected
    in the meantime.</p>
  </div>
<?php endif; ?>

<?php if ($history !== []): ?>
  <h2>Billing history</h2>
  <div class="panel" style="padding:14px 16px">
    <table class="data">
      <thead>
        <tr><th>When</th><th>Change</th><th>Recorded by</th></tr>
      </thead>
      <tbody>
        <?php foreach ($history as $h): ?>
        <tr>
          <td><?= Fmt::e(Fmt::date((string) $h['occurred_at'])) ?></td>
          <td>
            <?= Fmt::e((string) ($h['status_from'] ?? '—')) ?>
            &rarr; <strong><?= Fmt::e((string) $h['status_to']) ?></strong>
          </td>
          <td class="muted"><?= Fmt::e((string) $h['source']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="hint" style="margin:12px 0 0">
      Shopify is the record of what was actually charged. This is our log of what we were
      told and when, so a question about a charge has something to look at.
    </p>
  </div>
<?php endif; ?>
