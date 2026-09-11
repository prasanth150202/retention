<?php
/**
 * Project Odysseus — library self-test.
 *
 *   php bin/selftest.php [--env=.env.production]
 *
 * Exercises app/lib against the real databases: connections, shard routing,
 * the tenant guard, encryption, hashing and the event type map.
 *
 * Read-only against application tables. The only writes are to
 * shard_registry.bytes_used (a measurement) and, on a local run, a test
 * tenant salt which is removed afterwards.
 *
 * Exit code 0 = all passed, 1 = something failed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/lib/bootstrap.php';

$passed = 0;
$failed = 0;
$skipped = 0;

function check(string $label, callable $fn): void
{
    global $passed, $failed;
    try {
        $detail = $fn();
        $passed++;
        printf("  \e[32mPASS\e[0m  %-46s %s\n", $label, $detail ?? '');
    } catch (Throwable $e) {
        $failed++;
        printf("  \e[31mFAIL\e[0m  %-46s %s\n", $label, $e->getMessage());
    }
}

function skip(string $label, string $why): void
{
    global $skipped;
    $skipped++;
    printf("  \e[33mSKIP\e[0m  %-46s %s\n", $label, $why);
}

function assertTrue(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

/** Asserts the callable throws. Used for the guards, where NOT throwing is the bug. */
function assertThrows(callable $fn, string $msg): string
{
    try {
        $fn();
    } catch (Throwable $e) {
        return 'correctly refused';
    }
    throw new RuntimeException($msg);
}

$envFile = odysseus_env_arg();
odysseus_boot($envFile);

echo "\nProject Odysseus — self-test\n";
echo "Environment: " . ($envFile ?? '.env') . "\n";
echo str_repeat('-', 78) . "\n";

// -----------------------------------------------------------------------
echo "\nConfiguration\n";

check('config loads', fn() => 'core=' . Config::require('db.core'));
check('shard pattern has {year}', function () {
    assertTrue(str_contains(Config::require('db.shard'), '{year}'), 'DB_SHARD lacks {year}');
    return Config::require('db.shard');
});
check('mbstring is available', function () {
    // Text::fit() and Fmt::clip() are the only mb_* callers in the tree, and
    // everything written to a VARCHAR now goes through Text::fit(). Without
    // the extension those are undefined functions, so the products, campaigns
    // and geography tabs fatal and the importer stops storing anything.
    // Shared hosting normally has it; "normally" is not a guarantee, and a
    // fatal on the products tab is a poor way to find out.
    assertTrue(Text::mbstringAvailable(), 'ext-mbstring is not loaded');
    assertTrue(mb_internal_encoding() !== false, 'mbstring is loaded but not usable');

    return 'ext-mbstring present';
});
check('missing key throws', fn() => assertThrows(
    fn() => Config::require('db.definitely_not_here'),
    'Config::require returned a value for a missing key'
));

// -----------------------------------------------------------------------
echo "\nConnections\n";

check('core database', function () {
    $v = Db::core()->query('SELECT VERSION()')->fetchColumn();
    return 'MariaDB/MySQL ' . $v;
});
check('core is UTC', function () {
    $offset = Db::core()->query('SELECT @@session.time_zone')->fetchColumn();
    assertTrue($offset === '+00:00', "session time_zone is {$offset}, expected +00:00");
    return $offset;
});
check('connection pooling', function () {
    assertTrue(Db::core() === Db::core(), 'core() returned two different PDO instances');
    return 'same instance reused';
});

// -----------------------------------------------------------------------
echo "\nShard routing\n";

check('registry populated', function () {
    $all = Shard::all(true);
    assertTrue($all !== [], 'shard_registry has no provisioned shards');
    return count($all) . ' shard(s)';
});
check('today routes to a shard', function () {
    $s = Shard::forDate(gmdate('Y-m-d'));
    return $s['shard_name'] . ' -> ' . $s['physical_name'];
});
check('shard connection (own credentials)', function () {
    $pdo = Shard::connectionForDate(gmdate('Y-m-d'));
    $n = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events'"
    )->fetchColumn();
    assertTrue($n === 1, 'events table not found in the shard');
    return 'events table present';
});
check('events table still compressed', function () {
    $pdo = Shard::connectionForDate(gmdate('Y-m-d'));
    $row = $pdo->query(
        "SELECT ROW_FORMAT FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events'"
    )->fetch();
    assertTrue(
        strtolower((string) $row['ROW_FORMAT']) === 'compressed',
        'events is ' . $row['ROW_FORMAT'] . ', not Compressed — the shard lifetime estimate assumes compression'
    );
    return 'Compressed';
});
check('unroutable date fails loudly', fn() => assertThrows(
    fn() => Shard::forDate('1999-01-01'),
    'Shard::forDate silently accepted a date with no shard — events could be dropped'
));
check('range spanning years', function () {
    $s = Shard::forRange('2026-01-01', '2027-12-31');
    return count($s) . ' shard(s) touched';
});

// -----------------------------------------------------------------------
echo "\nTenant isolation guard\n";

check('unscoped query on events refused', fn() => assertThrows(
    fn() => Db::assertTenantScoped('SELECT COUNT(*) FROM events'),
    'A query against events without :tenant_id was allowed'
));
check('unscoped query on orders refused', fn() => assertThrows(
    fn() => Db::assertTenantScoped('SELECT * FROM orders WHERE created_at > :from'),
    'A query against orders without :tenant_id was allowed'
));
check('unscoped JOIN refused', fn() => assertThrows(
    fn() => Db::assertTenantScoped('SELECT * FROM persons p JOIN identity_keys k ON 1=1'),
    'A JOIN across tenant-scoped tables without :tenant_id was allowed'
));
check('scoped query permitted', function () {
    Db::assertTenantScoped('SELECT * FROM orders WHERE tenant_id = :tenant_id');
    return 'allowed';
});
check('non-tenant table unaffected', function () {
    Db::assertTenantScoped('SELECT * FROM shard_registry');
    return 'allowed';
});
check('tenantQuery rejects tenant id 0', fn() => assertThrows(
    fn() => Db::tenantQuery(Db::core(), 'SELECT 1 FROM orders WHERE tenant_id = :tenant_id', 0),
    'tenantQuery accepted tenant id 0'
));
check('tenantQuery executes when scoped', function () {
    $stmt = Db::tenantQuery(
        Db::core(),
        'SELECT COUNT(*) AS n FROM orders WHERE tenant_id = :tenant_id',
        1
    );
    return 'returned ' . $stmt->fetch()['n'] . ' row(s) for tenant 1';
});

// -----------------------------------------------------------------------
echo "\nHashing and normalisation\n";

check('phone: +91 stripped', function () {
    $r = Hash::normalisePhone('+91 98765 43210');
    assertTrue($r === '9876543210', "got " . var_export($r, true));
    return $r;
});
check('phone: punctuation stripped', function () {
    assertTrue(Hash::normalisePhone('098765-43210') === '9876543210', 'leading zero form failed');
    return '9876543210';
});
check('phone: too short rejected', function () {
    assertTrue(Hash::normalisePhone('12345') === null, 'a 5-digit number was accepted');
    return 'null';
});
check('email: lowercased and trimmed', function () {
    assertTrue(Hash::normaliseEmail('  Ayman@Digifyce.COM ') === 'ayman@digifyce.com', 'failed');
    return 'ayman@digifyce.com';
});
check('email: gmail dots NOT stripped', function () {
    // Deliberate: dot-stripping is a guess about provider routing, and a
    // wrong guess merges two different people into one customer.
    assertTrue(Hash::normaliseEmail('a.b@gmail.com') === 'a.b@gmail.com', 'dots were stripped');
    return 'preserved';
});
check('uid64 deterministic and positive', function () {
    $a = Hash::uid64('shopify-event-abc123');
    $b = Hash::uid64('shopify-event-abc123');
    assertTrue($a === $b, 'not deterministic');
    assertTrue($a > 0, 'not positive: ' . $a);
    assertTrue($a <= PHP_INT_MAX, 'exceeds PHP_INT_MAX');
    return (string) $a;
});
check('uid64 distinct for distinct input', function () {
    assertTrue(Hash::uid64('a') !== Hash::uid64('b'), 'collision on trivial input');
    return 'ok';
});
check('dim hash is 16 bytes', function () {
    assertTrue(strlen(Hash::dim('/collections/all')) === 16, 'wrong length');
    return '16 bytes';
});

$testTenant = 999999;
check('pii hash deterministic, 16 bytes', function () use ($testTenant) {
    $a = Hash::pii($testTenant, '9876543210');
    $b = Hash::pii($testTenant, '9876543210');
    assertTrue($a === $b, 'not deterministic');
    assertTrue(strlen($a) === 16, 'wrong length: ' . strlen($a));
    return '16 bytes, stable';
});
check('pii hash differs across tenants', function () use ($testTenant) {
    // Per-tenant salting is what stops the same person being correlated
    // across two clients' data.
    $a = Hash::pii($testTenant, '9876543210');
    $b = Hash::pii($testTenant + 1, '9876543210');
    assertTrue($a !== $b, 'same digest for two tenants — salts are not per-tenant');
    return 'isolated';
});

// -----------------------------------------------------------------------
echo "\nEncryption\n";

if (!Crypto::available()) {
    skip('token encryption round-trip', 'no secrets/master.key — run: php bin/keygen.php');
    skip('tampered ciphertext rejected', 'no secrets/master.key');
} else {
    check('token encryption round-trip', function () {
        $secret = 'shpat_' . bin2hex(random_bytes(16));
        assertTrue(Crypto::decrypt(Crypto::encrypt($secret)) === $secret, 'round-trip mismatch');
        return 'AES-256-GCM';
    });
    check('tampered ciphertext rejected', function () {
        $blob = Crypto::encrypt('sensitive');
        $blob[strlen($blob) - 1] = chr(ord($blob[strlen($blob) - 1]) ^ 0xFF);
        return assertThrows(
            fn() => Crypto::decrypt($blob),
            'A modified ciphertext decrypted without error — GCM authentication is not working'
        );
    });
}

// -----------------------------------------------------------------------
echo "\nEvent types\n";

check('pixel name maps to code', function () {
    $t = EventType::fromName('checkout_completed', EventType::SOURCE_PIXEL);
    assertTrue($t === EventType::CHECKOUT_COMPLETED, 'wrong code: ' . var_export($t, true));
    return 'checkout_completed = ' . $t;
});
check('PII-bearing events not subscribed', function () {
    // input_changed / input_blurred carry raw element.value and leaked 2,626
    // emails in the earlier export. They must not resolve to a stored type.
    foreach (['input_changed', 'input_blurred', 'input_focused', 'alert_displayed'] as $n) {
        assertTrue(
            EventType::fromName($n, EventType::SOURCE_PIXEL) === null,
            "{$n} resolved to a storable event type — it carries raw PII"
        );
    }
    return 'all four rejected';
});
check('feed namespaces disjoint', function () {
    // If a Liquid event could resolve to a funnel code it would double-count
    // every funnel step against the pixel feed.
    foreach (['identify', 'link_click', 'internal_search'] as $n) {
        $t = EventType::fromName($n, EventType::SOURCE_LIQUID);
        assertTrue($t !== null && $t >= 64, "{$n} is not in the liquid range");
        assertTrue(!EventType::isFunnelStep($t), "{$n} counts as a funnel step");
    }
    return 'liquid feed cannot enter the funnel';
});
check('funnel steps ordered', function () {
    assertTrue(array_keys(EventType::FUNNEL_STEPS) === [1, 2, 3, 4, 5, 6], 'steps out of order');
    return '6 steps';
});

// -----------------------------------------------------------------------
echo "\nEvent storage seam\n";

/**
 * Where raw events live is a deferred decision — shared-hosting shards today,
 * possibly a VPS or a columnar store once install counts grow. That deferral
 * is only cheap while Shard remains the single place that knows which
 * database an event row belongs to.
 *
 * This is a static scan rather than a runtime check, because the failure it
 * guards against is somebody writing a direct query in a new file, which no
 * runtime test would ever execute.
 */
check('events queried only through Shard', function () {
    $root  = dirname(__DIR__);
    $dirs  = ['/app', '/bin', '/public_html'];
    $bad   = [];

    // Files permitted to name the events table without routing through Shard,
    // because they ARE the seam or they define it.
    $allow = ['Shard.php', 'Migrator.php', 'selftest.php'];

    foreach ($dirs as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $dir));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $name = $file->getBasename();
            if (in_array($name, $allow, true)) {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());

            // Scan only string literals. An earlier version matched raw source
            // and flagged Dim.php for the phrase "from events" in a comment —
            // allowlisting the file would have blinded this check to a real
            // violation there later, so the scan is narrowed instead.
            $sql = '';
            foreach (token_get_all($src) as $token) {
                if (is_array($token) && in_array($token[0], [
                    T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML,
                ], true)) {
                    $sql .= ' ' . $token[1];
                }
            }

            // Does it write SQL against the events table?
            if (!preg_match('/\b(FROM|INTO|UPDATE|JOIN)\s+`?events`?\b/i', $sql)) {
                continue;
            }
            // If so, it must obtain its connection from Shard.
            if (!str_contains($src, 'Shard::')) {
                $bad[] = $name;
            }
        }
    }

    assertTrue(
        $bad === [],
        'these query the events table without going through Shard: '
        . implode(', ', array_unique($bad))
        . ' — route the connection through Shard::forDate() or '
        . 'Shard::connectionForDate(), or the storage layer can no longer be '
        . 'swapped without hunting for stray queries.'
    );

    return 'no direct queries outside the seam';
});

check('shard router resolves by date, not by config', function () {
    // Shard::forDate must consult shard_registry rather than rebuilding the
    // name from DB_SHARD, otherwise a migrated store silently reads the wrong
    // database.
    $src = (string) file_get_contents(dirname(__DIR__) . '/app/lib/Shard.php');
    assertTrue(str_contains($src, 'shard_registry'), 'Shard does not read shard_registry');
    return 'reads shard_registry';
});

// -----------------------------------------------------------------
echo "\nShopify OAuth\n";

/** Build a callback query string signed the way Shopify signs one. */
$signed = static function (array $params, string $secret): string {
    ksort($params);
    $parts = [];
    foreach ($params as $k => $v) {
        $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
    }
    $msg = implode('&', $parts);
    return $msg . '&hmac=' . hash_hmac('sha256', $msg, $secret);
};

$oauthSecret = 'shpss_test_secret_abcdef123456';
$oauthParams = [
    'code' => 'authcode123', 'shop' => 'demo.myshopify.com',
    'state' => 'statenonce', 'timestamp' => '1757400000',
];
$signedQs = $signed($oauthParams, $oauthSecret);

check('valid callback signature accepted', function () use ($signedQs, $oauthSecret) {
    assertTrue(ShopifyOAuth::verifyHmac($signedQs, $oauthSecret), 'a genuine callback was rejected');
    return 'verified';
});
check('forged callback rejected', function () use ($signedQs) {
    assertTrue(!ShopifyOAuth::verifyHmac($signedQs, 'wrong_secret'), 'wrong secret was accepted');
    return 'correctly refused';
});
check('tampered shop rejected', function () use ($signedQs, $oauthSecret) {
    // The attack this exists to stop: pointing a valid install at another shop.
    $evil = str_replace('demo.myshopify', 'evil.myshopify', $signedQs);
    assertTrue(!ShopifyOAuth::verifyHmac($evil, $oauthSecret), 'a substituted shop was accepted');
    return 'correctly refused';
});
check('appended parameter rejected', function () use ($signedQs, $oauthSecret) {
    assertTrue(!ShopifyOAuth::verifyHmac($signedQs . '&extra=1', $oauthSecret), 'extra param accepted');
    return 'correctly refused';
});
check('legacy signature param excluded', function () use ($signedQs, $oauthSecret) {
    // Shopify excludes 'signature' from the digest; including it breaks real callbacks.
    assertTrue(ShopifyOAuth::verifyHmac($signedQs . '&signature=legacy', $oauthSecret), 'failed');
    return 'ignored, as Shopify does';
});
check('percent-encoded values verify', function () use ($signed, $oauthParams, $oauthSecret) {
    $qs = $signed($oauthParams + ['note' => 'a b&c=d'], $oauthSecret);
    assertTrue(ShopifyOAuth::verifyHmac($qs, $oauthSecret), 'encoding round-trip lost bytes');
    return 'verified';
});
check('shop domain normalised', function () {
    assertTrue(ShopifyOAuth::normaliseShop('demo') === 'demo.myshopify.com', 'bare name');
    assertTrue(ShopifyOAuth::normaliseShop('https://DEMO.myshopify.com/') === 'demo.myshopify.com', 'url form');
    return 'demo.myshopify.com';
});
check('non-Shopify domain refused', fn() => assertThrows(
    fn() => ShopifyOAuth::normaliseShop('evil.com'),
    'an arbitrary domain was accepted as a shop'
));
check('PHP scopes match shopify.app.toml', function () {
    // Under managed installation Shopify grants what the TOML declares while
    // the app requests ShopifyOAuth::SCOPES. If they drift, the app asks for
    // one set and receives another and nothing raises an error.
    $r = ShopifyOAuth::scopesMatchToml();
    assertTrue($r['match'],
        'scope drift — only in PHP: [' . implode(', ', $r['only_in_php'])
        . '], only in TOML: [' . implode(', ', $r['only_in_toml']) . ']');
    return count(ShopifyOAuth::SCOPES) . ' scopes, both files agree';
});
check('pixel scopes present', function () {
    // Without these two, webPixelCreate fails and the merchant has to install
    // a pixel by hand — which is the entire thing this app exists to avoid.
    foreach (['write_pixels', 'read_customer_events'] as $s) {
        assertTrue(in_array($s, ShopifyOAuth::SCOPES, true), "{$s} is missing");
    }
    return 'write_pixels + read_customer_events';
});
check('missing read_all_orders detected', function () {
    // Silent otherwise: the install succeeds, the API returns 60 days, and
    // every cohort chart is empty for reasons nobody traces back to here.
    $p = ShopifyOAuth::verifyScopes('read_orders,read_customers,read_products');
    assertTrue($p['history_limited'] === true, 'not flagged');

    // read_all_orders is not in SCOPES while approval is pending, so our own
    // declared scopes are NOT a full grant — history is still limited. Saying
    // otherwise is the failure mode that matters, because it would hide the
    // banner on every store at exactly the time every store needs it.
    $ours = ShopifyOAuth::verifyScopes(implode(',', ShopifyOAuth::SCOPES));
    assertTrue($ours['missing'] === [], 'our own scope list did not verify clean');
    assertTrue(
        $ours['history_limited'] === !in_array('read_all_orders', ShopifyOAuth::SCOPES, true),
        'history_limited disagrees with whether read_all_orders is declared'
    );

    // Once Shopify approves it and a store grants it, the flag goes quiet.
    $full = ShopifyOAuth::verifyScopes(implode(',', ShopifyOAuth::SCOPES) . ',read_all_orders');
    assertTrue($full['history_limited'] === false, 'false positive on a full grant');

    return in_array('read_all_orders', ShopifyOAuth::SCOPES, true)
        ? 'flagged when absent, quiet when granted'
        : 'flagged while approval is pending, quiet once granted';
});

