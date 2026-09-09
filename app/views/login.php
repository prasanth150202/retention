<?php
/** @var array{0:string,1:string}|null $flash */
?>
<div style="max-width:380px;margin:8vh auto">
  <h1>Odysseus</h1>
  <p class="sub">Retention analytics — staff sign in.</p>

  <?php if ($flash): ?>
    <div class="flash <?= htmlspecialchars($flash[0]) ?>"><?= htmlspecialchars($flash[1]) ?></div>
  <?php endif; ?>

  <form method="post" action="/?p=login" class="panel">
    <?= Auth::csrfField() ?>
    <label for="email">Email</label>
    <input type="email" id="email" name="email" autocomplete="username" required autofocus>

    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>

    <p style="margin:20px 0 0"><button type="submit" class="primary" style="width:100%">Sign in</button></p>
  </form>
</div>
