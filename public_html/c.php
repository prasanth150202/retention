<?php
/**
 * Project Odysseus — pixel ingest endpoint.
 *
 * Called by every page view on every connected storefront, via sendBeacon.
 * Its entire job is: validate cheaply, append a line to a file, return 204.
 *
 * IT NEVER TOUCHES MySQL ON THE HAPPY PATH. That is deliberate:
 *   - a database outage must not lose events, only delay them
 *   - shared hosting limits concurrent connections, and ingest must not
 *     compete with the dashboard for them
 *   - appending a line is ~50us; a connect + insert is 10-50x that
 *
 * import.php picks the files up on a cron and does the expensive work.
 *
 * ON THE "SECRET" IN THE SNIPPET
 * The write_key is embedded in JavaScript served to every visitor. It is
 * readable by anyone with View Source and is NOT a credential. It identifies
 * which tenant an event belongs to and filters out random noise. The actual
 * protections are the Origin check, the rate limit and the size cap below.
 * See TECHNICAL_PLAN.md section 7.2.
 */

declare(strict_types=1);

// Never let a warning turn into a 200 with an HTML body: the pixel expects
// an empty response and a browser will happily retry a malformed one.
ini_set('display_errors', '0');

$ROOT = dirname(__DIR__);

require_once $ROOT . '/app/lib/Env.php';
require_once $ROOT . '/app/lib/Config.php';

/** Empty response, no body, no cache. */
function done(int $status): never
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('Content-Length: 0');
    exit;
}

// ---------------------------------------------------------------------
// 1. Shape of the request
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    done(405);
}

// sendBeacon is fire-and-forget and cannot read a response, so CORS headers
// would be pointless. text/plain is what keeps it a "simple request" and
// avoids a preflight OPTIONS on every page view.
$len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

try {
    Config::init();
} catch (Throwable) {
    // Misconfiguration must not look like success, or the pixel will drop
    // events believing they were accepted.
    done(500);
}

$maxBytes = (int) Config::get('ingest.max_body_bytes', 65536);

if ($len > $maxBytes) {
    done(413);
}

$body = file_get_contents('php://input', false, null, 0, $maxBytes + 1);

if ($body === false || $body === '' || strlen($body) > $maxBytes) {
    done(400);
}

$payload = json_decode($body, true);

if (!is_array($payload) || !isset($payload['k'], $payload['e']) || !is_array($payload['e'])) {
    done(400);
}

$writeKey = (string) $payload['k'];
$events   = $payload['e'];

if ($writeKey === '' || $events === [] || count($events) > 100) {
    done(400);
}

// ---------------------------------------------------------------------
// 2. Which tenant?
//
// Resolved from a small generated PHP file rather than a query, so the hot
// path stays off the database. import.php refreshes it every run; a cache
// miss falls back to one query and rewrites the file.
// ---------------------------------------------------------------------
$tenant = tenantFor($writeKey, $ROOT);

if ($tenant === null) {
    done(403);
}

// ---------------------------------------------------------------------
// 3. Origin check
//
// The real access control. An event claiming to be from a tenant must
// actually have been sent by a page on that tenant's storefront.
//
// Shopify checkout runs on <shop>.myshopify.com even when the storefront
// uses a custom domain, so both are accepted.
// ---------------------------------------------------------------------
if (Config::get('ingest.enforce_origin', true)) {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
    $host   = strtolower((string) parse_url($origin, PHP_URL_HOST));

    if ($host !== '' && !originAllowed($host, $tenant['domains'])) {
        done(403);
    }
    // An absent Origin is allowed through: some privacy modes strip it, and
    // rejecting those would silently lose real traffic. The write_key plus
    // the rate limit still apply.
}

// ---------------------------------------------------------------------
// 4. Rate limit, per IP per minute
// ---------------------------------------------------------------------
$ip = clientIp();

if (!rateLimitOk($ip, (int) Config::get('ingest.rate_limit_per_min', 240))) {
    done(429);
}

// ---------------------------------------------------------------------
// 5. Append to the spool
//
// One file per tenant per hour. NOT one file per request: at projected
// volume that would be ~160,000 files a month and breach the account's
// inode limit.
// ---------------------------------------------------------------------
$spoolDir = Config::get('paths.spool') . '/' . $tenant['id'];

if (!is_dir($spoolDir) && !@mkdir($spoolDir, 0700, true) && !is_dir($spoolDir)) {
    done(500);
}

$file      = $spoolDir . '/' . gmdate('YmdH') . '.ndjson';
$receivedAt = (int) (microtime(true) * 1000);
$ua        = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);

$lines = '';
foreach ($events as $e) {
    if (!is_array($e)) {
        continue;
    }
    // Server-side facts the client cannot be trusted to supply.
    $e['_t']  = $tenant['id'];
    $e['_ip'] = $ip;
    $e['_ua'] = $ua;
    $e['_rx'] = $receivedAt;

    $json = json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json !== false) {
        $lines .= $json . "\n";
    }
}