// -----------------------------------------------------------------
echo "\nPixel / importer contract\n";

/**
 * The web pixel decides what an event looks like; import.php decides how to
 * read it. If one renames a field and the other does not, events keep
 * arriving, keep importing, and quietly lose a column — no error anywhere.
 *
 * This compares the two directly. It is the reason the extension lives in
 * this repository rather than its own.
 */
$pixelJs = dirname(__DIR__) . '/shopify/extensions/retention-pixel/src/index.js';
$importP = dirname(__DIR__) . '/app/cron/import.php';

check('pixel extension present', function () use ($pixelJs) {
    assertTrue(is_file($pixelJs), 'web pixel source not found at ' . $pixelJs);
    return 'shopify/extensions/retention-pixel';
});

check('every field the pixel emits is read by the importer', function () use ($pixelJs, $importP) {
    $js  = (string) file_get_contents($pixelJs);
    $php = (string) file_get_contents($importP);

    // Fields are set two ways and BOTH must be collected. An earlier version
    // only matched `out.x =` and reported "all consumed" having checked nine
    // of sixteen — a test that passes while missing the mismatch it exists
    // to catch is worse than no test.
    $emitted = [];

    // 1. the object literal: const out = { n: …, s: …, id: … }
    if (preg_match('/const\s+out\s*=\s*\{(.*?)\n\s*\};/s', $js, $lit)) {
        preg_match_all('/^\s*([a-z]{1,3})\s*:/mi', $lit[1], $keys);
        $emitted = array_merge($emitted, $keys[1] ?? []);
    }

    // 2. later assignments: out.x = …
    preg_match_all('/\bout\.([a-z]{1,3})\s*=/i', $js, $assign);
    $emitted = array_merge($emitted, $assign[1] ?? []);

    $emitted = array_values(array_unique($emitted));

    assertTrue($emitted !== [], 'no emitted fields found — has the pixel been rewritten?');
    assertTrue(
        count($emitted) >= 14,
        'only ' . count($emitted) . ' fields detected (' . implode(',', $emitted)
        . ') — the extraction is probably missing a form again, not the pixel shrinking'
    );

    $unread = [];
    foreach ($emitted as $f) {
        // import.php reads them as $e['x'] in mapEvent().
        if (!preg_match("/\\\$e\\['" . preg_quote($f, '/') . "'\\]/", $php)) {
            $unread[] = $f;
        }
    }

    assertTrue(
        $unread === [],
        'the pixel sends fields the importer ignores: ' . implode(', ', $unread)
        . ' — either read them in mapEvent() or stop sending them, because right '
        . 'now that data is being collected and discarded.'
    );

    return count($emitted) . ' fields, all consumed';
});

check('pixel omits the PII-bearing events', function () use ($pixelJs) {
    // input_changed and friends carry raw element.value: real emails and phone
    // numbers as they are typed. Their absence is what stops that data ever
    // reaching us.
    $js = (string) file_get_contents($pixelJs);
    foreach (['input_changed', 'input_blurred', 'input_focused', 'alert_displayed'] as $e) {
        assertTrue(!str_contains($js, "'{$e}'"), "the pixel subscribes to {$e}, which carries raw PII");
    }
    return 'all four absent';
});

check('pixel captures the order id', function () use ($pixelJs) {
    // checkout.order.id is the join between behaviour and revenue. Losing it
    // leaves orders and browsing as two unrelated piles of data.
    $js = (string) file_get_contents($pixelJs);
    assertTrue(str_contains($js, 'd.checkout.order'), 'the order id is not captured');
    assertTrue(str_contains($js, 'checkout_completed'), 'checkout_completed is not subscribed');
    return 'behaviour joins to revenue';
});

check('extension declares consent categories honestly', function () {
    // Shopify gates the pixel on these. Over-declaring collects less for no
    // reason; under-declaring collects without the consent that applies.
    $toml = (string) @file_get_contents(
        dirname(__DIR__) . '/shopify/extensions/retention-pixel/shopify.extension.toml'
    );
    assertTrue(str_contains($toml, 'runtime_context = "strict"'), 'runtime_context must be strict');
    assertTrue(preg_match('/^\s*analytics\s*=\s*true/m', $toml) === 1, 'analytics consent not declared');
    assertTrue(preg_match('/^\s*marketing\s*=\s*false/m', $toml) === 1,
        'marketing is declared true, but this app does no ad targeting');
    return 'analytics only, strict sandbox';
});

// -----------------------------------------------------------------
echo "\nWebhook verification\n";

$hookSecret = 'shpss_webhook_test_secret_0123456789';
$hookBody   = '{"id":820982911946154508,"shop_domain":"demo.myshopify.com"}';
$hookSig    = base64_encode(hash_hmac('sha256', $hookBody, $hookSecret, true));

check('genuine webhook accepted', function () use ($hookBody, $hookSig, $hookSecret) {
    assertTrue(Webhook::verify($hookBody, $hookSig, $hookSecret), 'a real webhook was rejected');
    return 'verified';
});
check('forged webhook rejected', function () use ($hookBody, $hookSig) {
    assertTrue(!Webhook::verify($hookBody, $hookSig, 'wrong_secret'), 'wrong secret accepted');
    return 'correctly refused';
});
check('tampered body rejected', function () use ($hookBody, $hookSig, $hookSecret) {
    // The attack this stops: telling us a store uninstalled, or asking us to
    // delete a merchant's data.
    $evil = str_replace('demo.myshopify.com', 'victim.myshopify.com', $hookBody);
    assertTrue(!Webhook::verify($evil, $hookSig, $hookSecret), 'a modified body was accepted');
    return 'correctly refused';
});
check('missing signature rejected', function () use ($hookBody, $hookSecret) {
    assertTrue(!Webhook::verify($hookBody, '', $hookSecret), 'unsigned body accepted');
    return 'correctly refused';
});
check('webhook scheme differs from OAuth scheme', function () use ($hookBody, $hookSig, $hookSecret) {
    // Webhooks are a base64 HMAC over the raw body; OAuth is a hex HMAC over
    // sorted query params. Using the OAuth verifier on a webhook would reject
    // every genuine delivery, so this asserts they are not interchangeable.
    assertTrue(!ShopifyOAuth::verifyHmac($hookBody, $hookSecret), 'the two schemes were conflated');
    return 'base64-over-body vs hex-over-query';
});

// -----------------------------------------------------------------
// Webhook delivery bookkeeping
//
// A verified signature only settles who sent it. What happens after that —
// whether the work is done, and whether a retry does it again — is the part
// that decides if a customer is actually erased.
//
// These write to compliance_requests, which on a live install is a legal
// audit log, so they only run locally.
if ($envFile !== null) {
    skip('an interrupted deletion is done on the retry', 'writes to the compliance log; local runs only');
    skip('a finished deletion is not repeated', 'local runs only');
    skip('an unfinished request is reported to somebody', 'local runs only');
    skip('a long non-ASCII address can be recorded', 'local runs only');
} else {
    $cmpPdo  = Db::core();
    $cmpShop = 'selftest-compliance.myshopify.com';
    $cmpPdo->prepare('DELETE FROM compliance_requests WHERE shop_domain = ?')->execute([$cmpShop]);

    $cmpBody = static fn(string $tag): string => json_encode(['shop_domain' => 'x', 'tag' => $tag]);

    check('an interrupted deletion is done on the retry', function () use ($cmpShop, $cmpBody) {
        // The handler records the request BEFORE it starts deleting. If a row
        // is treated as proof the work happened, then one timeout — on a large
        // store, mid-deploy, a dropped connection — converts into a permanent
        // refusal: Shopify retries, we answer "already handled", and the
        // customer is never erased. Nothing anywhere notices.
        $raw = $cmpBody('interrupted');

        Webhook::logCompliance('customers/redact', $cmpShop, $raw, null, '9001');

        assertTrue(
            !Webhook::completedBefore('customers/redact', $raw),
            'a request that was logged and never finished counted as done'
        );

        return 'the retry does the work';
    });

    check('a finished deletion is not repeated', function () use ($cmpShop, $cmpBody) {
        // The other half. Shopify can deliver the same webhook twice on
        // success, and redoing a completed deletion is pointless work on a
        // path that deletes things.
        $raw = $cmpBody('finished');
        $id  = Webhook::logCompliance('customers/redact', $cmpShop, $raw, null, '9002');
        Webhook::completeCompliance($id, 3, 'done');

        assertTrue(
            Webhook::completedBefore('customers/redact', $raw),
            'a completed request would have been run again'
        );

        // Same topic, different body: a different customer, not a duplicate.
        assertTrue(
            !Webhook::completedBefore('customers/redact', $cmpBody('someone-else')),
            'a different customer was mistaken for a duplicate delivery'
        );

        return 'deduplicated on the exact delivery';
    });

    check('an unfinished request is reported to somebody', function () use ($cmpPdo, $cmpShop, $cmpBody) {
        // Shopify stops retrying after 48 hours. After that this table is the
        // only place the obligation still exists, so something has to read it.
        $raw = $cmpBody('stale');
        $id  = Webhook::logCompliance('shop/redact', $cmpShop, $raw, null, null);

        $cmpPdo->prepare('UPDATE compliance_requests SET received_at = ? WHERE request_id = ?')
            ->execute([gmdate('Y-m-d H:i:s', time() - 4 * 86400), $id]);

        $ids = array_column(Webhook::outstanding(24), 'request_id');
        assertTrue(in_array($id, array_map('intval', $ids), true), 'a four-day-old request went unreported');

        // A fresh one is not yet a problem: Shopify is still retrying.
        $fresh = Webhook::logCompliance('shop/redact', $cmpShop, $cmpBody('fresh'), null, null);
        $ids   = array_map('intval', array_column(Webhook::outstanding(24), 'request_id'));
        assertTrue(!in_array($fresh, $ids, true), 'a request from a minute ago was raised as stuck');

        return 'raised after a day, not before';
    });

    check('a long non-ASCII address can be recorded', function () use ($cmpPdo, $cmpShop, $cmpBody) {
        // subject_ref is VARCHAR(191) — 191 CHARACTERS. substr() counts BYTES,
        // so cutting a Devanagari or Japanese address at 191 bytes lands
        // mid-character, and a strict connection refuses the invalid UTF-8.
        // The insert is the first thing the handler does, so the whole
        // mandatory webhook fails and keeps failing until Shopify gives up.
        $email = str_repeat("\u{0917}", 70) . '@example.com';
        $raw   = $cmpBody('long-address');

        $id = Webhook::logCompliance('customers/data_request', $cmpShop, $raw, null, Text::fitOrNull($email, 191));
        assertTrue($id > 0, 'the request could not be recorded at all');

        $stmt = $cmpPdo->prepare('SELECT subject_ref FROM compliance_requests WHERE request_id = ?');
        $stmt->execute([$id]);
        $stored = (string) $stmt->fetchColumn();

        assertTrue(mb_check_encoding($stored, 'UTF-8'), 'the stored address is not valid UTF-8');
        assertTrue(mb_strlen($stored, 'UTF-8') <= 191, 'the stored address is longer than the column');

        // The round trip above proves the column accepts what Text produces.
        // It does not prove the HANDLER uses it, and the handler is where the
        // byte-cut was. Assert the entry point itself, because a substr() put
        // back here fails in production and nowhere else.
        $src = (string) file_get_contents(dirname(__DIR__) . '/public_html/webhooks/compliance.php');
        assertTrue(
            str_contains($src, 'Text::fitOrNull($ref, 191)'),
            'compliance.php no longer fits the subject reference to the column'
        );
        assertTrue(
            !preg_match('/substr\(\s*\$ref/', $src),
            'compliance.php truncates the subject reference by bytes again'
        );

        return mb_strlen($stored, 'UTF-8') . ' characters, and the handler fits it';
    });

    $cmpPdo->prepare('DELETE FROM compliance_requests WHERE shop_domain = ?')->execute([$cmpShop]);
}

// -----------------------------------------------------------------
echo "\nMerchant session\n";

check('no session without app credentials', function () {
    // An app with no client secret cannot verify anything, so it must not
    // establish sessions on trust. This is also the state a fresh checkout is
    // in, which is why it is asserted rather than assumed.
    if (ShopifyOAuth::configured()) {
        return 'credentials present; see the signed-request checks below';
    }

    assertTrue(
        Merchant::fromSignedRequest(
            ['shop' => 'demo.myshopify.com', 'hmac' => str_repeat('a', 64)],
            'hmac=' . str_repeat('a', 64) . '&shop=demo.myshopify.com'
        ) === null,
        'a session was created with no secret to verify against'
    );

    return 'refuses to trust anything';
});

// The two below exercise Merchant::fromSignedRequest() end to end, which needs
// a configured client secret — it refuses before reaching the signature check
// without one. They are SKIPPED rather than quietly passing when it is absent:
// a green tick for a code path that returned early at the second line is worse
// than no tick at all, because it reads as proof the signature was checked.
if (!ShopifyOAuth::configured()) {
    skip('unsigned request creates no session', 'needs SHOPIFY_CLIENT_SECRET; HMAC itself is covered above');
    skip('stale signed request refused', 'needs SHOPIFY_CLIENT_SECRET');
} else {
    check('unsigned request creates no session', function () {
        assertTrue(
            Merchant::fromSignedRequest(['shop' => 'demo.myshopify.com'], 'shop=demo.myshopify.com') === null,
            'a session was created without a signature'
        );
        return 'correctly refused';
    });
    check('stale signed request refused', function () use ($signed) {
        // A signed URL is a bearer credential while it verifies. Bounding its
        // age means one leaked into a browser history or a support ticket
        // expires.
        $secret = (string) Config::get('shopify.client_secret');
        $old    = ['shop' => 'demo.myshopify.com', 'timestamp' => (string) (time() - 8000)];
        $qs     = $signed($old, $secret);
        parse_str($qs, $parsed);
        assertTrue(Merchant::fromSignedRequest($parsed, $qs) === null, 'a stale link was accepted');
        return 'correctly refused';
    });
}

// -----------------------------------------------------------------
echo "\nToken lifecycle\n";

check('expiring-token columns exist', function () {
    // Public apps cannot use non-expiring tokens. Without these the app
    // authenticates once and breaks exactly one hour later.
    $cols = Db::core()->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach (['refresh_token_enc', 'token_expires_at', 'refresh_expires_at'] as $c) {
        assertTrue(in_array($c, $cols, true), "tenants.{$c} is missing");
    }
    return 'refresh token + both expiries';
});
check('uninstall and billing columns exist', function () {
    $cols = Db::core()->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach (['uninstalled_at', 'purge_after', 'purged_at',
              'plan', 'billing_status', 'trial_ends_at'] as $c) {
        assertTrue(in_array($c, $cols, true), "tenants.{$c} is missing");
    }
    return 'lifecycle + billing';
});
check('a refresh that states nothing keeps the store alive', function () {
    // RFC 6749 §6 lets a refresh response carry nothing but the new access
    // token. storeTokens() writes exactly what it is given — correctly, since
    // on install a missing expiry really does mean a token that never expires
    // — so something has to turn "absent" back into "unchanged" first.
    //
    // Getting this wrong is silent. A null token_expires_at reads as "never
    // expires", accessToken() stops refreshing the store, and the token dies
    // for real a day later with nothing retrying or reporting it.
    $row = [
        'token_expires_at'   => gmdate('Y-m-d H:i:s', time() + 60),
        'refresh_expires_at' => gmdate('Y-m-d H:i:s', time() + 5184000),   // 60 days
    ];

    $out = Tenant::carryForward([
        'access_token'             => 'shpat_new',
        'refresh_token'            => 'shprt_same',
        'expires_in'               => null,
        'refresh_token_expires_in' => null,
    ], $row);

    assertTrue($out['expires_in'] !== null, 'the new access token was stored as never expiring');
    assertTrue($out['expires_in'] > 0, 'the new access token was stored already expired');

    // Not the OLD expiry: the access token is new, and carrying forward a
    // value that is 60 seconds from expiry would ask for another refresh
    // immediately, and the one after that, forever.
    assertTrue($out['expires_in'] > 300, 'the assumed lifetime is inside the refresh margin, so it would loop');

    // The refresh token IS the same one, so its expiry is genuinely unchanged
    // and should be restated rather than guessed.
    $days = (int) round($out['refresh_token_expires_in'] / 86400);
    assertTrue($days === 60, "the refresh token expiry became {$days} days instead of 60");

    // And a response that DOES state them is left alone.
    $stated = Tenant::carryForward([
        'access_token'             => 'shpat_new',
        'refresh_token'            => 'shprt_new',
        'expires_in'               => 86400,
        'refresh_token_expires_in' => 7776000,
    ], $row);

    assertTrue($stated['expires_in'] === 86400, 'a stated lifetime was overwritten');
    assertTrue($stated['refresh_token_expires_in'] === 7776000, 'a stated refresh lifetime was overwritten');

    return 'absent means unchanged, stated is honoured';
});

