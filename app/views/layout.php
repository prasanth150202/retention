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
  .pill.warn { color: var(--warn); border-color: var(--warn); }

  /* --- date range ------------------------------------------------ */
  .toolbar {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    margin: -6px 0 20px;
  }
  .toolbar .ranges { display: flex; gap: 2px; max-width: 100%; overflow-x: auto; scrollbar-width: none; }
  .toolbar .ranges::-webkit-scrollbar { display: none; }
  .toolbar .ranges a {
    font-size: 13px; padding: 5px 11px; border-radius: 7px; white-space: nowrap;
    color: var(--muted); text-decoration: none; border: 1px solid transparent;
  }
  .toolbar .ranges a.on {
    color: var(--fg); background: var(--panel); border-color: var(--line);
  }
  .toolbar .ranges a:hover { color: var(--fg); }
  .toolbar .spacer { flex: 1; }

  /* --- metric tiles ---------------------------------------------- */
  .tiles {
    display: grid; gap: 12px; margin-bottom: 18px;
    grid-template-columns: repeat(auto-fit, minmax(144px, 1fr));
  }
  .tile {
    background: var(--panel); border: 1px solid var(--line);
    border-radius: 10px; padding: 14px 16px;
  }
  .tile .k {
    font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em;
    color: var(--muted); font-weight: 600;
    /* Two lines' worth, so a long label does not shunt its value below the
       values beside it. */
    min-height: 2.7em;
  }
  .tile .v {
    font-size: 23px; font-weight: 600; letter-spacing: -.02em;
    margin: 5px 0 2px; line-height: 1.15;
  }
  .tile .d { font-size: 12px; color: var(--muted); }
  .d.up   { color: var(--ok); }
  .d.down { color: var(--danger); }

  /* --- funnel ----------------------------------------------------- */
  .funnel { display: flex; flex-direction: column; gap: 3px; }
  .fstep { display: grid; grid-template-columns: 218px 1fr 132px; gap: 14px; align-items: center; }
  .fstep .lbl { font-size: 13.5px; }
  .fbar { background: color-mix(in srgb, var(--fg) 6%, transparent); border-radius: 5px; height: 30px; position: relative; }
  .fbar span {
    position: absolute; inset: 0 auto 0 0; border-radius: 5px;
    background: var(--accent); opacity: .82; min-width: 2px;
  }
  .fbar em {
    position: absolute; inset: 0 auto 0 0; border-radius: 5px;
    background: var(--accent); opacity: .45; min-width: 1px;
  }
  .fstep .n { font-size: 13px; text-align: right; color: var(--muted); }
  .fstep .n b { color: var(--fg); font-weight: 600; }
  .fdrop { font-size: 12px; color: var(--danger); }

  /* --- data tables ------------------------------------------------ */
  table.data td, table.data th { padding: 9px 10px; }
  table.data th { white-space: nowrap; }
  table.data td.n, table.data th.n { text-align: right; font-variant-numeric: tabular-nums; }
  table.data tbody tr:hover td { background: color-mix(in srgb, var(--fg) 3%, transparent); }
  table.data .name { font-weight: 500; }
  table.data .sub2 { font-size: 12px; color: var(--muted); }

  /* --- inline bar inside a table cell ----------------------------- */
  .minibar { height: 5px; border-radius: 3px; background: var(--accent); opacity: .55; min-width: 2px; }

  /* --- cohort grid ------------------------------------------------ */
  .cohort td.c { text-align: center; font-variant-numeric: tabular-nums; font-size: 13px; }
  .cohort td.c span {
    display: block; border-radius: 5px; padding: 6px 0;
    background: color-mix(in srgb, var(--accent) calc(var(--w) * 1%), transparent);
  }

  .empty { text-align: center; padding: 46px 20px; color: var(--muted); }
  .empty strong { display: block; color: var(--fg); margin-bottom: 6px; font-size: 15px; }

  .note {
    font-size: 12.5px; color: var(--muted); background: color-mix(in srgb, var(--fg) 4%, transparent);
    border-radius: 8px; padding: 10px 13px; margin: 0 0 16px;
  }
  .note b { color: var(--fg); font-weight: 600; }

  @media (max-width: 700px) {
    .fstep { grid-template-columns: 1fr; gap: 3px; }
    .fstep .n { text-align: left; }

    /* The header has to wrap onto two rows here. Left as one flex row, the
       brand and the shop domain squeeze the navigation to nothing and the
       merchant cannot reach any tab but the one they are on. */
    header { flex-wrap: wrap; gap: 0 14px; padding: 0 14px; align-items: baseline; }
    header .brand { padding: 13px 0 6px; font-size: 15px; }
    header .who   { margin-left: auto; font-size: 12px; }
    header nav {
      order: 3; flex: 0 0 100%; gap: 18px;
      overflow-x: auto; scrollbar-width: none;
    }
    header nav::-webkit-scrollbar { display: none; }
    header nav a { padding: 6px 0 10px; white-space: nowrap; }

    main { padding: 20px 14px 60px; }

    /* Wide tables scroll inside their own panel. The page itself must never
       scroll sideways — a merchant swiping a report should move the report,
       not the whole page out from under the header. */
    .panel { overflow-x: auto; }
    .note, .empty { overflow-x: visible; }
  }
</style>

<?php if ($merchant !== null): ?>
<!-- A merchant sees their own store and nothing else. No store switcher,
     because there is nothing to switch to, and no sign-out, because Shopify
     signs them in on arrival and "sign out" would only strand them. -->
<header>
  <span class="brand">Retention Dashboard</span>
  <nav>
    <?php
    // Tabs are named after the question a merchant is asking, not after the
    // table behind them. "Checkout" is where they look when sales dip, and
    // nobody has ever gone looking for "rollup_daily_abandon".
    $keep = [];
    foreach (['from', 'to'] as $p) {
        if (isset($_GET[$p]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET[$p])) {
            $keep[$p] = $_GET[$p];
        }
    }
    foreach ([
        ''            => 'Overview',
        'funnel'      => 'Funnel',
        'campaigns'   => 'Campaigns',
        'products'    => 'Products',
        'retention'   => 'Retention',
        'checkout'    => 'Checkout',
        'geography'   => 'Geography',
    ] as $slug => $name):
        $href = '/' . ($slug === '' ? '' : '?p=' . $slug);
        if ($keep !== []) {
            $href .= ($slug === '' ? '?' : '&') . http_build_query($keep);
        }
    ?>
      <a href="<?= htmlspecialchars($href) ?>" class="<?= $nav === ($slug === '' ? 'dashboard' : $slug) ? 'on' : '' ?>"><?= $name ?></a>
    <?php endforeach; ?>
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
