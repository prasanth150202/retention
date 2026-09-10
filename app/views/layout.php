<?php
/**
 * Shared page shell for both audiences.
 *
 * Merchants see their own store; Digifyce staff see every store. The header
 * differs, the rest does not.
 *
 * The visual language is ported from the MadMinimalist customer-behaviour
 * report in the Pixel Analysis Shopify project: warm paper ground, terracotta
 * accent, letterspaced small-caps section rules with a short accent dash.
 *
 * That is an editorial report look rather than an admin-panel look, and it is
 * the right one here — a merchant reads this page, they do not operate it.
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
  /* ---------------------------------------------------------------
     Design ported from the MadMinimalist customer-behaviour report
     (Pixel Analysis Shopify). Warm paper ground, terracotta accent,
     letterspaced small-caps section rules. It is an editorial report
     look rather than an admin-panel look, which is right: this is
     something a merchant reads, not a console they operate.

     Single light palette, as the original has. A dark variant of a
     warm cream ground is its own design exercise, not a media query.
     --------------------------------------------------------------- */
  :root {
    color-scheme: light;
    --bg:     #f4f1ea;  --panel: #fffdf8;  --ink:  #1f1d1a;
    --muted:  #7c756a;  --line:  #e4ddcf;  --sunk: #ece5d6;
    --accent: #c2643b;  --accent-lite: #d98a5f;  --accent-wash: #e9cbb8;
    --good:   #3f7d54;  --bad:   #c0413b;  --warn: #c9912f;  --blue: #2f6f8f;
    --body:   #48433b;

    /* Older rules and views still speak in these names. */
    --fg: var(--ink);  --danger: var(--bad);  --ok: var(--good);
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; background: var(--bg); color: var(--ink);
    font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
    -webkit-font-smoothing: antialiased;
  }

  /* --- header ------------------------------------------------------ */
  header {
    border-bottom: 2px solid var(--ink); background: var(--panel);
    padding: 0 26px; display: flex; align-items: center; gap: 26px;
  }
  header .brand {
    font-weight: 800; letter-spacing: -.01em; padding: 15px 0; font-size: 16px;
  }
  header nav { display: flex; gap: 22px; flex: 1; }
  header nav a {
    color: var(--muted); text-decoration: none; padding: 16px 0;
    border-bottom: 2px solid transparent; font-size: 13px; font-weight: 600;
    letter-spacing: .02em;
  }
  header nav a.on { color: var(--ink); border-bottom-color: var(--accent); }
  header nav a:hover { color: var(--ink); }
  header .who { color: var(--muted); font-size: 12px; }
  header .who a { color: var(--accent); font-weight: 600; }

  main { max-width: 1180px; margin: 0 auto; padding: 34px 26px 80px; }

  /* --- editorial headings ------------------------------------------ */
  .eyebrow {
    font-size: 11px; letter-spacing: .22em; text-transform: uppercase;
    color: var(--accent); font-weight: 700; margin-bottom: 8px;
  }
  h1 {
    font-size: 30px; line-height: 1.08; margin: 0 0 6px;
    font-weight: 800; letter-spacing: -.02em;
  }
  /* The accent dash before every section rule is the signature of this
     design. It comes free on any <h2> a view writes. */
  h2 {
    font-size: 13px; letter-spacing: .16em; text-transform: uppercase;
    color: var(--muted); font-weight: 700; margin: 40px 0 16px;
    display: flex; align-items: center; gap: 10px;
  }
  h2::before {
    content: ""; width: 22px; height: 2px; background: var(--accent);
    display: inline-block; flex: none;
  }
  .sub {
    color: var(--muted); font-size: 14.5px; max-width: 760px; margin: 0 0 26px;
  }
  .sub b { color: var(--ink); }
  .page-head { border-bottom: 2px solid var(--ink); padding-bottom: 20px; margin-bottom: 26px; }
  .page-head .sub { margin-bottom: 0; }

  /* --- panels ------------------------------------------------------- */
  .panel {
    background: var(--panel); border: 1px solid var(--line);
    border-radius: 14px; padding: 20px 22px; margin-bottom: 16px;
  }

  /* --- tables ------------------------------------------------------- */
  table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
  th {
    text-align: left; font-size: 11px; text-transform: uppercase;
    letter-spacing: .06em; color: var(--muted); font-weight: 700;
    padding: 0 10px 9px; border-bottom: 1px solid var(--line);
  }
  td { padding: 9px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
  tr:last-child td { border-bottom: 0; }
  table.data th { white-space: nowrap; }
  table.data td.n, table.data th.n { text-align: right; font-variant-numeric: tabular-nums; }
  table.data tbody tr:hover td { background: #faf6ed; }
  table.data .name { font-weight: 600; }
  table.data .sub2 { font-size: 12px; color: var(--muted); }

  /* --- forms -------------------------------------------------------- */
  label { display: block; font-size: 13px; font-weight: 700; margin: 14px 0 5px; }
  .hint { font-weight: 400; color: var(--muted); font-size: 12px; margin-top: 3px; }
  input[type=text], input[type=email], input[type=password], select {
    width: 100%; padding: 9px 11px; font: inherit; color: var(--ink);
    background: var(--bg); border: 1px solid var(--line); border-radius: 8px;
  }
  input:focus, select:focus { outline: 2px solid var(--accent); outline-offset: -1px; }

  button, .btn {
    font: inherit; font-weight: 700; padding: 9px 16px; border-radius: 8px;
    border: 1px solid var(--line); background: var(--panel); color: var(--ink);
    cursor: pointer; text-decoration: none; display: inline-block;
  }
  button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
  button:hover, .btn:hover { filter: brightness(.97); }

  .flash { padding: 11px 15px; border-radius: 10px; margin-bottom: 18px; font-size: 14px; }
  .flash.ok  { background: #e1efe4; border: 1px solid var(--good); }
  .flash.err { background: #f6e1df; border: 1px solid var(--bad); }

  code, pre { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; }
  code { font-size: 12px; background: #efe9dc; padding: 1px 6px;
         border-radius: 5px; color: var(--accent); }
  pre {
    background: #efe9dc; border: 1px solid var(--line); border-radius: 10px;
    padding: 14px; overflow-x: auto; font-size: 12.5px; line-height: 1.5;
  }
  .muted { color: var(--muted); }

  .pill { display: inline-block; padding: 2px 9px; border-radius: 20px;
          font-size: 11px; font-weight: 700; background: #efe9dc; color: var(--muted); }
  .pill.live { background: #e1efe4; color: var(--good); }
  .pill.warn { background: #f7ecd3; color: var(--warn); }
  .pill.bad  { background: #f6e1df; color: var(--bad); }

  /* --- date range --------------------------------------------------- */
  .toolbar {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    margin: 0 0 22px;
  }
  .toolbar .ranges {
    display: flex; gap: 3px; max-width: 100%; overflow-x: auto; scrollbar-width: none;
  }
  .toolbar .ranges::-webkit-scrollbar { display: none; }
  .toolbar .ranges a {
    font-size: 12px; padding: 6px 12px; border-radius: 8px; white-space: nowrap;
    color: var(--muted); text-decoration: none; border: 1px solid transparent;
    font-weight: 600; letter-spacing: .02em;
  }
  .toolbar .ranges a.on {
    color: var(--ink); background: var(--panel); border-color: var(--line);
  }
  .toolbar .ranges a:hover { color: var(--ink); }
  .toolbar .spacer { flex: 1; }

  /* --- metric tiles -------------------------------------------------- */
  .tiles {
    display: grid; gap: 14px; margin-bottom: 16px;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  }
  .tile {
    background: var(--panel); border: 1px solid var(--line);
    border-radius: 14px; padding: 16px 18px;
  }
  .tile .k {
    font-size: 11px; letter-spacing: .08em; text-transform: uppercase;
    color: var(--muted); font-weight: 700;
    /* Two lines' worth, so a long label does not shunt its value below the
       values beside it. */
    min-height: 2.6em;
  }
  .tile .v {
    font-size: 27px; font-weight: 800; letter-spacing: -.02em;
    margin: 6px 0 3px; line-height: 1.1;
  }
  .tile .d { font-size: 12px; color: var(--muted); }
  .d.up   { color: var(--good); }
  .d.down { color: var(--bad); }

  /* One inverted tile per group, for the number the page is about. The
     original report does this for conversion rate, and it is what makes a
     row of tiles read as a headline rather than a wall. */
  .tile.accent { background: var(--ink); color: #fff; border-color: var(--ink); }
  .tile.accent .k, .tile.accent .d { color: #cfc7b8; }
  .tile.accent .d.up { color: #8fd3a4; }
  .tile.accent .d.down { color: #ec9b95; }

  /* --- funnel --------------------------------------------------------- */
  .funnel { display: flex; flex-direction: column; gap: 7px; }
  .fstep { display: grid; grid-template-columns: 218px 1fr 132px; gap: 12px; align-items: center; }
  .fstep .lbl { font-size: 13px; font-weight: 600; }
  .fbar { background: var(--sunk); border-radius: 7px; height: 30px; position: relative; overflow: hidden; }
  /* Two bars, one inside the other: the pale one is everyone who reached
     the step, the solid one those who took every earlier step in order.
     Strict is always a subset, so it nests rather than overlaps. */
  .fbar span {
    position: absolute; inset: 0 auto 0 0; border-radius: 7px;
    background: var(--accent-wash); min-width: 2px;
  }
  .fbar em {
    position: absolute; inset: 0 auto 0 0; border-radius: 7px;
    background: linear-gradient(90deg, var(--accent), var(--accent-lite));
    min-width: 2px;
  }
  /* A single bar with nothing nested inside it is not the pale "reached"
     half of a pair — it is the whole measure, so it takes the solid fill.
     The checkout micro-funnel draws one bar per stage. */
  .fbar span:only-child {
    background: linear-gradient(90deg, var(--accent), var(--accent-lite));
  }
  .fstep .n { font-size: 12px; text-align: right; color: var(--muted); }
  .fstep .n b { color: var(--ink); font-weight: 800; font-size: 14px; }
  .fdrop { font-size: 11.5px; color: var(--bad); font-weight: 600; }

  /* --- inline bar inside a table cell --------------------------------- */
  .minibar {
    height: 6px; border-radius: 6px; min-width: 2px;
    background: linear-gradient(90deg, var(--accent), var(--accent-lite));
  }

  /* --- cohort grid ----------------------------------------------------- */
  .cohort td.c { text-align: center; font-variant-numeric: tabular-nums; font-size: 13px; }
  .cohort td.c span {
    display: block; border-radius: 7px; padding: 6px 0; font-weight: 700;
    background: color-mix(in srgb, var(--accent) calc(var(--w) * 1.4%), transparent);
  }

  .empty { text-align: center; padding: 46px 20px; color: var(--muted); }
  .empty strong { display: block; color: var(--ink); margin-bottom: 6px; font-size: 16px; font-weight: 800; }

  /* A callout, not a grey box. The left rule is what makes it read as an
     aside rather than as more of the same. */
  .note {
    font-size: 13px; color: var(--body); background: var(--panel);
    border: 1px solid var(--line); border-left: 4px solid var(--accent);
    border-radius: 10px; padding: 13px 16px; margin: 0 0 16px;
  }
  .note b { color: var(--ink); font-weight: 800; }

  @media (max-width: 700px) {
    .fstep { grid-template-columns: 1fr; gap: 3px; }
    .fstep .n { text-align: left; }
    h1 { font-size: 24px; }

    /* The header has to wrap onto two rows here. Left as one flex row, the
       brand and the shop domain squeeze the navigation to nothing and the
       merchant cannot reach any tab but the one they are on. */
    header { flex-wrap: wrap; gap: 0 14px; padding: 0 16px; align-items: baseline; }
    header .brand { padding: 13px 0 6px; font-size: 15px; }
    header .who   { margin-left: auto; font-size: 11px; }
    header nav {
      order: 3; flex: 0 0 100%; gap: 18px;
      overflow-x: auto; scrollbar-width: none;
    }
    header nav::-webkit-scrollbar { display: none; }
    header nav a { padding: 6px 0 10px; white-space: nowrap; }

    main { padding: 22px 16px 60px; }

    /* Wide tables scroll inside their own panel. The page itself must never
       scroll sideways — a merchant swiping a report should move the report,
       not the whole page out from under the header.

       The shadows say there is more to see. Without them the retention cohort
       table shows "within 30 days" and nothing else on a phone, and a merchant
       reasonably concludes the 60, 90 and 180 day columns do not exist.
       Background-attachment does the work: the two `local` panels scroll with
       the content and cover the `scroll` shadows once an edge is reached, so a
       shadow appears only while there is something in that direction. No
       JavaScript, and nothing shown on a table that fits. */
    .panel {
      overflow-x: auto;
      background:
        linear-gradient(to right, var(--panel) 40%, rgba(255, 253, 248, 0)) left center / 44px 100% no-repeat local,
        linear-gradient(to left,  var(--panel) 40%, rgba(255, 253, 248, 0)) right center / 44px 100% no-repeat local,
        radial-gradient(farthest-side at 0 50%,   rgba(31, 29, 26, .13), rgba(31, 29, 26, 0)) left center / 16px 100% no-repeat scroll,
        radial-gradient(farthest-side at 100% 50%, rgba(31, 29, 26, .13), rgba(31, 29, 26, 0)) right center / 16px 100% no-repeat scroll,
        var(--panel);
    }
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
  <span class="who">
    <?php
    // Not a tab. Billing is something a merchant visits twice a year, and
    // giving it equal weight with the reports would say the wrong thing about
    // what this page is for.
    if (class_exists('Billing') && Billing::enabled()):
    ?>
      <a href="/?p=plan"<?= $nav === 'plan' ? ' style="color:var(--ink)"' : '' ?>>Plan</a> ·
    <?php endif; ?>
    <?= htmlspecialchars((string) $merchant['shop_domain']) ?>
  </span>
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