if ($envFile !== null) {
    skip('a valid token is used without contacting Shopify', 'writes a test store; local runs only');
    skip('a store unreachable for 90 days is told to reinstall', 'local runs only');
} else {
    $tokShop = 'selftest-token.myshopify.com';
    $tokPdo  = Db::core();

    check('a valid token is used without contacting Shopify', function () use ($tokPdo, $tokShop) {
        $tokPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$tokShop]);
        $t = Tenant::upsert($tokShop, [
            'access_token'             => 'shpat_valid',
            'refresh_token'            => 'shprt_valid',
            'expires_in'               => 86400,
            'refresh_token_expires_in' => 7776000,
        ], 'read_orders');
        Tenant::forgetCache();

        try {
            // Six hours of life left: handing this straight back is the whole
            // point. Refreshing a token that works would be a token request on
            // every cron run, for every store.
            assertTrue(Tenant::accessToken($t) === 'shpat_valid', 'a good token was not returned');

            // Inside the margin it must reach for Shopify instead. There is no
            // Shopify here, so the attempt is the evidence: what must NOT
            // happen is the stale token coming back.
            $tokPdo->prepare('UPDATE tenants SET token_expires_at = ? WHERE tenant_id = ?')
                ->execute([gmdate('Y-m-d H:i:s', time() + 120), $t]);
            Tenant::forgetCache();

            $handedBack = null;
            try {
                $handedBack = Tenant::accessToken($t);
            } catch (Throwable $e) {
                assertTrue(
                    !str_contains($e->getMessage(), 'no refresh token'),
                    'it gave up instead of refreshing: ' . $e->getMessage()
                );
            }
            assertTrue($handedBack === null, 'a token two minutes from expiry was used as-is');

            return 'used while good, refreshed when not';
        } finally {
            $tokPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$tokShop]);
        }
    });

    check('a store unreachable for 90 days is told to reinstall', function () use ($tokPdo, $tokShop) {
        // The refresh token expires too. Past that the app cannot recover the
        // store on its own, and the honest thing is to say so and stop — not
        // to keep failing against Shopify hourly while the store still reads
        // as active.
        $tokPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$tokShop]);
        $t = Tenant::upsert($tokShop, [
            'access_token'             => 'shpat_dead',
            'refresh_token'            => 'shprt_dead',
            'expires_in'               => 86400,
            'refresh_token_expires_in' => 7776000,
        ], 'read_orders');

        try {
            $tokPdo->prepare(
                'UPDATE tenants SET token_expires_at = ?, refresh_expires_at = ? WHERE tenant_id = ?'
            )->execute([
                gmdate('Y-m-d H:i:s', time() - 3600),
                gmdate('Y-m-d H:i:s', time() - 86400),
                $t,
            ]);
            Tenant::forgetCache();

            $said = '';
            try {
                Tenant::accessToken($t);
            } catch (Throwable $e) {
                $said = $e->getMessage();
            }

            assertTrue(str_contains($said, 'reinstall'), 'the operator was not told what to do: ' . $said);

            $st = $tokPdo->prepare('SELECT status FROM tenants WHERE tenant_id = ?');
            $st->execute([$t]);
            assertTrue($st->fetchColumn() === 'paused', 'the store still reads as healthy');

            return 'paused, with the reason';
        } finally {
            $tokPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$tokShop]);
        }
    });
}

check('compliance request log exists', function () {
    // Answering a data request within 30 days has to be provable, not just
    // asserted.
    $n = (int) Db::core()->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'compliance_requests'"
    )->fetchColumn();
    assertTrue($n === 1, 'compliance_requests is missing');
    return 'present';
});

// -----------------------------------------------------------------
// Identity resolution
//
// Every retention number the product sells is a claim about one person
// buying more than once, so these are the checks that decide whether the
// headline figure is true. They plant orders and remove them again, which
// is why they run only against the local database.
echo "\nIdentity resolution\n";

if ($envFile !== null) {
    foreach ([
        'guest orders resolve to one person',
        'deferred merge joins two persons',
        'unidentifiable order still counts',
        'cancelled order takes no position',
        'identity does not cross tenants',
    ] as $label) {
        skip($label, 'writes test orders; local runs only');
    }
} else {
    $idPdo   = Db::core();
    $idShops = ['selftest-identity-a.myshopify.com', 'selftest-identity-b.myshopify.com'];

    $idClean = static function () use ($idPdo, $idShops): void {
        foreach ($idShops as $shop) {
            $idPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);
        }
    };
    $idClean();

    $idTenant = static fn(string $shop): int => Tenant::upsert($shop, [
        'access_token'             => 'selftest',
        'refresh_token'            => 'selftest',
        'expires_in'               => 3600,
        'refresh_token_expires_in' => 7776000,
    ], 'read_orders');

    /** Plant one order with hashed contact keys, exactly as sync.php does. */
    $idOrder = static function (
        int $tenantId,
        int $orderId,
        string $createdAt,
        ?int $customerId = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $cancelledAt = null
    ) use ($idPdo): void {
        $e = $email !== null ? Hash::pii($tenantId, (string) Hash::normaliseEmail($email)) : null;
        $p = $phone !== null ? Hash::pii($tenantId, (string) Hash::normalisePhone($phone)) : null;

        $idPdo->prepare(
            "INSERT INTO orders (tenant_id, order_id, shopify_customer_id, email_hash, phone_hash,
                                 created_at, cancelled_at, currency, total_minor, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'INR', 100000, UTC_TIMESTAMP())"
        )->execute([$tenantId, $orderId, $customerId, $e, $p, $createdAt, $cancelledAt]);
    };

    /** Drain the work the way app/cron/identity.php does. */
    $idRun = static function (int $tenantId): void {
        foreach (Identity::unresolved($tenantId) as $order) {
            Identity::resolveOrder($tenantId, $order);
        }
        foreach (Identity::pendingResequence($tenantId) as $personId) {
            Identity::resequence($tenantId, $personId);
        }
    };

    /** @return array<int,array{person:int,seq:int|null}> keyed by order id */
    $idRows = static function (int $tenantId) use ($idPdo): array {
        $stmt = $idPdo->prepare(
            'SELECT order_id, person_id, order_sequence FROM orders WHERE tenant_id = ?'
        );
        $stmt->execute([$tenantId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['order_id']] = [
                'person' => (int) $r['person_id'],
                'seq'    => $r['order_sequence'] === null ? null : (int) $r['order_sequence'],
            ];
        }

        return $out;
    };

    $idA = $idTenant($idShops[0]);

    check('guest orders resolve to one person', function () use ($idA, $idOrder, $idRun, $idRows) {
        // The case the whole layer exists for: two guest checkouts sharing a
        // phone but not an email, then an account created with the second
        // email. Grouping by customer_id sees a one-order customer and two
        // anonymous orders, and reports a repeat rate of zero.
        $idOrder($idA, 1001, '2026-01-10 10:00:00', null, 'priya@example.com', '9876543210');
        $idOrder($idA, 1002, '2026-03-15 10:00:00', null, 'priya.work@example.com', '+91 98765 43210');
        $idOrder($idA, 1003, '2026-06-20 10:00:00', 55501, 'priya.work@example.com', null);
        $idOrder($idA, 1004, '2026-02-01 10:00:00', 55502, 'someone@example.com', '9000000001');
        $idRun($idA);

        $r = $idRows($idA);
        assertTrue($r[1001]['person'] === $r[1002]['person'], 'same phone did not merge');
        assertTrue($r[1002]['person'] === $r[1003]['person'], 'same email did not merge');
        assertTrue($r[1004]['person'] !== $r[1001]['person'], 'an unrelated buyer was merged in');
        assertTrue(
            [$r[1001]['seq'], $r[1002]['seq'], $r[1003]['seq'], $r[1004]['seq']] === [1, 2, 3, 1],
            'sequences wrong: ' . json_encode(array_column($r, 'seq'))
        );

        return '3 orders, 1 person, seq 1-2-3';
    });

    check('deferred merge joins two persons', function () use ($idA, $idOrder, $idRun, $idRows, $idPdo) {
        // Two persons already exist before anything links them. Resolution has
        // to follow merged_into rather than assume a key still points at a
        // surviving person, or the absorbed person keeps its orders.
        $idOrder($idA, 2001, '2026-01-05 10:00:00', null, 'raj@example.com', null);
        $idOrder($idA, 2002, '2026-02-05 10:00:00', null, null, '9111111111');
        $idRun($idA);

        $before = $idRows($idA);
        assertTrue($before[2001]['person'] !== $before[2002]['person'], 'unlinked orders merged early');

        $idOrder($idA, 2003, '2026-03-05 10:00:00', null, 'raj@example.com', '9111111111');
        $idRun($idA);

        $after = $idRows($idA);
        assertTrue(
            $after[2001]['person'] === $after[2002]['person']
                && $after[2002]['person'] === $after[2003]['person'],
            'the linking order did not merge the two persons'
        );
        assertTrue(
            [$after[2001]['seq'], $after[2002]['seq'], $after[2003]['seq']] === [1, 2, 3],
            'the surviving person was not resequenced'
        );

        // A merge is occasionally wrong - a shared family phone is the usual
        // cause - so it has to be auditable, not merely correct on average.
        $stmt = $idPdo->prepare(
            'SELECT reason FROM person_merges WHERE tenant_id = ? AND survivor_id = ?'
        );
        $stmt->execute([$idA, $after[2001]['person']]);
        $reasons = $stmt->fetchAll(PDO::FETCH_COLUMN);
        assertTrue($reasons !== [], 'the merge was not logged');

        return 'merged and logged on ' . implode(',', $reasons);
    });

    check('unidentifiable order still counts', function () use ($idA, $idOrder, $idRun, $idRows) {
        // Some orders arrive with neither email nor phone. The purchase still
        // happened, so it is somebody's first order. Leaving the sequence NULL
        // would drop it from every first-purchase count with nothing to notice.
        $idOrder($idA, 2004, '2026-04-05 10:00:00', null, null, null);
        $idRun($idA);

        $r = $idRows($idA);
        assertTrue($r[2004]['seq'] === 1, 'keyless order has sequence ' . var_export($r[2004]['seq'], true));

        return 'own person, sequence 1';
    });

    check('cancelled order takes no position', function () use ($idA, $idOrder, $idRun, $idRows) {
        // A cancelled order is not a purchase. If it held a position, the next
        // real order would be reported one step further along than it was.
        $idOrder($idA, 2005, '2026-05-05 10:00:00', null, 'raj@example.com', null, '2026-05-06 10:00:00');
        $idOrder($idA, 2006, '2026-06-05 10:00:00', null, 'raj@example.com', null);
        $idRun($idA);

        $r = $idRows($idA);
        assertTrue($r[2005]['seq'] === null, 'the cancelled order kept a sequence');
        assertTrue($r[2006]['seq'] === 4, 'next order got sequence ' . var_export($r[2006]['seq'], true));

        return 'cancelled skipped, next order seq 4';
    });

    $idB = $idTenant($idShops[1]);

    check('identity does not cross tenants', function () use ($idA, $idB, $idOrder, $idRun, $idRows, $idPdo) {
        // Two stores, one shopper, the same phone and email. They must stay
        // separate people: the salt is per-tenant, so the hashes differ and one
        // merchant's customer list cannot be reconstructed from another's. This
        // is what keeps a shared identity layer from becoming a cross-merchant
        // data leak.
        $idOrder($idB, 1001, '2026-01-10 10:00:00', null, 'priya@example.com', '9876543210');
        $idRun($idB);

        $a = $idRows($idA);
        $b = $idRows($idB);
        assertTrue($a[1001]['person'] !== $b[1001]['person'], 'one shopper shared a person across two stores');

        $stmt = $idPdo->prepare(
            'SELECT COUNT(*) FROM identity_keys k1
               JOIN identity_keys k2
                 ON k1.key_hash = k2.key_hash AND k1.key_type = k2.key_type
              WHERE k1.tenant_id = ? AND k2.tenant_id = ?'
        );
        $stmt->execute([$idA, $idB]);
        assertTrue((int) $stmt->fetchColumn() === 0, 'identity key hashes collide across tenants');

        return 'separate people, no shared hashes';
    });

    $idClean();
}

// -----------------------------------------------------------------
// Attribution
//
// This is the tab a merchant checks their ad spend against, so being
// quietly wrong here costs them money. Plants events on the shard and
// orders in core, then removes both — local runs only.
echo "\nAttribution\n";

if ($envFile !== null) {
    foreach ([
        'UTM outranks referrer',
        'first and last touch differ',
        'touch outside the lookback is not credited',
        'cross-device touch is credited',
        'no touch reads as Direct/Untracked',
        'unrecognised tag is not filed as Direct',
        'orders inside the grace window are left alone',
        'an attributed order is not revisited',
        'click ids classify without any UTM tags',
        'one Google campaign lands in one row',
    ] as $label) {
        skip($label, 'writes test events; local runs only');
    }
} else {
    $atShop = 'selftest-attribution.myshopify.com';
    $atPdo  = Db::core();
    $atPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$atShop]);

    $atT = Tenant::upsert($atShop, [
        'access_token'             => 'selftest',
        'refresh_token'            => 'selftest',
        'expires_in'               => 3600,
        'refresh_token_expires_in' => 7776000,
    ], 'read_orders');

    $atShard = Shard::connectionForDate(date('Y-m-d'));
    $atShard->prepare('DELETE FROM events WHERE tenant_id = ?')->execute([$atT]);

    $atUid = 1;

    /** A campaign-bearing page view, interned exactly as the importer does. */
    $atTouch = static function (int $visitorKey, string $when, ?string $url, ?string $referrer = null)
        use ($atShard, $atT, &$atUid): void {
        $atShard->prepare(
            'INSERT INTO events (tenant_id, event_uid, occurred_at, received_at, event_type,
                                 source, visitor_key, path_id, referrer_id, campaign_id)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $atT, $atUid++, $when, $when,
            EventType::PAGE_VIEWED, EventType::SOURCE_PIXEL,
            $visitorKey,
            Dim::path($atT, $url),
            Dim::referrer($atT, $referrer),
            Dim::campaign($atT, $url),
        ]);
    };

    $atOrder = static function (int $orderId, string $when, ?int $visitorKey, ?int $personId = null)
        use ($atPdo, $atT): void {
        $atPdo->prepare(
            "INSERT INTO orders (tenant_id, order_id, visitor_key, person_id, created_at,
                                 currency, total_minor, synced_at)
             VALUES (?, ?, ?, ?, ?, 'INR', 100000, UTC_TIMESTAMP())"
        )->execute([$atT, $orderId, $visitorKey, $personId, $when]);
    };

    /** @return array<string,mixed>|null */
    $atRow = static function (int $orderId, string $model) use ($atPdo, $atT): ?array {
        $stmt = $atPdo->prepare(
            'SELECT a.channel, a.touch_at, c.utm_source
               FROM order_attribution a
               LEFT JOIN dim_campaign c
                      ON c.tenant_id = a.tenant_id AND c.campaign_id = a.campaign_id
              WHERE a.tenant_id = ? AND a.order_id = ? AND a.model = ?'
        );
        $stmt->execute([$atT, $orderId, $model]);

        return $stmt->fetch() ?: null;
    };

    $atDay = static fn(int $ago): string => date('Y-m-d H:i:s', strtotime("-{$ago} days"));

    // ---- plant everything, then resolve once ----------------------
    $vRef    = Dim::visitor($atT, 'st-referrer');
    $vTwo    = Dim::visitor($atT, 'st-two-touches');
    $vOld    = Dim::visitor($atT, 'st-old-touch');
    $vPhone  = Dim::visitor($atT, 'st-phone');
    $vLaptop = Dim::visitor($atT, 'st-laptop');
    $vNone   = Dim::visitor($atT, 'st-no-touch');
    $vOdd    = Dim::visitor($atT, 'st-odd-tag');
    $vFresh  = Dim::visitor($atT, 'st-fresh');

    $atPdo->prepare('INSERT INTO persons (tenant_id, first_seen, last_seen) VALUES (?,?,?)')
        ->execute([$atT, $atDay(10), $atDay(1)]);
    $atPerson = (int) $atPdo->lastInsertId();
    $atPdo->prepare(
        'UPDATE dim_visitor SET person_id = ? WHERE tenant_id = ? AND visitor_key IN (?, ?)'
    )->execute([$atPerson, $atT, $vPhone, $vLaptop]);

    $atTouch($vRef, $atDay(6), 'https://s.test/?utm_source=instagram&utm_medium=social', 'https://l.facebook.com/');
    $atOrder(4001, $atDay(5), $vRef);

    $atTouch($vTwo, $atDay(20), 'https://s.test/?utm_source=instagram&utm_campaign=launch');
    $atTouch($vTwo, $atDay(2), 'https://s.test/?utm_source=klaviyo&utm_medium=email');
    $atOrder(4002, $atDay(1), $vTwo);

    $atTouch($vOld, $atDay(45), 'https://s.test/?utm_source=instagram&utm_campaign=ancient');
    $atOrder(4003, $atDay(1), $vOld);

    $atTouch($vPhone, $atDay(3), 'https://s.test/?utm_source=instagram&utm_campaign=reels');
    $atOrder(4004, $atDay(1), $vLaptop, $atPerson);

    $atOrder(4005, $atDay(1), $vNone);

    $atTouch($vOdd, $atDay(2), 'https://s.test/?utm_source=nosuchnetwork&utm_medium=banner');
    $atOrder(4006, $atDay(1), $vOdd);

    $atOrder(4007, date('Y-m-d H:i:s', time() - 600), $vFresh);

    $atPending = Attribution::pending($atT, 500);
    Attribution::resolveBatch($atT, $atPending);

    check('UTM outranks referrer', function () use ($atRow) {
        // The audit's 392 visitors: Instagram UTMs arriving with a
        // l.facebook.com referrer. Crediting Instagram is correct — an
        // explicit tag beats a referrer header — and the point of moving the
        // rules into a table is that this is now a decision anyone can read.
        assertTrue($atRow(4001, 'pixel_last')['channel'] === 'Instagram', 'referrer won over the UTM tag');

        return 'Instagram over l.facebook.com';
    });

    check('first and last touch differ', function () use ($atRow) {
        // If these two ever collapse into the same answer, the Campaigns tab
        // is showing one model twice and the comparison it exists for is gone.
        $first = $atRow(4002, 'pixel_first');
        $last  = $atRow(4002, 'pixel_last');

        assertTrue($first['utm_source'] === 'instagram', 'first touch was ' . var_export($first['utm_source'], true));
        assertTrue($last['utm_source'] === 'klaviyo', 'last touch was ' . var_export($last['utm_source'], true));
        assertTrue($last['channel'] === 'Email', 'klaviyo did not classify as Email');

        return 'discovery Instagram, conversion Email';
    });

    check('touch outside the lookback is not credited', function () use ($atRow) {
        // 45 days before the order. Crediting it would hand a campaign revenue
        // no ad platform would agree it earned.
        assertTrue(
            $atRow(4003, 'pixel_last')['channel'] === 'Direct/Untracked',
            'a 45-day-old touch was credited'
        );

        return '45-day-old touch ignored';
    });

    check('cross-device touch is credited', function () use ($atRow) {
        // Saw the ad on a phone, bought on a laptop. Two visitors, one person.
        // Without the person-level union this reads as Direct, and every
        // social campaign looks worse than it is.
        assertTrue(
            $atRow(4004, 'pixel_last')['channel'] === 'Instagram',
            'the phone touch was not credited to the laptop purchase'
        );

        return 'phone touch credited to a laptop order';
    });

    check('no touch reads as Direct/Untracked', function () use ($atRow) {
        $row = $atRow(4005, 'pixel_last');
        assertTrue($row !== null, 'no row was written for an order with no touches');
        assertTrue($row['channel'] === 'Direct/Untracked', 'got ' . var_export($row['channel'], true));
        assertTrue($row['touch_at'] === null, 'a touch time was recorded for an order with no touch');

        return 'recorded, not skipped';
    });

    check('unrecognised tag is not filed as Direct', function () use ($atRow) {
        // utm_source=nosuchnetwork is tracked traffic the store has no rule
        // for. Filing it under Direct would hide real campaign spend inside
        // the bucket merchants read as "people who typed the URL".
        assertTrue(
            $atRow(4006, 'pixel_last')['channel'] === 'Other',
            'an unrecognised tag was filed as ' . var_export($atRow(4006, 'pixel_last')['channel'], true)
        );

        return 'Other, so a missing rule is visible';
    });

    check('orders inside the grace window are left alone', function () use ($atRow) {
        // The pixel spools to disk and the importer drains it every five
        // minutes, so an order can beat its own checkout event into the
        // database. Attributing at once would freeze Direct onto orders whose
        // touches had simply not landed.
        assertTrue($atRow(4007, 'pixel_last') === null, 'a ten-minute-old order was attributed');

        return 'ten-minute-old order deferred';
    });

    check('an attributed order is not revisited', function () use ($atT) {
        // Orders that found a campaign are done. Orders that found nothing are
        // deliberately re-examined until the reclose window shuts, because a
        // late touch can still upgrade them.
        $again = array_column(Attribution::pending($atT, 500), 'order_id');
        sort($again);

        assertTrue(
            array_map('intval', $again) === [4003, 4005],
            'expected only the two campaign-less orders to be revisited, got ' . json_encode($again)
        );

        return 'only the 2 campaign-less orders, inside reclose';
    });

    check('click ids classify without any UTM tags', function () use ($atT) {
        // A separate branch in the matcher: gclid and fbclid are booleans on
        // dim_campaign, not strings, so 'contains' cannot reach them.
        $gclid  = Dim::campaign($atT, 'https://s.test/?gclid=abc123');
        $fbclid = Dim::campaign($atT, 'https://s.test/?fbclid=xyz789');

        assertTrue($gclid !== null && $fbclid !== null, 'a bare click id did not intern as a campaign');

        // gclid IS an ads marker — Google adds it only on paid clicks.
        assertTrue(
            Channel::classify($atT, $gclid, null, null) === 'Google Ads',
            'gclid did not classify as Google Ads'
        );

        // fbclid is NOT. Facebook stamps it on organic post, Messenger and
        // group links too, so calling it 'Meta Ads' books organic social as
        // ad-driven revenue — an overstatement in the direction nobody
        // questions. Migration 007 corrected the seed; this keeps it corrected.
        assertTrue(
            Channel::classify($atT, $fbclid, null, null) === 'Facebook',
            'fbclid was classified as an ad click: ' . Channel::classify($atT, $fbclid, null, null)
        );

        return 'gclid = Google Ads, fbclid = Facebook';
    });
    check('one Google campaign lands in one row', function () use ($atT) {
        // The same Google Ads campaign arrives tagged two ways depending on
        // whether auto-tagging is on: a gclid with no UTM parameters, or
        // utm_source=google&utm_medium=cpc. If those classify differently the
        // campaign is split across two rows in the Campaigns tab and neither
        // shows the real total — a merchant reconciling against Google Ads
        // finds both numbers too low and nothing explaining why.
        $auto   = Dim::campaign($atT, 'https://s.test/?gclid=abc123');
        $manual = Dim::campaign($atT, 'https://s.test/?utm_source=google&utm_medium=cpc');

        $a = Channel::classify($atT, $auto, null, null);
        $m = Channel::classify($atT, $manual, null, null);

        assertTrue($a === $m, "auto-tagged gave '{$a}', manually tagged gave '{$m}'");
        assertTrue($a === 'Google Ads', "expected Google Ads, got '{$a}'");

        // Organic Google must NOT be swept in with them. It carries no UTM
        // parameters and is matched on referrer alone.
        $organic = Dim::referrer($atT, 'https://www.google.com/search?q=kurta');
        $o = Channel::classify($atT, null, $organic, null);

        assertTrue($o !== 'Google Ads', "organic Google was classified as paid ('{$o}')");

        return "both paths Google Ads, organic stays {$o}";
    });
    $atShard->prepare('DELETE FROM events WHERE tenant_id = ?')->execute([$atT]);
    $atPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$atShop]);
}

