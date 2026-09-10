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
    $f = ShopifyOAuth::verifyScopes(implode(',', ShopifyOAuth::SCOPES));
    assertTrue($f['history_limited'] === false && $f['missing'] === [], 'false positive on a full grant');
    return 'flagged when absent, quiet when granted';
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
echo "\nMerchant session\n";

check('unsigned request creates no session', function () {
    assertTrue(
        Merchant::fromSignedRequest(['shop' => 'demo.myshopify.com'], 'shop=demo.myshopify.com') === null,
        'a session was created without a signature'
    );
    return 'correctly refused';
});
check('stale signed request refused', function () use ($signed) {
    // A signed URL is a bearer credential while it verifies. Bounding its age
    // means one leaked into a browser history or a support ticket expires.
    $secret = 'shpss_merchant_test_secret_012345';
    $old    = ['shop' => 'demo.myshopify.com', 'timestamp' => (string) (time() - 8000)];
    $qs     = $signed($old, $secret);
    parse_str($qs, $parsed);
    assertTrue(Merchant::fromSignedRequest($parsed, $qs) === null, 'a stale link was accepted');
    return 'correctly refused';
});

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

    $atShard->prepare('DELETE FROM events WHERE tenant_id = ?')->execute([$atT]);
    $atPdo->prepare('DELETE FROM tenants WHERE shop_domain = ?')->execute([$atShop]);
}

// -----------------------------------------------------------------
echo "\nStorage headroom\n";

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
