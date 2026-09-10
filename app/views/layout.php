<?php
/**
 * Shared page shell for both audiences.
 *
 * Merchants see their own store; Digifyce staff see every store. The header
 * differs, the rest does not.
 *
 * Deliberately plain for now. The analytics surface arrives in P4 and is what
 * a merchant judges the product on, so it deserves real design attention then.
 * What matters here is that data is legible and state is unambiguous.
 *
 * No build step, so styles are inline and there is no framework.
 *
 * @var string $title
 * @var callable $content
 */

// A merchant session wins: a signed request from Shopify is proof of who is
// looking, where a lingering staff cookie is only proof somebody once was.
$merchant = Merchant::check() ? Merchant::tenant() : null;
$user     = $merchant === null && Auth::check() ? Auth::user() : null;
$nav  = $nav ?? 'stores';
?>
<!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= htmlspecialchars($title) ?> — Retention Dashboard</title>
<style>
  :root {
    color-scheme: light dark;
    --bg:      #fbfbfa;  --fg:     #1b1b19;  --muted: #6d6d68;
    --line:    #e3e3df;  --panel:  #ffffff;  --accent:#1d5c46;
    --danger:  #a3241c;  --warn:   #8a6100;  --ok:    #157f3b;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg: #16171a; --fg: #e8e8e4; --muted: #9a9a94;
      --line: #2c2e33; --panel: #1d1f23; --accent: #6bbf9a;
    }
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; background: var(--bg); color: var(--fg);
    font: 15px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif;
  }
  header {
    border-bottom: 1px solid var(--line); background: var(--panel);
    padding: 0 20px; display: flex; align-items: center; gap: 26px;
  }
  header .brand { font-weight: 650; letter-spacing: -.01em; padding: 14px 0; }
  header nav { display: flex; gap: 20px; flex: 1; }
  header nav a {
    color: var(--muted); text-decoration: none; padding: 15px 0;
    border-bottom: 2px solid transparent; font-size: 14px;
  }
  header nav a.on { color: var(--fg); border-bottom-color: var(--accent); }
  header nav a:hover { color: var(--fg); }
  header .who { color: var(--muted); font-size: 13px; }
  header .who a { color: var(--muted); }

  main { max-width: 1040px; margin: 0 auto; padding: 28px 20px 80px; }
  h1 { font-size: 21px; margin: 0 0 4px; letter-spacing: -.01em; }
  h2 { font-size: 15px; margin: 32px 0 10px; }
  .sub { color: var(--muted); margin: 0 0 24px; }

  .panel {
    background: var(--panel); border: 1px solid var(--line);
    border-radius: 10px; padding: 20px; margin-bottom: 18px;
  }
  table { width: 100%; border-collapse: collapse; }
  th {
    text-align: left; font-size: 12px; text-transform: uppercase;
    letter-spacing: .04em; color: var(--muted); font-weight: 600;
    padding: 0 10px 8px; border-bottom: 1px solid var(--line);
  }
  td { padding: 11px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
  tr:last-child td { border-bottom: 0; }

  label { display: block; font-size: 13px; font-weight: 600; margin: 14px 0 5px; }
  .hint { font-weight: 400; color: var(--muted); font-size: 12px; margin-top: 3px; }
  input[type=text], input[type=email], input[type=password], select {
    width: 100%; padding: 9px 11px; font: inherit; color: var(--fg);
    background: var(--bg); border: 1px solid var(--line); border-radius: 7px;
  }
  input:focus, select:focus { outline: 2px solid var(--accent); outline-offset: -1px; }

  button, .btn {
    font: inherit; font-weight: 550; padding: 9px 16px; border-radius: 7px;
    border: 1px solid var(--line); background: var(--panel); color: var(--fg);
    cursor: pointer; text-decoration: none; display: inline-block;
  }
  button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
  button:hover, .btn:hover { filter: brightness(.97); }

  .flash { padding: 11px 15px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; }
  .flash.ok  { background: color-mix(in srgb, var(--ok) 12%, transparent);     border: 1px solid var(--ok); }
  .flash.err { background: color-mix(in srgb, var(--danger) 12%, transparent); border: 1px solid var(--danger); }

  code, pre { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; }
  code { font-size: 12.5px; background: color-mix(in srgb, var(--fg) 7%, transparent);
         padding: 2px 6px; border-radius: 4px; }
  pre {
    background: color-mix(in srgb, var(--fg) 5%, transparent);
    border: 1px solid var(--line); border-radius: 8px;
    padding: 14px; overflow-x: auto; font-size: 12.5px; line-height: 1.5;
  }
  .muted { color: var(--muted); }
  .pill  { font-size: 11px; padding: 2px 8px; border-radius: 20px;
           border: 1px solid var(--line); color: var(--muted); }
  .pill.live { color: var(--ok); border-color: var(--ok); }
</style>

<?php if ($merchant !== null): ?>
<!-- A merchant sees their own store and nothing else. No store switcher,
     because there is nothing to switch to, and no sign-out, because Shopify
     signs them in on arrival and "sign out" would only strand them. -->
<header>
  <span class="brand">Retention Dashboard</span>
  <nav>
    <a href="/" class="<?= $nav === 'dashboard' ? 'on' : '' ?>">Overview</a>
  </nav>
  <span class="who"><?= htmlspecialchars((string) $merchant['shop_domain']) ?></span>
</header>
<?php elseif ($user): ?>
<header>
  <span class="brand">Retention Dashboard <span class="pill">staff</span></span>
  <nav>
    <a href="/?p=stores" class="<?= $nav === 'stores' ? 'on' : '' ?>">Stores</a>
  </nav>
  <span class="who"><?= htmlspecialchars($user['name']) ?> · <a href="/?p=logout">Sign out</a></span>
</header>
<?php endif; ?>

<main>
<?php $content(); ?>
</main>