// -----------------------------------------------------------------
// Deletion
//
// Purge is what answers shop/redact and what the retention cron runs.
// "We deleted your data" is a claim that has to be true. Local runs
// only: it plants two stores and removes them.
echo "\nDeletion\n";

if ($envFile !== null) {
    skip('deleting a store leaves nothing behind', 'plants two stores; local runs only');
    skip('redacting a customer removes their traces', 'local runs only');
    skip('a redacted customer is not rebuilt', 'local runs only');
    skip('deleting one store spares the next', 'local runs only');
} else {
    $pgPdo   = Db::core();
    $pgShops = ['selftest-purge-a.myshopify.com', 'selftest-purge-b.myshopify.com'];

    // Which tables actually carry per-store data? Ask the SCHEMA, not Purge's
    // own list — otherwise this only proves Purge does what it says it does,
    // and a table it forgot is exactly the failure that matters.
    $pgScoped = $pgPdo->query(
        "SELECT TABLE_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'tenant_id'
            AND TABLE_NAME <> 'tenants'
          ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);

    /** @return array<string,int> tables with rows for this store */
    $pgRows = static function (int $tid) use ($pgPdo, $pgScoped): array {
        $out = [];
        foreach ($pgScoped as $table) {
            $q = $pgPdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE tenant_id = ?");
            $q->execute([$tid]);
            if (($n = (int) $q->fetchColumn()) > 0) {
                $out[$table] = $n;
            }
        }

        return $out;
    };

    $pgTz  = new DateTimeZone('Asia/Kolkata');
    $pgUtc = new DateTimeZone('UTC');
    $pgDay = (new DateTimeImmutable('now', $pgTz))->modify('-10 days')->format('Y-m-d');
    $pgAt  = static fn(string $x): string =>
        (new DateTimeImmutable($x, $pgTz))->setTimezone($pgUtc)->format('Y-m-d H:i:s');

    $pgShard = Shard::connectionForDate($pgDay);
    $pgUid   = 700000;

    $pgFill = static function (string $shop) use ($pgPdo, $pgShard, &$pgUid, $pgAt, $pgDay): int {
        $pgPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);

        $t = Tenant::upsert($shop, [
            'access_token'             => 'selftest',
            'refresh_token'            => 'selftest',
            'expires_in'               => 3600,
            'refresh_token_expires_in' => 7776000,
        ], 'read_orders');
        $pgPdo->prepare('UPDATE tenants SET iana_timezone = ? WHERE tenant_id = ?')
            ->execute(['Asia/Kolkata', $t]);
        Tenant::forgetCache();

        $cam = Dim::campaign($t, 'https://s.test/?utm_source=instagram&utm_medium=social&utm_campaign=x');
        $pth = Dim::path($t, 'https://s.test/products/thing');
        $ref = Dim::referrer($t, 'https://l.instagram.com/');
        $geo = Dim::geo(['country' => 'IN', 'city' => 'Mumbai']);
        $ua  = Dim::userAgent($t, 'Mozilla/5.0 (iPhone) Mobile Safari/604.1');
        $trm = Dim::searchTerm($t, 'kurta');
        $clk = Dim::clickTarget($t, 'Buy now', '.btn', 'https://s.test/cart');
        $vis = Dim::visitor($t, "purge-{$t}");

        foreach ([
            [EventType::PAGE_VIEWED, '10:00:00'],
            [EventType::PRODUCT_VIEWED, '10:05:00'],
            [EventType::PRODUCT_ADDED_TO_CART, '10:10:00'],
            [EventType::CHECKOUT_STARTED, '10:15:00'],
            [EventType::CHECKOUT_COMPLETED, '10:25:00'],
        ] as [$type, $time]) {
            $pgShard->prepare(
                'INSERT INTO events (tenant_id, event_uid, occurred_at, received_at, event_type,
                                     source, visitor_key, path_id, referrer_id, campaign_id,
                                     product_id, geo_id, ua_id, search_term_id, click_target_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$t, $pgUid++, $pgAt("{$pgDay} {$time}"), $pgAt("{$pgDay} {$time}"), $type,
                EventType::SOURCE_PIXEL, $vis, $pth, $ref, $cam, 4242, $geo, $ua, $trm, $clk]);
        }

        $pgPdo->prepare('INSERT INTO products (tenant_id, product_id, title, handle, synced_at)
                         VALUES (?,?,?,?,UTC_TIMESTAMP())')->execute([$t, 4242, 'Thing', 'thing']);
        $pgPdo->prepare('INSERT INTO product_variants (tenant_id, variant_id, product_id, title, synced_at)
                         VALUES (?,?,?,?,UTC_TIMESTAMP())')->execute([$t, 9001, 4242, 'Default']);
        $pgPdo->prepare('INSERT INTO customers (tenant_id, shopify_customer_id, synced_at)
                         VALUES (?,?,UTC_TIMESTAMP())')->execute([$t, 7001]);
        $pgPdo->prepare(
            "INSERT INTO orders (tenant_id, order_id, shopify_customer_id, phone_hash, visitor_key,
                                 created_at, currency, total_minor, synced_at)
             VALUES (?,?,?,?,?,?, 'INR', 150000, UTC_TIMESTAMP())"
        )->execute([$t, 6001, 7001, Hash::pii($t, (string) Hash::normalisePhone('9876500001')),
            $vis, $pgAt("{$pgDay} 10:25:00")]);
        $pgPdo->prepare('INSERT INTO order_line_items (tenant_id, order_id, line_id, product_id,
                                                       quantity, price_minor, discount_minor)
                         VALUES (?,?,1,?,1,150000,0)')->execute([$t, 6001, 4242]);
        $pgPdo->prepare('INSERT INTO abandoned_checkouts (tenant_id, checkout_id, visitor_key,
                                                          created_at, total_minor, synced_at)
                         VALUES (?,?,?,?,90000,UTC_TIMESTAMP())')
            ->execute([$t, 8001, $vis, $pgAt("{$pgDay} 11:00:00")]);
        $pgPdo->prepare('INSERT INTO abandoned_checkout_items (tenant_id, checkout_id, line_no,
                                                               product_id, quantity, price_minor)
                         VALUES (?,?,1,?,1,90000)')->execute([$t, 8001, 4242]);

        foreach (Identity::unresolved($t, 50) as $o) { Identity::resolveOrder($t, $o); }
        foreach (Identity::pendingResequence($t, 50) as $p) { Identity::resequence($t, $p); }
        Identity::linkVisitors($t, 50);
        Attribution::resolveBatch($t, Attribution::pending($t, 50));
        Attribution::backfillChannels($t);
        Rollup::day($t, $pgDay);
        Rollup::cohorts($t);

        $dir = Config::get('paths.spool') . '/' . $t;
        @mkdir($dir, 0700, true);
        @file_put_contents($dir . '/' . gmdate('YmdH') . '.ndjson', "{\"n\":\"page_viewed\"}\n");

        return $t;
    };

    $pgA = $pgFill($pgShops[0]);
    $pgB = $pgFill($pgShops[1]);

    $pgBeforeA = $pgRows($pgA);
    $pgBeforeB = $pgRows($pgB);

    // shop/redact takes everything, rollups included.
    $pgResult = Purge::store($pgA, true);

    check('deleting a store leaves nothing behind', function () use (
        $pgRows, $pgA, $pgBeforeA, $pgResult, $pgShard
    ) {
        assertTrue(count($pgBeforeA) >= 18, 'the fixture only filled ' . count($pgBeforeA) . ' tables');
        assertTrue($pgResult['shards_failed'] === [], 'a shard failed: ' . implode(',', $pgResult['shards_failed']));

        $left = $pgRows($pgA);
        assertTrue($left === [], 'rows survived in ' . json_encode($left));

        $events = (int) $pgShard->query("SELECT COUNT(*) FROM events WHERE tenant_id = {$pgA}")->fetchColumn();
        assertTrue($events === 0, "{$events} event(s) survived");

        $spool = glob(Config::get('paths.spool') . '/' . $pgA . '/*.ndjson') ?: [];
        assertTrue($spool === [], count($spool) . ' spool file(s) survived');

        return count($pgBeforeA) . ' tables emptied, events and spool gone';
    });

    check('redacting a customer removes their traces', function () use ($pgPdo) {
        // customers/redact is mandatory and had no test. The question is not
        // whether it runs but whether the person is actually gone — and
        // whether everybody else is still here.
        $shop = 'selftest-redact.myshopify.com';
        $pgPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);

        $t = Tenant::upsert($shop, [
            'access_token'             => 'selftest',
            'refresh_token'            => 'selftest',
            'expires_in'               => 3600,
            'refresh_token_expires_in' => 7776000,
        ], 'read_orders');
        Tenant::forgetCache();

        $target = ['id' => 4001, 'email' => 'priya@example.com', 'phone' => '9876500001'];
        $other  = ['id' => 4002, 'email' => 'someone@example.com', 'phone' => '9876500002'];

        $put = static function (int $id, array $who, string $ago, bool $account) use ($pgPdo, $t): void {
            $pgPdo->prepare(
                "INSERT INTO orders (tenant_id, order_id, shopify_customer_id, email_hash,
                                     phone_hash, created_at, currency, total_minor, synced_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'INR', 100000, UTC_TIMESTAMP())"
            )->execute([
                $t, $id, $account ? $who['id'] : null,
                Hash::pii($t, (string) Hash::normaliseEmail($who['email'])),
                Hash::pii($t, (string) Hash::normalisePhone($who['phone'])),
                gmdate('Y-m-d H:i:s', strtotime($ago)),
            ]);
        };

        try {
            $put(500, $target, '-30 days', false);   // guest
            $put(501, $target, '-10 days', true);    // later, with an account
            $put(502, $other, '-20 days', true);

            foreach ([$target, $other] as $who) {
                $pgPdo->prepare('INSERT INTO customers (tenant_id, shopify_customer_id, synced_at)
                                 VALUES (?,?,UTC_TIMESTAMP())')->execute([$t, $who['id']]);
            }

            $pgPdo->prepare(
                "INSERT INTO abandoned_checkouts (tenant_id, checkout_id, email_hash, phone_hash,
                                                  created_at, total_minor, synced_at)
                 VALUES (?,?,?,?,?,50000,UTC_TIMESTAMP())"
            )->execute([
                $t, 9500,
                Hash::pii($t, (string) Hash::normaliseEmail($target['email'])),
                Hash::pii($t, (string) Hash::normalisePhone($target['phone'])),
                gmdate('Y-m-d H:i:s', strtotime('-15 days')),
            ]);

            $visitor = Dim::visitor($t, 'redact-visitor');
            $pgPdo->prepare('UPDATE orders SET visitor_key = ? WHERE tenant_id = ? AND order_id = ?')
                ->execute([$visitor, $t, 501]);

            foreach (Identity::unresolved($t, 50) as $o) { Identity::resolveOrder($t, $o); }
            foreach (Identity::pendingResequence($t, 50) as $p) { Identity::resequence($t, $p); }
            Identity::linkVisitors($t, 50);

            $person = static function (int $order) use ($pgPdo, $t): ?int {
                $q = $pgPdo->prepare('SELECT person_id FROM orders WHERE tenant_id = ? AND order_id = ?');
                $q->execute([$t, $order]);
                $v = $q->fetchColumn();

                return $v === null || $v === false ? null : (int) $v;
            };

            $targetPerson = $person(500);
            $otherPerson  = $person(502);
            assertTrue($targetPerson !== null && $targetPerson !== $otherPerson, 'fixture did not separate them');

            Purge::customer($t, $target['id'], $target['email'], $target['phone']);

            $emailHash = Hash::pii($t, (string) Hash::normaliseEmail($target['email']));
            $phoneHash = Hash::pii($t, (string) Hash::normalisePhone($target['phone']));

            $count = static function (string $sql, array $a) use ($pgPdo): int {
                $q = $pgPdo->prepare($sql);
                $q->execute($a);

                return (int) $q->fetchColumn();
            };

            // Gone.
            assertTrue($count('SELECT COUNT(*) FROM identity_keys WHERE tenant_id=? AND key_hash IN (?,?)',
                [$t, $emailHash, $phoneHash]) === 0, 'identity keys survived');
            assertTrue($count('SELECT COUNT(*) FROM persons WHERE tenant_id=? AND person_id=?',
                [$t, $targetPerson]) === 0, 'the person survived');
            assertTrue($count('SELECT COUNT(*) FROM customers WHERE tenant_id=? AND shopify_customer_id=?',
                [$t, $target['id']]) === 0, 'the customer row survived');
            assertTrue($count('SELECT COUNT(*) FROM orders WHERE tenant_id=? AND (email_hash=? OR phone_hash=?)',
                [$t, $emailHash, $phoneHash]) === 0, 'their contact hashes survived on the orders');
            assertTrue($count('SELECT COUNT(*) FROM abandoned_checkouts WHERE tenant_id=? AND (email_hash=? OR phone_hash=?)',
                [$t, $emailHash, $phoneHash]) === 0, 'their contact hashes survived on an abandoned cart');
            assertTrue($count('SELECT COUNT(*) FROM dim_visitor WHERE tenant_id=? AND person_id=?',
                [$t, $targetPerson]) === 0, 'a visitor still points at the deleted person');

            // Kept: the money is the merchant's record, not the customer's.
            assertTrue($count('SELECT COUNT(*) FROM orders WHERE tenant_id=?', [$t]) === 3,
                'orders were deleted along with the customer');
            assertTrue($count('SELECT COUNT(*) FROM persons WHERE tenant_id=? AND person_id=?',
                [$t, $otherPerson]) === 1, 'the other shopper was caught up in it');
            assertTrue($count('SELECT COUNT(*) FROM orders WHERE tenant_id=? AND person_id=?',
                [$t, $otherPerson]) === 1, 'the other shopper lost their order link');

            return 'identity gone, revenue kept, neighbour untouched';
        } finally {
            $pgPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);
        }
    });

    check('a redacted customer is not rebuilt', function () use ($pgPdo) {
        // The one that matters most. Deleting the person while leaving
        // email_hash and phone_hash on their orders redacts nothing: those
        // columns are exactly what identity resolution reads, so the next run
        // of the identity job reassembles the same customer from the same
        // orders in the same sequence. The deletion undoes itself within the
        // hour, and nothing reports it.
        $shop = 'selftest-redact-rebuild.myshopify.com';
        $pgPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);

        $t = Tenant::upsert($shop, [
            'access_token'             => 'selftest',
            'refresh_token'            => 'selftest',
            'expires_in'               => 3600,
            'refresh_token_expires_in' => 7776000,
        ], 'read_orders');
        Tenant::forgetCache();

        $email = 'priya@example.com';
        $phone = '9876500001';

        try {
            foreach ([[601, '-30 days'], [602, '-10 days']] as [$id, $ago]) {
                $pgPdo->prepare(
                    "INSERT INTO orders (tenant_id, order_id, shopify_customer_id, email_hash,
                                         phone_hash, created_at, currency, total_minor, synced_at)
                     VALUES (?, ?, 4001, ?, ?, ?, 'INR', 100000, UTC_TIMESTAMP())"
                )->execute([
                    $t, $id,
                    Hash::pii($t, (string) Hash::normaliseEmail($email)),
                    Hash::pii($t, (string) Hash::normalisePhone($phone)),
                    gmdate('Y-m-d H:i:s', strtotime($ago)),
                ]);
            }

            $resolve = static function () use ($t): void {
                foreach (Identity::unresolved($t, 50) as $o) { Identity::resolveOrder($t, $o); }
                foreach (Identity::pendingResequence($t, 50) as $p) { Identity::resequence($t, $p); }
            };

            $resolve();

            $q = $pgPdo->prepare('SELECT person_id FROM orders WHERE tenant_id = ? ORDER BY order_id');
            $q->execute([$t]);
            $before = $q->fetchAll(PDO::FETCH_COLUMN);
            assertTrue(
                $before[0] !== null && $before[0] === $before[1],
                'the fixture did not link the two orders to one person'
            );

            Purge::customer($t, 4001, $email, $phone);

            // Three runs, because one is not proof against a queue draining
            // slowly.
            $resolve();
            $resolve();
            $resolve();

            $q->execute([$t]);
            $after = $q->fetchAll(PDO::FETCH_COLUMN);

            assertTrue(
                $after[0] !== $after[1],
                'the two orders were re-linked into one profile again (person ' . $after[0] . ')'
            );

            $left = $pgPdo->prepare(
                'SELECT COUNT(*) FROM orders WHERE tenant_id = ? AND (email_hash IS NOT NULL OR phone_hash IS NOT NULL)'
            );
            $left->execute([$t]);
            assertTrue((int) $left->fetchColumn() === 0, 'a contact hash came back');

            return 'stays unlinked across repeated identity runs';
        } finally {
            $pgPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);
        }
    });

    check('deleting one store spares the next', function () use (
        $pgRows, $pgB, $pgBeforeB, $pgShard
    ) {
        // The failure this exists for: a DELETE that forgot its WHERE, or a
        // shared dimension row removed because two stores happened to intern
        // the same value.
        $after = $pgRows($pgB);
        assertTrue(
            $after == $pgBeforeB,
            'the other store changed: ' . json_encode(array_diff_assoc($pgBeforeB, $after))
        );

        $events = (int) $pgShard->query("SELECT COUNT(*) FROM events WHERE tenant_id = {$pgB}")->fetchColumn();
        assertTrue($events === 5, "the other store has {$events} event(s), expected 5");

        return array_sum($pgBeforeB) . ' rows and 5 events untouched';
    });

    // Tidy the survivor.
    Purge::store($pgB, true);
    foreach ([$pgA, $pgB] as $t) {
        $dir = Config::get('paths.spool') . '/' . $t;
        foreach (glob($dir . '/*') ?: [] as $x) { @unlink($x); }
        @rmdir($dir);
    }
    $pgPdo->prepare('DELETE FROM tenants WHERE shop_domain IN (?, ?)')->execute($pgShops);
}

