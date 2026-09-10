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