if ($lines === '') {
    done(400);
}

// LOCK_EX makes concurrent appends safe. Lines are well under the 4 KB at
// which atomicity stops being guaranteed.
if (@file_put_contents($file, $lines, FILE_APPEND | LOCK_EX) === false) {
    // Failing loudly matters more than failing quietly: a 500 tells us the
    // spool is broken, where a 204 would silently discard real events.
    done(500);
}

done(204);


// =====================================================================
// Helpers
// =====================================================================

/**
 * Tenant record for a write key, or null.
 *
 * @return array{id:int,domains:string[]}|null
 */
function tenantFor(string $writeKey, string $root): ?array
{
    $cacheFile = Config::get('paths.storage') . '/tenants.php';

    if (is_file($cacheFile)) {
        /** @var array<string,array{id:int,domains:string[]}> $map */
        $map = @include $cacheFile;
        if (is_array($map)) {
            if (isset($map[$writeKey])) {
                return $map[$writeKey];
            }
            // Present but unknown key. Rebuild once in case a store was
            // onboarded since the cache was written.
            if ((time() - (int) @filemtime($cacheFile)) < 60) {
                return null;   // rebuilt recently; the key really is unknown
            }
        }
    }

    return rebuildTenantCache($root, $cacheFile)[$writeKey] ?? null;
}

/**
 * Regenerate the write_key -> tenant map from the database.
 *
 * The only place this endpoint touches MySQL, and only on a cache miss.
 *
 * @return array<string,array{id:int,domains:string[]}>
 */
function rebuildTenantCache(string $root, string $cacheFile): array
{
    require_once $root . '/app/lib/Db.php';

    $map = [];

    try {
        $rows = Db::core()->query(
            "SELECT tenant_id, write_key, shop_domain, custom_domain
               FROM tenants WHERE status = 'active'"
        )->fetchAll();
    } catch (Throwable) {
        return [];
    }

    foreach ($rows as $r) {
        $domains = array_values(array_filter([
            strtolower((string) $r['shop_domain']),
            strtolower((string) ($r['custom_domain'] ?? '')),
        ]));

        $map[(string) $r['write_key']] = [
            'id'      => (int) $r['tenant_id'],
            'domains' => $domains,
        ];
    }

    $tmp = $cacheFile . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, "<?php return " . var_export($map, true) . ";\n", LOCK_EX) !== false) {
        @rename($tmp, $cacheFile);   // atomic; a partial file is never included
    }

    return $map;
}

/** Exact host match, or a subdomain of an allowed host. */
function originAllowed(string $host, array $domains): bool
{
    foreach ($domains as $d) {
        if ($d !== '' && ($host === $d || str_ends_with($host, '.' . $d))) {
            return true;
        }
    }
    return false;
}

/**
 * Visitor IP.
 *
 * X-Forwarded-For is only trusted for its LAST entry, which the reverse
 * proxy appends; earlier entries are attacker-controlled. Used for geo
 * lookup and rate limiting, and never stored - there is no ip column.
 */
function clientIp(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($xff !== '') {
        $parts = array_map('trim', explode(',', $xff));
        $last  = end($parts);
        if ($last !== false && filter_var($last, FILTER_VALIDATE_IP)) {
            return $last;
        }
    }

    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
}

/**
 * Per-IP-per-minute counter held in one small file per minute.
 *
 * One file per minute rather than per IP, because per-IP files would create
 * inodes faster than the account can afford. Stale files are swept
 * opportunistically.
 */
function rateLimitOk(string $ip, int $limit): bool
{
    if ($ip === '' || $limit <= 0) {
        return true;
    }

    $dir = Config::get('paths.locks');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return true;   // never reject real traffic because of a missing folder
    }

    $minute = gmdate('YmdHi');
    $file   = $dir . '/rl_' . $minute . '.php';
    $key    = substr(hash('xxh3', $ip) ?: md5($ip), 0, 12);

    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return true;
    }

    $ok = true;

    if (flock($fh, LOCK_EX)) {
        $raw     = stream_get_contents($fh) ?: '';
        $counts  = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
        $current = (int) ($counts[$key] ?? 0);

        if ($current >= $limit) {
            $ok = false;
        } else {
            $counts[$key] = $current + 1;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($counts));
        }

        flock($fh, LOCK_UN);
    }

    fclose($fh);

    // Roughly once every 500 requests, clear counters older than 5 minutes.
    if (random_int(1, 500) === 1) {
        foreach (glob($dir . '/rl_*.php') ?: [] as $old) {
            if (@filemtime($old) < time() - 300) {
                @unlink($old);
            }
        }
    }

    return $ok;
}