// -----------------------------------------------------------------
// The importer
//
// A spool file through mapEvent() into the shard. No HTTP — the endpoint
// is a separate concern — but this is the path every event takes, and it
// is the one that failed silently: a constant declared among the helper
// functions at the bottom of import.php did not exist while the main loop
// ran, so every event became "malformed" and the shard stayed empty with
// nothing reported as an error.
echo "\nThe importer\n";

if ($envFile !== null) {
    skip('a spool file becomes events', 'writes a spool file; local runs only');
    skip('a replayed file adds nothing', 'local runs only');
    skip('hostile values are bounded, not rejected', 'local runs only');
} else {
    $imShop = 'selftest-import.myshopify.com';
    $imPdo  = Db::core();
    $imPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$imShop]);

    $imT = Tenant::upsert($imShop, [
        'access_token'             => 'selftest',
        'refresh_token'            => 'selftest',
        'expires_in'               => 3600,
        'refresh_token_expires_in' => 7776000,
    ], 'read_orders');
    Tenant::forgetCache();

    $imShard = Shard::connectionForDate(gmdate('Y-m-d'));
    $imShard->prepare('DELETE FROM events WHERE tenant_id = ?')->execute([$imT]);

    $imDir = Config::get('paths.spool') . '/' . $imT;
    @mkdir($imDir, 0700, true);
    foreach (glob($imDir . '/*.ndjson') ?: [] as $x) { @unlink($x); }

    // The importer skips the CURRENT hour's file, because the ingest endpoint
    // is still appending to it. Writing an earlier hour is what the passage of
    // an hour does, without waiting for one.
    $imFile = $imDir . '/' . gmdate('YmdH', time() - 7200) . '.ndjson';
    $imNow  = (int) round(microtime(true) * 1000);

    // Field names come from the web pixel: `n` for the name, `s` for source,
    // `ts` in milliseconds. See shopify/extensions/retention-pixel/src/index.js.
    $imEvents = [
        ['n' => 'page_viewed', 's' => 1, 'id' => 'st-1', 'ts' => $imNow, 'cid' => 'st-visitor',
         'u' => 'https://s.test/?utm_source=instagram&utm_medium=social&utm_campaign=selftest'],
        ['n' => 'product_viewed', 's' => 1, 'id' => 'st-2', 'ts' => $imNow, 'cid' => 'st-visitor',
         'u' => 'https://s.test/products/x', 'p' => 12345, 'v' => 67890],
        ['n' => 'product_added_to_cart', 's' => 1, 'id' => 'st-3', 'ts' => $imNow, 'cid' => 'st-visitor',
         'u' => 'https://s.test/products/x', 'p' => 12345, 'q' => 2],
        ['n' => 'checkout_completed', 's' => 1, 'id' => 'st-4', 'ts' => $imNow, 'cid' => 'st-visitor',
         'u' => 'https://s.test/checkout', 'o' => 555001, 'a' => 249900, 'c' => 'INR'],
        // Deliberately hostile: a negative product id, a quantity and an amount
        // far past their columns, and a name with a multibyte character right
        // where a byte-based cut would split it.
        ['n' => 'product_added_to_cart', 's' => 1, 'id' => 'st-5', 'ts' => $imNow, 'cid' => 'st-visitor',
         'u' => 'https://s.test/products/y?utm_campaign=' . str_repeat('श', 120),
         'p' => -7, 'q' => 999999, 'a' => '99999999999999'],
    ];

    $imLines = '';
    foreach ($imEvents as $e) {
        $imLines .= json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    file_put_contents($imFile, $imLines);

    $imRun = static function () use ($imT): void {
        $root = dirname(__DIR__);
        @shell_exec('"' . PHP_BINARY . '" ' . escapeshellarg($root . '/app/cron/import.php') . ' 2>&1');
    };
    $imRun();

    check('a spool file becomes events', function () use ($imShard, $imT, $imPdo) {
        $stmt = $imShard->prepare(
            'SELECT event_type, visitor_key, campaign_id, product_id, qty, amount_minor, order_ref
               FROM events WHERE tenant_id = ? ORDER BY event_uid'
        );
        $stmt->execute([$imT]);
        $rows = $stmt->fetchAll();

        assertTrue(count($rows) === 5, count($rows) . ' event(s) landed, expected 5');

        $byType = [];
        foreach ($rows as $r) { $byType[(int) $r['event_type']][] = $r; }

        assertTrue(isset($byType[EventType::PAGE_VIEWED]), 'the page view is missing');
        assertTrue(
            (int) $byType[EventType::PRODUCT_VIEWED][0]['product_id'] === 12345,
            'product id did not survive'
        );
        assertTrue(
            (int) $byType[EventType::CHECKOUT_COMPLETED][0]['order_ref'] === 555001,
            'the order id did not survive'
        );
        assertTrue(
            (int) $byType[EventType::CHECKOUT_COMPLETED][0]['amount_minor'] === 249900,
            'the amount did not survive'
        );

        // The UTM tuple must be interned, not dropped.
        $campaign = (int) $byType[EventType::PAGE_VIEWED][0]['campaign_id'];
        assertTrue($campaign > 0, 'the campaign was not interned');

        $c = $imPdo->prepare('SELECT utm_source FROM dim_campaign WHERE tenant_id = ? AND campaign_id = ?');
        $c->execute([$imT, $campaign]);
        assertTrue($c->fetchColumn() === 'instagram', 'utm_source did not survive');

        $visitors = array_unique(array_column($rows, 'visitor_key'));
        assertTrue(count($visitors) === 1, 'one browser became ' . count($visitors) . ' visitors');

        return '5 events, fields and campaign intact';
    });

    check('hostile values are bounded, not rejected', function () use ($imShard, $imT, $imPdo) {
        // These arrive from a browser, so they cannot be trusted to be in
        // range. The events insert uses INSERT IGNORE for deduplication, and
        // IGNORE also downgrades an out-of-range value to a silent clamp —
        // strict mode does not apply to it. So the bounds have to hold before
        // the value is handed over, and a bad one must not lose the event.
        $stmt = $imShard->prepare(
            "SELECT product_id, qty, amount_minor, campaign_id FROM events
              WHERE tenant_id = ? AND event_type = ? ORDER BY event_uid"
        );
        $stmt->execute([$imT, EventType::PRODUCT_ADDED_TO_CART]);
        $rows = $stmt->fetchAll();

        assertTrue(count($rows) === 2, 'the hostile add-to-cart was dropped');

        $bad = null;
        foreach ($rows as $r) {
            if ((int) $r['qty'] !== 2) { $bad = $r; }
        }
        assertTrue($bad !== null, 'the hostile row is missing');

        // Negative becomes null rather than wrapping to a huge unsigned value.
        assertTrue($bad['product_id'] === null, 'a negative product id was stored as ' . var_export($bad['product_id'], true));
        assertTrue((int) $bad['qty'] === 65535, 'quantity was not clamped: ' . $bad['qty']);
        assertTrue((int) $bad['amount_minor'] === 4294967295, 'amount was not clamped: ' . $bad['amount_minor']);

        // A multibyte campaign name cut at a byte boundary would be invalid
        // UTF-8, which strict mode rejects — losing the whole event.
        $c = $imPdo->prepare('SELECT utm_campaign FROM dim_campaign WHERE tenant_id = ? AND campaign_id = ?');
        $c->execute([$imT, (int) $bad['campaign_id']]);
        $name = (string) $c->fetchColumn();

        assertTrue($name !== '', 'the multibyte campaign was not stored');
        assertTrue(mb_check_encoding($name, 'UTF-8'), 'the stored campaign name is not valid UTF-8');

        return 'clamped and stored, event kept';
    });

    check('a replayed file adds nothing', function () use ($imShard, $imT, $imDir, $imFile, $imRun) {
        // The same beacon can arrive twice. uq_event is what stops that
        // becoming a second row — and a duplicated purchase event would
        // overstate revenue.
        $before = (int) $imShard->query("SELECT COUNT(*) FROM events WHERE tenant_id = {$imT}")->fetchColumn();

        $again = $imDir . '/' . gmdate('YmdH', time() - 10800) . '.ndjson';
        @copy($imFile, $again);
        if (!is_file($again)) {
            // The first run moves the file once processed; rebuild from the
            // processed copy if that is where it went.
            $processed = Config::get('paths.processed') . '/' . $imT;
            foreach (glob($processed . '/*.ndjson') ?: [] as $p) { @copy($p, $again); break; }
        }
        $imRun();

        $after = (int) $imShard->query("SELECT COUNT(*) FROM events WHERE tenant_id = {$imT}")->fetchColumn();
        assertTrue($after === $before, "replay changed the count from {$before} to {$after}");

        return "{$after} rows before and after";
    });

    $imShard->prepare('DELETE FROM events WHERE tenant_id = ?')->execute([$imT]);
    foreach (glob($imDir . '/*') ?: [] as $x) { @unlink($x); }
    @rmdir($imDir);
    foreach (glob(Config::get('paths.processed') . '/' . $imT . '/*') ?: [] as $x) { @unlink($x); }
    @rmdir(Config::get('paths.processed') . '/' . $imT);
    $imPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$imShop]);
}

// -----------------------------------------------------------------
echo "\nIngest write-key map\n";

check('a new store is in the map immediately', function () {
    // c.php resolves a write key from a generated file, never a query, to keep
    // the hot path off the database. On an unknown key it rebuilds — but not
    // if it rebuilt within the last minute, or anyone posting random keys
    // would force a query and a file write per request.
    //
    // That protection is right, and it left one gap: a store onboarded during
    // that minute had its first events answered with 403, and the pixel uses
    // sendBeacon, which cannot retry. The OAuth callback now refreshes the map
    // as soon as the write key exists. This checks the refresh actually works.
    $shop = 'selftest-writekey.myshopify.com';
    $pdo  = Db::core();
    $pdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);

    $t = Tenant::upsert($shop, [
        'access_token'             => 'selftest',
        'refresh_token'            => 'selftest',
        'expires_in'               => 3600,
        'refresh_token_expires_in' => 7776000,
    ], 'read_orders');

    try {
        $key = (string) (Tenant::find($t)['write_key'] ?? '');
        assertTrue($key !== '', 'the new store has no write key');

        Tenant::refreshWriteKeyCache();

        $file = Config::get('paths.storage') . '/tenants.php';
        assertTrue(is_file($file), 'no write-key map was written');

        $map = include $file;
        assertTrue(is_array($map), 'the map is not an array');
        assertTrue(isset($map[$key]), 'the new store is missing from the map');
        assertTrue(
            (int) $map[$key]['id'] === $t,
            'the map points at tenant ' . $map[$key]['id'] . ', not ' . $t
        );
        // The origin check reads these, so a missing domain means every event
        // from that storefront is refused.
        assertTrue(
            in_array($shop, $map[$key]['domains'] ?? [], true),
            'the storefront domain is missing from the map'
        );

        return 'key, tenant id and domain all present';
    } finally {
        $pdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);
        Tenant::refreshWriteKeyCache();
    }
});

check('installing refreshes the map', function () {
    // The call itself, in the one place that matters. A refactor that drops it
    // reopens the gap silently, because nothing else fails.
    $callback = (string) @file_get_contents(dirname(__DIR__) . '/public_html/oauth/callback.php');

    assertTrue($callback !== '', 'the OAuth callback is missing');
    assertTrue(
        str_contains($callback, 'Tenant::refreshWriteKeyCache()'),
        'the OAuth callback no longer refreshes the write-key map'
    );

    return 'callback refreshes on install';
});

// -----------------------------------------------------------------
// Static checks
echo "\nStatic checks\n";

check('TLS verification is never disabled', function () {
    // This one exists because of a specific temptation. Certificate
    // verification fails on a developer machine with no CA bundle — as it does
    // on the Windows box this was built on — and the one-line "fix" is to turn
    // verification off. That line then ships, and every Shopify API call,
    // including the OAuth token exchange, will accept a forged certificate
    // from anyone able to intercept the connection.
    //
    // curl and PHP's stream wrapper both verify by default, so the correct
    // state is that none of these appear anywhere.
    $root  = dirname(__DIR__);
    $found = [];
    $files = 0;

    $banned = [
        'CURLOPT_SSL_VERIFYPEER',
        'CURLOPT_SSL_VERIFYHOST',
        'verify_peer',
        'verify_peer_name',
        'allow_self_signed',
    ];

    foreach (['app', 'bin', 'public_html', 'config'] as $dir) {
        if (!is_dir("{$root}/{$dir}")) {
            continue;
        }

        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}"));

        foreach ($walk as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            // This file lists the very strings it is looking for.
            if (realpath($file->getPathname()) === realpath(__FILE__)) {
                continue;
            }
            $files++;

            $src   = (string) file_get_contents($file->getPathname());
            $lines = explode("\n", $src);

            foreach ($lines as $n => $line) {
                foreach ($banned as $needle) {
                    if (str_contains($line, $needle)) {
                        $found[] = basename((string) $file->getPathname()) . ':' . ($n + 1) . " {$needle}";
                    }
                }
            }
        }
    }

    assertTrue($found === [], 'TLS verification is being configured: ' . implode(', ', $found));

    return "{$files} files, verification left at the secure default";
});
check('no SQL binds a placeholder twice', function () {
    // PDO here runs with ATTR_EMULATE_PREPARES = false, and native prepares
    // cannot bind the same named placeholder more than once — the statement
    // dies with "Invalid parameter number" the first time it is executed.
    //
    // That makes it a runtime failure on a code path a developer may not hit
    // for weeks: a rollup for a date range, an unusual tenant, a rarely taken
    // branch. Cheaper to find by reading every SQL literal in the tree.
    $root  = dirname(__DIR__);
    $bad   = [];
    $files = 0;

    foreach (['app', 'bin', 'public_html'] as $dir) {
        if (!is_dir("{$root}/{$dir}")) {
            continue;
        }

        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}"));

        foreach ($walk as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $files++;

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                if (!in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }

                $text = $token[1];

                // Has to look like a statement, not merely contain the word.
                // CSS in a view carries :root and :hover and would otherwise
                // report as an offender forever.
                $isSql = preg_match('/\bSELECT\b[\s\S]*\bFROM\b/i', $text)
                    || preg_match('/\bINSERT\s+INTO\b/i', $text)
                    || preg_match('/\bUPDATE\b[\s\S]*\bSET\b/i', $text)
                    || preg_match('/\bDELETE\s+FROM\b/i', $text);

                if (!$isSql) {
                    continue;
                }

                preg_match_all('/:([a-z_][a-z0-9_]*)/i', $text, $m);

                foreach (array_count_values($m[1] ?? []) as $name => $n) {
                    if ($n > 1) {
                        $bad[] = basename((string) $file->getPathname()) . ':' . $token[2] . " (:{$name} x{$n})";
                    }
                }
            }
        }
    }

    assertTrue($bad === [], 'repeated placeholders: ' . implode(', ', $bad));

    return "{$files} files scanned";
});

check('setup.php refuses without reporting a fault', function () {
    // Its resting state is refusal: SYSADMIN.md tells the operator to remove
    // SETUP_TOKEN when setup is finished, so "disabled" is how this URL should
    // sit in production forever.
    //
    // fail() used to set 500 on its first line, overwriting the 403 the token
    // gate set two lines earlier. Every refusal — including that resting state
    // — was reported as a server error, which sends whoever reads the logs
    // hunting a broken application instead of a mistyped token, and buries the
    // 500s that do mean something.
    $src = (string) file_get_contents(dirname(__DIR__) . '/public_html/setup.php');

    assertTrue(
        (bool) preg_match('/function fail\([^)]*int \$status = 500[^)]*\): never/', $src),
        'fail() no longer takes a status'
    );
    assertTrue(
        str_contains($src, 'http_response_code($status);'),
        'fail() hardcodes a status again, so its callers cannot choose one'
    );

    // The two refusals must ask for 403. Checked by reading what is passed,
    // because the bug was a correct call site being silently overruled.
    assertTrue(
        (bool) preg_match("/fail\(\s*'Forbidden',[^;]*403\s*\)/s", $src),
        'the token gate no longer answers 403'
    );
    assertTrue(
        (bool) preg_match("/fail\(\s*'Setup is disabled',.*?403\s*\)/s", $src),
        'the disabled state no longer answers 403'
    );

    // And a genuine fault still is one: this call passes no status, so it
    // takes the 500 default.
    assertTrue(
        str_contains($src, "fail('Configuration error', '<pre>' . htmlspecialchars(\$e->getMessage()) . '</pre>');"),
        'a configuration error stopped being a server fault'
    );

    return 'refusals 403, faults 500';
});
// -----------------------------------------------------------------
// Rollups
//
// The dashboard reads nothing else, so an error here is an error on
// every panel at once. Plants a coherent day of traffic and orders for
// a store in Asia/Kolkata, rolls it up, and checks the totals against
// what a person counting by hand would say. Local runs only.
echo "\nRollups\n";

if ($envFile !== null) {
    foreach ([
        'the day boundary is the store\'s, not UTC',
        'sessions split on an idle gap',
        'orders, revenue, new and repeat',
        'funnel counts reached and strict separately',
        'product views, sales and abandons',
        'nobody advances from a stage they never entered',
        'drop-off is counted at the stage it happened',
        'repeat cohorts land in the right bucket',
        'recomputing a day changes nothing',
        'days close after the reclose window',
        'new and returning customers partition',
        'a buyer we cannot classify is not called returning',
        'resequencing leaves no stale numbers',
        'range figures count people, not buyer-days',
        'every dashboard tab renders',
        'a brand new store is told which it is',
    ] as $label) {
        skip($label, 'writes test events; local runs only');
    }
} else {
    $ruShop = 'selftest-rollup.myshopify.com';
    $ruPdo  = Db::core();
    $ruPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$ruShop]);

    $ruT = Tenant::upsert($ruShop, [
        'access_token'             => 'selftest',
        'refresh_token'            => 'selftest',
        'expires_in'               => 3600,
        'refresh_token_expires_in' => 7776000,
    ], 'read_orders');

    // An Indian store, because that is where the UTC day boundary bites.
    $ruPdo->prepare('UPDATE tenants SET iana_timezone = ? WHERE tenant_id = ?')
        ->execute(['Asia/Kolkata', $ruT]);
    Tenant::forgetCache();

    $ruIst = new DateTimeZone('Asia/Kolkata');
    $ruUtc = new DateTimeZone('UTC');

    // Five days back, so the day is outside the reclose window and closed.
    $ruDay = (new DateTimeImmutable('now', $ruIst))->modify('-5 days')->format('Y-m-d');
    $ruPre = (new DateTimeImmutable($ruDay, $ruIst))->modify('-1 day')->format('Y-m-d');

    $ruShard = Shard::connectionForDate($ruDay);
    $ruShard->prepare('DELETE FROM events WHERE tenant_id = ?')->execute([$ruT]);

    /** Store-local wall clock -> the UTC value actually stored. */
    $ruAt = static function (string $local) use ($ruIst, $ruUtc): string {
        return (new DateTimeImmutable($local, $ruIst))->setTimezone($ruUtc)->format('Y-m-d H:i:s');
    };

    $ruUid = 1;
    $ruEv  = static function (int $visitor, string $local, int $type, array $x = [])
        use ($ruShard, $ruT, &$ruUid, $ruAt): void {
        $ruShard->prepare(
            'INSERT INTO events (tenant_id, event_uid, occurred_at, received_at, event_type,
                                 source, visitor_key, path_id, campaign_id, product_id, geo_id, ua_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $ruT, $ruUid++, $ruAt($local), $ruAt($local), $type, EventType::SOURCE_PIXEL, $visitor,
            $x['path'] ?? null, $x['campaign'] ?? null, $x['product'] ?? null,
            $x['geo'] ?? null, $x['ua'] ?? null,
        ]);
    };

    $ruV1 = Dim::visitor($ruT, 'ru-complete');
    $ruV2 = Dim::visitor($ruT, 'ru-abandoner');
    $ruV3 = Dim::visitor($ruT, 'ru-midnight');
    $ruV4 = Dim::visitor($ruT, 'ru-two-visits');

    $ruInsta = Dim::campaign($ruT, 'https://s.test/?utm_source=instagram&utm_campaign=reels');
    $ruGoog  = Dim::campaign($ruT, 'https://s.test/?utm_source=google&utm_medium=cpc');
    $ruHome  = Dim::path($ruT, 'https://s.test/');
    $ruGeo   = Dim::geo(['country' => 'IN', 'region' => 'Maharashtra', 'city' => 'Mumbai']);
    $ruMob   = Dim::userAgent($ruT, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148 Safari/604.1');
    $ruDesk  = Dim::userAgent($ruT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36');

    $ruProd = 100;

    // The complete path, on an Instagram click.
    $c = ['campaign' => $ruInsta, 'path' => $ruHome, 'geo' => $ruGeo, 'ua' => $ruMob];
    $ruEv($ruV1, "{$ruDay} 10:00:00", EventType::PAGE_VIEWED, $c);
    $ruEv($ruV1, "{$ruDay} 10:05:00", EventType::PRODUCT_VIEWED, $c + ['product' => $ruProd]);
    $ruEv($ruV1, "{$ruDay} 10:10:00", EventType::PRODUCT_ADDED_TO_CART, $c + ['product' => $ruProd]);
    $ruEv($ruV1, "{$ruDay} 10:15:00", EventType::CHECKOUT_STARTED, $c);
    // Skips contact, address and shipping — a returning buyer with details
    // saved. This is what breaks a naive stage-to-stage funnel.
    $ruEv($ruV1, "{$ruDay} 10:20:00", EventType::PAYMENT_INFO_SUBMITTED, $c);
    $ruEv($ruV1, "{$ruDay} 10:25:00", EventType::CHECKOUT_COMPLETED, $c);

    // Straight onto a product page from a Google ad, then gone at contact info.
    $d = ['campaign' => $ruGoog, 'geo' => $ruGeo, 'ua' => $ruDesk];
    $ruEv($ruV2, "{$ruDay} 11:00:00", EventType::PRODUCT_VIEWED, $d + ['product' => $ruProd]);
    $ruEv($ruV2, "{$ruDay} 11:05:00", EventType::PRODUCT_ADDED_TO_CART, $d + ['product' => $ruProd]);
    $ruEv($ruV2, "{$ruDay} 11:10:00", EventType::CHECKOUT_STARTED, $d);
    $ruEv($ruV2, "{$ruDay} 11:12:00", EventType::CHECKOUT_CONTACT_INFO, $d);

    // 00:30 local is the PREVIOUS day in UTC.
    $ruEv($ruV3, "{$ruDay} 00:30:00", EventType::PAGE_VIEWED, ['path' => $ruHome, 'geo' => $ruGeo, 'ua' => $ruMob]);

    // Three hours apart: one visitor, two sessions.
    $ruEv($ruV4, "{$ruDay} 14:00:00", EventType::PAGE_VIEWED, ['path' => $ruHome, 'ua' => $ruMob]);
    $ruEv($ruV4, "{$ruDay} 17:00:00", EventType::PAGE_VIEWED, ['path' => $ruHome, 'ua' => $ruMob]);

    // 23:30 the previous local evening — must stay out of this day.
    $ruEv($ruV3, "{$ruPre} 23:30:00", EventType::PAGE_VIEWED, ['path' => $ruHome]);

    $ruPerson = static function () use ($ruPdo, $ruT, $ruDay): int {
        $ruPdo->prepare('INSERT INTO persons (tenant_id, first_seen, last_seen) VALUES (?,?,?)')
            ->execute([$ruT, $ruDay, $ruDay]);

        return (int) $ruPdo->lastInsertId();
    };
    $ruP1 = $ruPerson();
    $ruP2 = $ruPerson();

    $ruOrder = static function (int $id, string $local, int $person, int $seq, ?int $visitor, int $minor)
        use ($ruPdo, $ruT, $ruAt): void {
        $ruPdo->prepare(
            "INSERT INTO orders (tenant_id, order_id, person_id, visitor_key, order_sequence,
                                 created_at, currency, total_minor, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, 'INR', ?, UTC_TIMESTAMP())"
        )->execute([$ruT, $id, $person, $visitor, $seq, $ruAt($local), $minor]);
    };

    // P1 bought 40 days ago and again today: a repeat buyer whose gap falls
    // between the 30- and 60-day cohort buckets.
    $ruOld = (new DateTimeImmutable($ruDay, $ruIst))->modify('-40 days')->format('Y-m-d');
    $ruOrder(5000, "{$ruOld} 12:00:00", $ruP1, 1, null, 80000);
    $ruOrder(5001, "{$ruDay} 10:25:00", $ruP1, 2, $ruV1, 100000);
    $ruOrder(5002, "{$ruDay} 15:00:00", $ruP2, 1, $ruV4, 50000);

    $ruPdo->prepare(
        'INSERT INTO order_line_items
            (tenant_id, order_id, line_id, product_id, quantity, price_minor, discount_minor)
         VALUES (?,?,?,?,?,?,0)'
    )->execute([$ruT, 5001, 1, $ruProd, 2, 50000]);

    $ruPdo->prepare(
        'INSERT INTO abandoned_checkouts
            (tenant_id, checkout_id, visitor_key, created_at, total_minor, synced_at)
         VALUES (?,?,?,?,?,UTC_TIMESTAMP())'
    )->execute([$ruT, 9001, $ruV2, $ruAt("{$ruDay} 11:10:00"), 50000]);
    $ruPdo->prepare(
        'INSERT INTO abandoned_checkout_items
            (tenant_id, checkout_id, line_no, product_id, quantity, price_minor)
         VALUES (?,?,1,?,1,?)'
    )->execute([$ruT, 9001, $ruProd, 50000]);

    Attribution::resolveBatch($ruT, Attribution::pending($ruT, 100));
    Rollup::day($ruT, $ruDay);
    Rollup::cohorts($ruT);

    /** @return array<string,mixed> */
    $ruKpi = static function () use ($ruPdo, $ruT, $ruDay): array {
        $s = $ruPdo->prepare('SELECT * FROM rollup_daily_kpi WHERE tenant_id = ? AND stat_date = ?');
        $s->execute([$ruT, $ruDay]);

        return $s->fetch() ?: [];
    };

    /** @return array<int,array<string,mixed>> */
    $ruRows = static function (string $table, string $key) use ($ruPdo, $ruT, $ruDay): array {
        $s = $ruPdo->prepare("SELECT * FROM {$table} WHERE tenant_id = ? AND stat_date = ?");
        $s->execute([$ruT, $ruDay]);

        $out = [];
        foreach ($s->fetchAll() as $r) {
            $out[(int) $r[$key]] = $r;
        }

        return $out;
    };

    check('the day boundary is the store\'s, not UTC', function () use ($ruKpi) {
        // 00:30 IST is 19:00 UTC the evening before. Rolling up by UTC date
        // would drop it into yesterday, and every Indian evening with it —
        // which the merchant finds instantly by comparing against Shopify
        // admin, and then trusts nothing else on the page.
        $k = $ruKpi();
        assertTrue((int) $k['visitors'] === 4, 'visitors: ' . $k['visitors']);
        assertTrue((int) $k['pageviews'] === 4, 'pageviews: ' . $k['pageviews'] . ' (23:30 the night before leaked in?)');

        return '00:30 IST in, 23:30 the night before out';
    });

    check('sessions split on an idle gap', function () use ($ruKpi) {
        // Four visitors, five sessions: one of them came back after three
        // hours. Sessions equalling visitors means the gap logic is dead.
        $k = $ruKpi();
        assertTrue((int) $k['sessions'] === 5, 'sessions: ' . $k['sessions']);

        return '4 visitors, 5 sessions';
    });

    check('orders, revenue, new and repeat', function () use ($ruKpi) {
        $k = $ruKpi();
        assertTrue((int) $k['orders'] === 2, 'orders: ' . $k['orders']);
        assertTrue((int) $k['revenue_minor'] === 150000, 'revenue: ' . $k['revenue_minor']);
        assertTrue((int) $k['units'] === 2, 'units: ' . $k['units']);
        assertTrue((int) $k['new_customers'] === 1, 'new: ' . $k['new_customers']);
        assertTrue((int) $k['repeat_customers'] === 1, 'repeat: ' . $k['repeat_customers']);

        return '2 orders, 1 new, 1 repeat';
    });

    check('funnel counts reached and strict separately', function () use ($ruRows) {
        // V2 arrives straight on a product page from an ad and never fires a
        // plain page view. Reporting only the strict path hides a working ad;
        // reporting only reached shows more add-to-carts than page views and
        // reads as a broken dashboard. Both numbers, always.
        $f = $ruRows('rollup_daily_funnel', 'step');

        assertTrue((int) $f[1]['reached_visitors'] === 3, 'step1 reached: ' . $f[1]['reached_visitors']);
        assertTrue((int) $f[2]['reached_visitors'] === 2, 'step2 reached: ' . $f[2]['reached_visitors']);
        assertTrue((int) $f[2]['strict_visitors'] === 1, 'step2 strict: ' . $f[2]['strict_visitors']);
        assertTrue((int) $f[6]['reached_visitors'] === 1, 'step6 reached: ' . $f[6]['reached_visitors']);

        return 'step 2: 2 reached, 1 strict';
    });

    check('product views, sales and abandons', function () use ($ruRows, $ruProd) {
        $p = $ruRows('rollup_daily_product', 'product_id')[$ruProd] ?? [];

        assertTrue($p !== [], 'no product row was written');
        assertTrue((int) $p['views'] === 2, 'views: ' . $p['views']);
        assertTrue((int) $p['atc'] === 2, 'atc: ' . $p['atc']);
        assertTrue((int) $p['units'] === 2, 'units: ' . $p['units']);
        // "Most abandoned product" comes from carts nobody completed, not from
        // add-to-carts that later converted.
        assertTrue((int) $p['abandons'] === 1, 'abandons: ' . $p['abandons']);

        return '2 views, 2 sold, 1 abandoned';
    });

    check('nobody advances from a stage they never entered', function () use ($ruRows) {
        // V1 goes checkout_started -> payment, skipping contact, address and
        // shipping. Counting "advanced" as whoever entered the next stage then
        // reports stage 5 with 0 entered and 1 advanced. Advanced has to mean
        // "of those who reached this stage, how many went further".
        foreach ($ruRows('rollup_daily_abandon', 'stage') as $stage => $r) {
            assertTrue(
                (int) $r['advanced'] <= (int) $r['entered'],
                "stage {$stage}: advanced {$r['advanced']} > entered {$r['entered']}"
            );
            assertTrue(
                (int) $r['abandoned'] === (int) $r['entered'] - (int) $r['advanced'],
                "stage {$stage}: abandoned does not reconcile"
            );
        }

        return 'advanced <= entered at every stage';
    });

    check('drop-off is counted at the stage it happened', function () use ($ruRows) {
        $a = $ruRows('rollup_daily_abandon', 'stage');

        // Both shoppers started checkout and both went further, so nobody is
        // lost at stage 2. V2 stops at contact info, and that is where the one
        // abandonment belongs.
        assertTrue((int) $a[2]['entered'] === 2, 'stage 2 entered: ' . $a[2]['entered']);
        assertTrue((int) $a[2]['abandoned'] === 0, 'stage 2 abandoned: ' . $a[2]['abandoned']);
        assertTrue((int) $a[3]['abandoned'] === 1, 'stage 3 abandoned: ' . $a[3]['abandoned']);
        // The number that has to reconcile with Shopify admin.
        assertTrue((int) $a[3]['shopify_records'] === 1, 'shopify_records: ' . $a[3]['shopify_records']);

        return 'lost at contact info, not at checkout start';
    });

    check('repeat cohorts land in the right bucket', function () use ($ruPdo, $ruT) {
        // P1's two orders are 40 days apart: not a 30-day repeat, but a
        // 60-day one. A cohort curve that counts them at 30 days is
        // flattering, and flattering is the direction nobody checks.
        $s = $ruPdo->prepare(
            'SELECT days_bucket, cohort_size, reordered FROM rollup_cohort_repeat
              WHERE tenant_id = ? ORDER BY days_bucket'
        );
        $s->execute([$ruT]);

        $byBucket = [];
        foreach ($s->fetchAll() as $r) {
            $byBucket[(int) $r['days_bucket']] = ($byBucket[(int) $r['days_bucket']] ?? 0) + (int) $r['reordered'];
        }

        assertTrue(($byBucket[30] ?? 0) === 0, '40-day gap counted as a 30-day repeat');
        assertTrue(($byBucket[60] ?? 0) === 1, '40-day gap missing from the 60-day bucket');
        assertTrue(($byBucket[180] ?? 0) === 1, '40-day gap missing from the 180-day bucket');

        return '40-day gap: not 30d, yes 60d';
    });

    // computed_at moves on every run by design; everything else must not.
    $ruStable = static function (array $rows): string {
        array_walk_recursive($rows, static function (&$v, $k): void {
            if ($k === 'computed_at') { $v = null; }
        });

        return json_encode($rows) ?: '';
    };

    check('recomputing a day changes nothing', function () use ($ruT, $ruDay, $ruKpi, $ruRows, $ruStable) {
        // Every day inside the reclose window is recomputed on every run, so a
        // rollup that accumulates instead of replacing would double its totals
        // hourly. The device rollup folds many user agents into one row and is
        // the one that has to clear the day first.
        $before = [$ruKpi(), $ruRows('rollup_daily_device', 'device_type'), $ruRows('rollup_daily_funnel', 'step')];

        Rollup::day($ruT, $ruDay);

        $after = [$ruKpi(), $ruRows('rollup_daily_device', 'device_type'), $ruRows('rollup_daily_funnel', 'step')];

        foreach (['kpi' => 0, 'device' => 1, 'funnel' => 2] as $name => $i) {
            assertTrue(
                $ruStable($before[$i]) === $ruStable($after[$i]),
                "{$name} changed on recompute"
            );
        }

        return 'idempotent';
    });

    check('days close after the reclose window', function () use ($ruT, $ruKpi, $ruIst) {
        // A number that keeps moving cannot be reconciled against anything. It
        // settles after RECLOSE_DAYS and is labelled provisional until then.
        $k = $ruKpi();
        assertTrue((int) $k['is_provisional'] === 0, 'a five-day-old day is still provisional');

        $today = (new DateTimeImmutable('now', $ruIst))->format('Y-m-d');
        Rollup::day($ruT, $today);

        $s = Db::core()->prepare(
            'SELECT is_provisional FROM rollup_daily_kpi WHERE tenant_id = ? AND stat_date = ?'
        );
        $s->execute([$ruT, $today]);
        assertTrue((int) $s->fetchColumn() === 1, 'today was written as final');

        return 'today provisional, day 5 closed';
    });

    check('new and returning customers partition', function () use ($ruPdo) {
        // A shopper who buys twice in one day used to be counted as BOTH a new
        // and a returning customer: their first-ever order and their second
        // both fall on that date. The two tiles then summed to more people than
        // actually bought, and the repeat rate — which divides by that sum —
        // came out too low. On a two-buyer day it read 33% when the truth was
        // that both were brand new.
        $shop = 'selftest-partition.myshopify.com';
        $ruPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);

        $t = Tenant::upsert($shop, [
            'access_token'             => 'selftest',
            'refresh_token'            => 'selftest',
            'expires_in'               => 3600,
            'refresh_token_expires_in' => 7776000,
        ], 'read_orders');
        $ruPdo->prepare('UPDATE tenants SET iana_timezone = ? WHERE tenant_id = ?')
            ->execute(['Asia/Kolkata', $t]);
        Tenant::forgetCache();

        $tz  = new DateTimeZone('Asia/Kolkata');
        $utc = new DateTimeZone('UTC');
        $day = (new DateTimeImmutable('now', $tz))->modify('-10 days')->format('Y-m-d');
        $at  = static fn(string $s): string =>
            (new DateTimeImmutable($s, $tz))->setTimezone($utc)->format('Y-m-d H:i:s');

        // No person_id: identity assigns it, exactly as in production.
        $order = static function (int $id, string $phone, string $time, int $amount) use ($ruPdo, $t, $at, $day): void {
            $ruPdo->prepare(
                "INSERT INTO orders (tenant_id, order_id, phone_hash, created_at,
                                     currency, total_minor, synced_at)
                 VALUES (?, ?, ?, ?, 'INR', ?, UTC_TIMESTAMP())"
            )->execute([
                $t, $id, Hash::pii($t, (string) Hash::normalisePhone($phone)),
                $at("{$day} {$time}"), $amount,
            ]);
        };

        try {
            // One shopper, two orders, same day. Plus a second shopper.
            $order(770001, '9876500001', '10:00:00', 100000);
            $order(770002, '9876500001', '18:00:00', 200000);
            $order(770003, '9876500002', '12:00:00', 50000);

            foreach (Identity::unresolved($t, 100) as $o) {
                Identity::resolveOrder($t, $o);
            }
            foreach (Identity::pendingResequence($t, 100) as $p) {
                Identity::resequence($t, $p);
            }
            Rollup::day($t, $day);

            $stmt = $ruPdo->prepare(
                'SELECT orders, purchasers, new_customers, repeat_customers
                   FROM rollup_daily_kpi WHERE tenant_id = ? AND stat_date = ?'
            );
            $stmt->execute([$t, $day]);
            $k = $stmt->fetch() ?: [];

            assertTrue((int) $k['orders'] === 3, 'orders: ' . ($k['orders'] ?? 'none'));
            assertTrue((int) $k['purchasers'] === 2, 'purchasers: ' . ($k['purchasers'] ?? 'none'));
            assertTrue(
                (int) $k['new_customers'] + (int) $k['repeat_customers'] === (int) $k['purchasers'],
                "new {$k['new_customers']} + returning {$k['repeat_customers']} != purchasers {$k['purchasers']}"
            );
            // Both became customers that day, so neither is returning yet.
            assertTrue((int) $k['new_customers'] === 2, 'new: ' . $k['new_customers']);
            assertTrue((int) $k['repeat_customers'] === 0, 'returning: ' . $k['repeat_customers']);

            return '3 orders, 2 buyers, 2 new + 0 returning';
        } finally {
            $ruPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);
        }
    });

    check('a buyer we cannot classify is not called returning', function () use ($ruPdo) {
        // The partition is derived, so it depends on every buyer having a
        // sequence — and some never do. Identity assigns person_id and numbers
        // the orders in two separate passes, and a store with
        // refunded_counts_as_order = 0 leaves refunded orders unnumbered for
        // good.
        //
        // Deriving from every purchaser swept those into "returning", which
        // reported a store whose only customer was a first-time buyer as 100%
        // repeat. Better to admit we cannot classify them: an undercount is
        // visible, a confident wrong answer is not.
        $shop = 'selftest-unsequenced.myshopify.com';
        $ruPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);

        $t = Tenant::upsert($shop, [
            'access_token'             => 'selftest',
            'refresh_token'            => 'selftest',
            'expires_in'               => 3600,
            'refresh_token_expires_in' => 7776000,
        ], 'read_orders');
        $ruPdo->prepare(
            'UPDATE tenants SET iana_timezone = ?, refunded_counts_as_order = 0 WHERE tenant_id = ?'
        )->execute(['Asia/Kolkata', $t]);
        Tenant::forgetCache();

        $tz  = new DateTimeZone('Asia/Kolkata');
        $utc = new DateTimeZone('UTC');
        $day = (new DateTimeImmutable('now', $tz))->modify('-10 days')->format('Y-m-d');

        try {
            // One shopper, one order, refunded. Their first ever purchase.
            $ruPdo->prepare(
                "INSERT INTO orders (tenant_id, order_id, phone_hash, created_at,
                                     financial_status, currency, total_minor, synced_at)
                 VALUES (?, ?, ?, ?, 'refunded', 'INR', 100000, UTC_TIMESTAMP())"
            )->execute([
                $t, 910001,
                Hash::pii($t, (string) Hash::normalisePhone('9876511111')),
                (new DateTimeImmutable("{$day} 10:00:00", $tz))->setTimezone($utc)->format('Y-m-d H:i:s'),
            ]);

            foreach (Identity::unresolved($t, 50) as $o) { Identity::resolveOrder($t, $o); }
            foreach (Identity::pendingResequence($t, 50) as $p) { Identity::resequence($t, $p); }
            Rollup::day($t, $day);

            $stmt = $ruPdo->prepare(
                'SELECT order_sequence FROM orders WHERE tenant_id = ? AND order_id = ?'
            );
            $stmt->execute([$t, 910001]);
            assertTrue(
                $stmt->fetchColumn() === null,
                'the fixture no longer produces an unsequenced order'
            );

            $stmt = $ruPdo->prepare(
                'SELECT purchasers, new_customers, repeat_customers
                   FROM rollup_daily_kpi WHERE tenant_id = ? AND stat_date = ?'
            );
            $stmt->execute([$t, $day]);
            $k = $stmt->fetch() ?: [];

            assertTrue((int) $k['purchasers'] === 1, 'purchasers: ' . ($k['purchasers'] ?? 'none'));
            assertTrue(
                (int) $k['repeat_customers'] === 0,
                'an unclassifiable buyer was reported as returning (' . $k['repeat_customers'] . ')'
            );
            assertTrue((int) $k['new_customers'] === 0, 'new: ' . $k['new_customers']);

            return 'purchaser counted, classified as neither';
        } finally {
            $ruPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);
        }
    });

    check('resequencing leaves no stale numbers', function () use ($ruPdo) {
        // resequence() used to assign over the top and only clear cancelled
        // orders. An order that stopped qualifying — refunded, on a store that
        // does not count refunds — kept the number it already had, so a person
        // could hold two orders both numbered 1 and be counted as a first-time
        // buyer twice, on two different days.
        $shop = 'selftest-stale-seq.myshopify.com';
        $ruPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);

        $t = Tenant::upsert($shop, [
            'access_token'             => 'selftest',
            'refresh_token'            => 'selftest',
            'expires_in'               => 3600,
            'refresh_token_expires_in' => 7776000,
        ], 'read_orders');
        Tenant::forgetCache();

        $phone = Hash::pii($t, (string) Hash::normalisePhone('9876533333'));

        try {
            // Two orders, both counting at first.
            foreach ([[920001, '-20 days'], [920002, '-10 days']] as [$id, $when]) {
                $ruPdo->prepare(
                    "INSERT INTO orders (tenant_id, order_id, phone_hash, created_at,
                                         currency, total_minor, synced_at)
                     VALUES (?, ?, ?, ?, 'INR', 100000, UTC_TIMESTAMP())"
                )->execute([$t, $id, $phone, gmdate('Y-m-d H:i:s', strtotime($when))]);
            }

            foreach (Identity::unresolved($t, 50) as $o) { Identity::resolveOrder($t, $o); }
            foreach (Identity::pendingResequence($t, 50) as $p) { Identity::resequence($t, $p); }

            $seq = $ruPdo->prepare('SELECT order_id, order_sequence FROM orders WHERE tenant_id = ? ORDER BY order_id');
            $seq->execute([$t]);
            $before = array_column($seq->fetchAll(), 'order_sequence', 'order_id');
            assertTrue(
                (int) $before[920001] === 1 && (int) $before[920002] === 2,
                'the fixture did not number both orders: ' . json_encode($before)
            );

            // The first is refunded and the store stops counting refunds.
            $ruPdo->prepare("UPDATE orders SET financial_status = 'refunded' WHERE tenant_id = ? AND order_id = ?")
                ->execute([$t, 920001]);
            $ruPdo->prepare('UPDATE tenants SET refunded_counts_as_order = 0 WHERE tenant_id = ?')->execute([$t]);
            Tenant::forgetCache();

            $person = (int) $ruPdo->query(
                "SELECT person_id FROM orders WHERE tenant_id = {$t} AND order_id = 920002"
            )->fetchColumn();
            Identity::resequence($t, $person);

            $seq->execute([$t]);
            $after = array_column($seq->fetchAll(), 'order_sequence', 'order_id');

            assertTrue(
                $after[920001] === null,
                'the refunded order kept sequence ' . var_export($after[920001], true)
            );
            assertTrue(
                (int) $after[920002] === 1,
                'the surviving order should now be their first: got ' . var_export($after[920002], true)
            );

            return 'excluded order cleared, remaining renumbered';
        } finally {
            $ruPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);
        }
    });

    check('range figures count people, not buyer-days', function () use ($ruPdo) {
        // A daily rollup counts distinct people PER DAY. Adding thirty of them
        // counts somebody who bought on four days four times. New customers
        // survive that (a person has a first-ever order exactly once) but
        // returning customers do not — so the repeat rate divided buyer-days by
        // (buyers + buyer-days) and read far too high.
        $shop = 'selftest-range.myshopify.com';
        $ruPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);

        $t = Tenant::upsert($shop, [
            'access_token'             => 'selftest',
            'refresh_token'            => 'selftest',
            'expires_in'               => 3600,
            'refresh_token_expires_in' => 7776000,
        ], 'read_orders');
        $ruPdo->prepare('UPDATE tenants SET iana_timezone = ? WHERE tenant_id = ?')
            ->execute(['Asia/Kolkata', $t]);
        Tenant::forgetCache();

        $tz  = new DateTimeZone('Asia/Kolkata');
        $utc = new DateTimeZone('UTC');
        $day = static fn(int $ago): string =>
            (new DateTimeImmutable('now', $tz))->modify("-{$ago} days")->format('Y-m-d');

        $id  = 930000;
        $put = static function (string $phone, int $ago) use ($ruPdo, $t, &$id, $day, $tz, $utc): void {
            $ruPdo->prepare(
                "INSERT INTO orders (tenant_id, order_id, phone_hash, created_at,
                                     currency, total_minor, synced_at)
                 VALUES (?, ?, ?, ?, 'INR', 100000, UTC_TIMESTAMP())"
            )->execute([
                $t, $id++, Hash::pii($t, (string) Hash::normalisePhone($phone)),
                (new DateTimeImmutable($day($ago) . ' 10:00:00', $tz))
                    ->setTimezone($utc)->format('Y-m-d H:i:s'),
            ]);
        };

        try {
            $put('9000000002', 60);   // B became a customer before the range
            $put('9000000001', 24);   // A, first ever, inside the range
            $put('9000000002', 22);   // B returns
            $put('9000000001', 18);   // A again — still acquired in this range
            $put('9000000003', 15);   // C, first ever
            $put('9000000002', 10);   // B again — still one person

            foreach (Identity::unresolved($t, 50) as $o) { Identity::resolveOrder($t, $o); }
            foreach (Identity::pendingResequence($t, 50) as $p) { Identity::resequence($t, $p); }

            $range = Report::range($t, $day(25), $day(5));
            $b     = Report::buyersOverRange($t, $range);

            assertTrue($b['buyers'] === 3, 'buyers: ' . $b['buyers']);
            assertTrue($b['new'] === 2, 'new: ' . $b['new']);
            // Not 3. B bought on three days inside the range and is one person.
            assertTrue($b['returning'] === 1, 'returning: ' . $b['returning']);
            assertTrue(
                $b['rate'] !== null && abs($b['rate'] - 33.3) < 0.1,
                'rate: ' . var_export($b['rate'], true)
            );

            return '3 buyers, 2 new, 1 returning, 33.3%';
        } finally {
            $ruPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);
        }
    });

    check('every dashboard tab renders', function () use ($ruT, $ruPdo) {
        // Views are where undefined-index and null-arithmetic errors live, and
        // they only surface when the page is actually rendered. Each tab is
        // rendered twice: against a store with a full day of data, and against
        // a brand new store with nothing — which is what every merchant sees
        // in their first hour, and the state most likely to be untested.
        $blank = 'selftest-blank.myshopify.com';
        $ruPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$blank]);
        $emptyT = Tenant::upsert($blank, [
            'access_token'             => 'selftest',
            'refresh_token'            => 'selftest',
            'expires_in'               => 3600,
            'refresh_token_expires_in' => 7776000,
        ], 'read_orders');

        $views = ['overview', 'funnel', 'campaigns', 'products', 'retention', 'checkout', 'geography'];
        $root  = dirname(__DIR__);
        $bytes = 0;

        // Any notice or warning is a failure here, not a log line.
        set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
            throw new ErrorException($msg . "  [" . basename($file) . ":{$line}]", 0, $no, $file, $line);
        });

        try {
            foreach ([$ruT, $emptyT] as $tenantId) {
                foreach ($views as $view) {
                    $tenant  = Tenant::find($tenantId);
                    $stats   = ['events' => 0, 'last_event' => null, 'visitors' => 0,
                                'spool' => 0, 'orders' => 0, 'revenue_minor' => 0];
                    $hasData = Report::hasData($tenantId);
                    $range   = Report::range($tenantId, null, null);

                    $data = match ($view) {
                        'funnel'    => ['funnel' => Report::funnel($tenantId, $range)],
                        'campaigns' => [
                            'model'             => Report::DEFAULT_MODEL,
                            'campaigns'         => Report::campaigns($tenantId, $range, Report::DEFAULT_MODEL),
                            'channels'          => Report::channels($tenantId, $range, Report::DEFAULT_MODEL),
                            'comparison'        => Report::modelComparison($tenantId, $range),
                            'campaignRetention' => Report::campaignRetention($tenantId),
                        ],
                        'products'  => ['products' => Report::products($tenantId, $range)],
                        'retention' => ['retention' => Report::retention($tenantId)],
                        'checkout'  => ['abandon' => Report::abandonment($tenantId, $range)],
                        'geography' => [
                            'geography' => Report::geography($tenantId, $range),
                            'devices'   => Report::devices($tenantId, $range),
                            'landing'   => Report::landingPages($tenantId, $range),
                        ],
                        default     => [
                            'summary'  => Report::summary($tenantId, $range),
                            'trend'    => Report::trend($tenantId, $range),
                            'channels' => Report::channels($tenantId, $range, Report::DEFAULT_MODEL),
                        ],
                    };

                    extract($data, EXTR_SKIP);

                    ob_start();
                    try {
                        require $root . '/app/views/dash/' . $view . '.php';
                        $html = (string) ob_get_clean();
                    } catch (Throwable $e) {
                        ob_end_clean();
                        throw new RuntimeException("{$view} (tenant {$tenantId}): " . $e->getMessage());
                    }

                    assertTrue(trim($html) !== '', "{$view} rendered nothing for tenant {$tenantId}");
                    $bytes += strlen($html);
                }
            }
        } finally {
            restore_error_handler();
            $ruPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$blank]);
        }

        return count($views) . ' tabs x2 states, ' . number_format($bytes) . ' bytes';
    });

    check('a brand new store is told which it is', function () use ($ruPdo) {
        // An empty dashboard and a broken one look identical, and a merchant
        // who cannot tell them apart assumes the worst. The empty state has to
        // say tracking is live and when numbers will appear.
        $blank = 'selftest-blank2.myshopify.com';
        $ruPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$blank]);
        $t = Tenant::upsert($blank, [
            'access_token'             => 'selftest',
            'refresh_token'            => 'selftest',
            'expires_in'               => 3600,
            'refresh_token_expires_in' => 7776000,
        ], 'read_orders');

        $tenant  = Tenant::find($t);
        $stats   = ['events' => 0, 'last_event' => null, 'visitors' => 0,
                    'spool' => 0, 'orders' => 0, 'revenue_minor' => 0];
        $hasData = Report::hasData($t);
        $range   = Report::range($t, null, null);
        $summary = Report::summary($t, $range);
        $trend   = Report::trend($t, $range);
        $channels = Report::channels($t, $range, Report::DEFAULT_MODEL);

        ob_start();
        require dirname(__DIR__) . '/app/views/dash/overview.php';
        $html = (string) ob_get_clean();

        $ruPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$blank]);

        assertTrue(
            str_contains($html, 'Waiting for the first visitor'),
            'the empty overview does not explain itself'
        );
        // Zeros dressed up as data would be worse than the honest empty state.
        assertTrue(!str_contains($html, 'Conversion'), 'empty store was shown metric tiles');

        return 'explains itself instead of showing zeros';
    });
    $ruShard->prepare('DELETE FROM events WHERE tenant_id = ?')->execute([$ruT]);
    $ruPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$ruShop]);
}

// -----------------------------------------------------------------
// Billing
//
// Getting this wrong costs money in one direction or locks a paying
// merchant out in the other, and neither shows up as an error.
echo "\nBilling\n";

check('who can see the dashboard', function () {
    // Every access rule in one pure function, so this is the whole of it.
    $future = gmdate('Y-m-d H:i:s', time() + 5 * 86400);
    $past   = gmdate('Y-m-d H:i:s', time() - 86400);

    $cases = [
        // stored,      trial ends,  expected status, expected access
        ['active',      $future,     'trial',     true],
        ['active',      $past,       'active',    true],
        ['active',      null,        'active',    true],
        ['pending',     null,        'pending',   false],
        ['none',        null,        'none',      false],
        // A failed payment is not access. Shopify retries and restores the
        // subscription itself the moment one goes through.
        ['frozen',      null,        'frozen',    false],
        ['cancelled',   null,        'cancelled', false],
        ['declined',    null,        'declined',  false],
        ['expired',     null,        'expired',   false],
        // A trial that has run out without a payment is not a trial.
        ['cancelled',   $future,     'cancelled', false],
    ];

    foreach ($cases as [$stored, $trial, $wantStatus, $wantAccess]) {
        $got = Billing::resolve($stored, $trial);

        assertTrue(
            $got['status'] === $wantStatus,
            "{$stored} resolved to {$got['status']}, expected {$wantStatus}"
        );
        assertTrue(
            $got['access'] === $wantAccess,
            "{$stored} gave access=" . var_export($got['access'], true)
                . ", expected " . var_export($wantAccess, true)
        );
    }

    return count($cases) . ' states checked';
});

check('every Shopify status maps to one of ours', function () {
    // AppSubscriptionStatus, from the Admin API. A status Shopify adds that we
    // do not map falls through to 'none' and locks the merchant out, so the
    // list is written down rather than assumed.
    $shopify = ['ACTIVE', 'PENDING', 'FROZEN', 'CANCELLED', 'DECLINED', 'EXPIRED'];

    $ref = new ReflectionClass(Billing::class);
    $map = $ref->getConstant('STATUS_MAP');

    assertTrue(is_array($map), 'STATUS_MAP is missing');

    foreach ($shopify as $s) {
        assertTrue(isset($map[$s]), "Shopify status {$s} is not mapped");
    }

    // And every mapped value has to be storable, or the UPDATE silently
    // truncates to '' and the merchant is locked out with no explanation.
    $column = Db::core()->query("SHOW COLUMNS FROM tenants LIKE 'billing_status'")->fetch();
    $enum   = (string) ($column['Type'] ?? '');

    foreach (array_unique(array_values($map)) as $ours) {
        assertTrue(
            str_contains($enum, "'{$ours}'"),
            "billing_status has no '{$ours}' — the column enum and the map disagree"
        );
    }

    return count($shopify) . ' statuses, all storable';
});

if ($envFile !== null) {
    skip('a subscription webhook changes access', 'writes a test store; local runs only');
    skip('the plan page renders in every state', 'local runs only');
    skip('an unknown shop is ignored', 'local runs only');
} else {
    check('a subscription webhook changes access', function () {
        $shop = 'selftest-billing.myshopify.com';
        $pdo  = Db::core();
        $pdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);

        $t = Tenant::upsert($shop, [
            'access_token'             => 'selftest',
            'refresh_token'            => 'selftest',
            'expires_in'               => 3600,
            'refresh_token_expires_in' => 7776000,
        ], 'read_orders');

        $status = static function () use ($pdo, $t): string {
            $s = $pdo->prepare('SELECT billing_status FROM tenants WHERE tenant_id = ?');
            $s->execute([$t]);

            return (string) $s->fetchColumn();
        };

        $send = static function (string $shopifyStatus) use ($shop): void {
            Billing::applyWebhook($shop, ['app_subscription' => [
                'admin_graphql_api_id' => 'gid://shopify/AppSubscription/123',
                'name'                 => 'Retention Dashboard',
                'status'               => $shopifyStatus,
            ]]);
        };

        try {
            assertTrue($status() === 'none', 'a new store did not start at none');

            $send('PENDING');
            assertTrue($status() === 'pending', 'PENDING gave ' . $status());

            // The merchant approves. This is the moment the dashboard has to
            // unlock, and the reason the webhook exists at all rather than
            // waiting for the next scheduled read.
            $send('ACTIVE');
            assertTrue($status() === 'active', 'ACTIVE gave ' . $status());

            // Their card fails later.
            $send('FROZEN');
            assertTrue($status() === 'frozen', 'FROZEN gave ' . $status());
            assertTrue(!Billing::resolve('frozen', null)['access'], 'a frozen store kept access');

            // A status we do not recognise must leave state alone rather than
            // resetting it to none and locking out a paying merchant.
            $send('SOMETHING_NEW');
            assertTrue($status() === 'frozen', 'an unknown status overwrote a known one');

            // Every change is recorded. A dispute about a charge needs
            // something to look at.
            $history = Billing::history($t);
            $moves   = array_column($history, 'status_to');
            assertTrue(
                in_array('pending', $moves, true)
                    && in_array('active', $moves, true)
                    && in_array('frozen', $moves, true),
                'transitions were not logged: ' . implode(',', $moves)
            );

            return count($history) . ' transitions logged';
        } finally {
            $pdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);
        }
    });

        check('the plan page renders in every state', function () {
        // Each of these is a real thing a merchant sees, and the states that
        // matter most — a failed payment, a half-finished approval — are the
        // ones nobody clicks through by hand.
        $shop = 'selftest-planview.myshopify.com';
        $pdo  = Db::core();
        $pdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);

        $t = Tenant::upsert($shop, [
            'access_token'             => 'selftest',
            'refresh_token'            => 'selftest',
            'expires_in'               => 3600,
            'refresh_token_expires_in' => 7776000,
        ], 'read_orders');

        set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
            throw new ErrorException($msg . '  [' . basename($file) . ":{$line}]", 0, $no, $file, $line);
        });

        $states = [
            'none'      => [null, null],
            'pending'   => ['https://example.myshopify.com/admin/charges/1/confirm', null],
            'trial'     => [null, gmdate('Y-m-d H:i:s', time() + 5 * 86400)],
            'active'    => [null, null],
            'frozen'    => [null, null],
            'cancelled' => [null, null],
            'declined'  => [null, null],
            'expired'   => [null, null],
        ];

        $bytes = 0;

        try {
            foreach ($states as $status => [$confirm, $trialEnds]) {
                $tenant  = Tenant::find($t);
                $plans   = Billing::plans();
                $history = Billing::history($t);
                $error   = $status === 'declined' ? 'A deliberately shown error message.' : null;

                $resolved = Billing::resolve($status === 'trial' ? 'active' : $status, $trialEnds);

                $billing = [
                    'status'             => $resolved['status'],
                    'plan'               => 'standard',
                    'access'             => $resolved['access'],
                    'reason'             => 'Checked by the self-test.',
                    'trial_ends_at'      => $trialEnds,
                    'current_period_end' => $status === 'active' ? gmdate('Y-m-d H:i:s', time() + 20 * 86400) : null,
                    'confirm_url'        => $confirm,
                    'test'               => $status === 'active',
                    'enabled'            => true,
                ];

                ob_start();
                try {
                    require dirname(__DIR__) . '/app/views/dash/plan.php';
                    $html = (string) ob_get_clean();
                } catch (Throwable $e) {
                    ob_end_clean();
                    throw new RuntimeException("{$status}: " . $e->getMessage());
                }

                assertTrue(trim($html) !== '', "{$status} rendered nothing");
                $bytes += strlen($html);

                // A merchant one click from paying must be offered that click,
                // not a plan chooser telling them nothing is subscribed.
                if ($status === 'pending') {
                    assertTrue(
                        str_contains($html, (string) $confirm),
                        'the pending state did not offer the confirmation link'
                    );
                    assertTrue(
                        !str_contains($html, 'Choose a plan'),
                        'the pending state showed the plan chooser'
                    );
                }

                // A failed payment is not a cancellation, and saying so is the
                // difference between a merchant fixing a card and one deciding
                // they have been cut off.
                if ($status === 'frozen') {
                    assertTrue(
                        str_contains($html, 'still being collected'),
                        'the frozen state did not say data is still being kept'
                    );
                }
            }
        } finally {
            restore_error_handler();
            $pdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$shop]);
        }

        return count($states) . ' states, ' . number_format($bytes) . ' bytes';
    });

    check('an unknown shop is ignored', function () {
        // Webhooks arrive for stores that uninstalled, and for shops this
        // deployment has never seen. Neither is an error worth a 500, which
        // Shopify would retry for days.
        $r = Billing::applyWebhook('never-installed.myshopify.com', [
            'app_subscription' => ['status' => 'ACTIVE'],
        ]);

        assertTrue($r === null, 'an unknown shop returned ' . var_export($r, true));

        return 'returns null, does not throw';
    });
}

// -----------------------------------------------------------------
echo "\nStorage headroom\n";

check('no events belong to a store we no longer have', function () {
    // Orphaned events are data for a store with no record — the exact thing a
    // privacy review asks about, and something nothing else would notice. The
    // production path (Purge) clears every shard before the tenant row goes,
    // so anything here means a tenant was removed some other way.
    //
    // DISTINCT on tenant_id is the leading column of ix_tenant_time, so this
    // is an index scan rather than a walk of every row.
    $live   = array_map(
        'intval',
        Db::core()->query('SELECT tenant_id FROM tenants')->fetchAll(PDO::FETCH_COLUMN)
    );
    $orphans = [];
    $shards  = 0;

    foreach (Shard::all() as $shard) {
        try {
            $pdo = Shard::connectionForDate((string) $shard['date_from']);
        } catch (Throwable) {
            continue;
        }
        $shards++;

        foreach ($pdo->query('SELECT DISTINCT tenant_id FROM events')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            if (!in_array((int) $t, $live, true)) {
                $orphans[] = substr((string) $shard['date_from'], 0, 4) . ':tenant ' . (int) $t;
            }
        }
    }

    assertTrue($orphans === [], 'orphaned events in ' . implode(', ', $orphans));

    return "{$shards} shard(s) clean";
});
check('Shopify API version is current', function () {
    // Shopify supports each stable version for 12 months, then FALLS FORWARD
    // rather than erroring: requests to an expired version are quietly served
    // by the oldest supported one. So an app on a dead version keeps working
    // while no longer being pinned to anything it was written against, and
    // nothing anywhere says so. This is the only place that notices.
    //
    // It also matters for submission: Shopify will not accept an app on a
    // version due to expire within 90 days.
    //
    // From shopify.dev/docs/api/usage/versioning. Update when this list ages
    // past the end — the check fails rather than guesses.
    $schedule = [
        '2025-01' => '2026-01-16', '2025-04' => '2026-04-16',
        '2025-07' => '2026-07-16', '2025-10' => '2026-10-16',
        '2026-01' => '2027-01-16', '2026-04' => '2027-04-16',
        '2026-07' => '2027-07-16', '2026-10' => '2027-10-16',
    ];

    $configured = (string) Config::get('shopify.api_version', '');
    assertTrue($configured !== '', 'no API version configured');
    assertTrue(
        isset($schedule[$configured]),
        "'{$configured}' is not a version this check knows — extend the schedule"
    );

    $expires = strtotime($schedule[$configured] . ' 15:00:00 UTC');
    $days    = (int) floor(($expires - time()) / 86400);

    assertTrue($days > 0, "API version {$configured} expired " . abs($days) . ' days ago');
    assertTrue(
        $days > 90,
        "API version {$configured} expires in {$days} days; Shopify will not "
        . 'accept a submission on it, and it falls forward silently after that'
    );

    // A release candidate is one whose release date has not passed. Shopify
    // ships breaking changes into those and says not to run them in
    // production, but the CLI defaults new apps to the newest it knows.
    $released = strtotime(substr($configured, 0, 4) . '-' . substr($configured, 5, 2) . '-01 17:00:00 UTC');
    assertTrue($released < time(), "API version {$configured} is a release candidate, not stable");

    // The webhook version in the app config has to agree, or webhook payloads
    // arrive shaped differently from everything the sync code expects.
    $toml = (string) @file_get_contents(dirname(__DIR__) . '/shopify/shopify.app.toml');
    if (preg_match('/api_version\s*=\s*"([^"]+)"/', $toml, $m)) {
        assertTrue(
            $m[1] === $configured,
            "shopify.app.toml says {$m[1]} but the app is configured for {$configured}"
        );
    }

    return "{$configured}, stable for another " . number_format($days) . ' days';
});
check('money reads the way the merchant writes it', function () {
    // Three defects lived here, all of them the kind a merchant notices before
    // a developer does.
    $cases = [
        // INR groups the last three digits then in twos. A table showing
        // ₹123,456 beside a tile reading ₹1.2L is two different systems.
        ['money', 12345678,    'INR', '₹1,23,457'],
        ['money', 123456789,   'INR', '₹12,34,568'],
        ['money', 9999999999,  'INR', '₹10,00,00,000'],
        ['money', 99,          'INR', '₹0.99'],
        // Everyone else groups in threes.
        ['money', 12345678,    'USD', '$123,457'],

        // A unit must roll over when rounding fills it: a hundred lakh is a
        // crore, and a thousand million is a billion.
        ['moneyShort', 999999999,   'INR', '₹1Cr'],
        ['moneyShort', 999995000,   'INR', '₹1Cr'],
        ['moneyShort', 12345678,    'INR', '₹1.2L'],
        ['moneyShort', 9999999999,  'INR', '₹10Cr'],
        ['moneyShort', 99999999,    'USD', '$1M'],
        ['moneyShort', 99999999999, 'USD', '$1B'],
        ['moneyShort', 50000000000, 'USD', '$500M'],
    ];

    foreach ($cases as [$fn, $minor, $currency, $want]) {
        $got = Fmt::$fn($minor, $currency);
        assertTrue($got === $want, "{$fn}({$minor}, {$currency}) gave {$got}, expected {$want}");
    }

    // Unknown is a dash, never a zero — the rule the whole class exists for.
    assertTrue(Fmt::money(null) === Fmt::NONE, 'null money was not a dash');
    assertTrue(Fmt::moneyShort(null) === Fmt::NONE, 'null short money was not a dash');
    assertTrue(Fmt::pct(null) === Fmt::NONE, 'null percentage was not a dash');
    assertTrue(Fmt::num(null) === Fmt::NONE, 'null number was not a dash');

    // A zero-length bar for a zero value; a floor only above it.
    assertTrue(Fmt::barWidth(0, 100) === 0.0, 'a zero value drew a bar');
    assertTrue(Fmt::barWidth(null, 100) === 0.0, 'a null value drew a bar');
    assertTrue(Fmt::barWidth(5, 0) === 0.0, 'a zero maximum drew a bar');
    assertTrue(Fmt::barWidth(1, 1000) >= 0.5, 'a small real value collapsed to nothing');
    assertTrue(Fmt::barWidth(50, 100) === 50.0, 'a half value was not half a bar');

    return count($cases) . ' money cases, nulls, and bar widths';
});
check('every connection is strict', function () {
    // Without strict mode MySQL does not reject a value that will not fit — it
    // coerces it silently. An order total above the column maximum is clamped,
    // an over-long string is truncated, an impossible date becomes zeroes, and
    // nothing anywhere reports it. Every figure downstream is then confidently
    // wrong, which is the worst thing this application can do.
    //
    // The production server runs WITHOUT strict mode by default, so it is set
    // per connection rather than assumed from the server config. This checks
    // the setting actually took, on whichever environment is being tested.
    $targets = ['core' => Db::core()];

    try {
        $targets['shard'] = Shard::connectionForDate(gmdate('Y-m-d'));
    } catch (Throwable) {
        // No shard reachable; the core check still stands.
    }

    $seen = [];
    foreach ($targets as $name => $pdo) {
        $mode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();

        assertTrue(
            str_contains($mode, 'STRICT_TRANS_TABLES') || str_contains($mode, 'STRICT_ALL_TABLES'),
            "the {$name} connection is not strict: " . ($mode === '' ? '(empty)' : $mode)
        );

        // And prove it behaves, rather than trusting the variable: writing a
        // value too large for the column must raise, not truncate.
        $pdo->exec('CREATE TEMPORARY TABLE IF NOT EXISTS _strict_probe (n TINYINT UNSIGNED NOT NULL)');
        $threw = false;
        try {
            $pdo->exec('INSERT INTO _strict_probe (n) VALUES (999)');
        } catch (Throwable) {
            $threw = true;
        }
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS _strict_probe');

        assertTrue($threw, "the {$name} connection silently truncated an out-of-range value");
        $seen[] = $name;
    }

    return implode(' + ', $seen) . ' reject out-of-range writes';
});
check('shard size measured', function () {
    $m = Shard::measure(Shard::current());
    return sprintf(
        '%.2f MB of %.0f MB (%.2f%%)%s',
        $m['bytes'] / 1048576,
        $m['limit'] / 1048576,
        $m['ratio'] * 100,
        $m['over_warn'] ? '  <-- OVER WARNING THRESHOLD' : ''
    );
});

// -----------------------------------------------------------------------
// Local-only artefact: remove the salts created by the hashing tests.
foreach ([$testTenant, $testTenant + 1] as $t) {
    $f = Config::get('secrets.salt_dir') . '/tenant_' . $t . '.salt';
    if (is_file($f)) {
        @unlink($f);
    }
}

echo "\n" . str_repeat('-', 78) . "\n";
printf("%d passed, %d failed, %d skipped\n\n", $passed, $failed, $skipped);

exit($failed > 0 ? 1 : 0);
