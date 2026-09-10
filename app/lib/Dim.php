<?php
/**
 * Dimension interning.
 *
 * Every repeating string in an event — page path, referrer, UTM tuple, user
 * agent, city, search term — is stored once in a dim_* table and referenced
 * from events by a 4-byte integer.
 *
 * This is what makes the row-size budget work. Storing these inline would put
 * the events row near 2 KB, exhausting a 3 GB shard in about a month; interned
 * it is ~130 bytes compressed and a shard lasts around fourteen. See
 * TECHNICAL_PLAN.md section 6.2.
 *
 * Lookups are memoised for the life of the process. An import run resolves the
 * same handful of paths and campaigns thousands of times, so the cache turns
 * most of those into no queries at all.
 */

declare(strict_types=1);

final class Dim
{
    /** @var array<string,int|null> memo, keyed by table + tenant + hash */
    private static array $memo = [];

    /** Guards against one long-running import holding unbounded memory. */
    private const MEMO_MAX = 50000;

    // -----------------------------------------------------------------
    // Public resolvers
    // -----------------------------------------------------------------

    public static function visitor(int $tenantId, string $clientId): ?int
    {
        if ($clientId === '') {
            return null;
        }

        $hash = Hash::visitor($tenantId, $clientId);

        return self::intern('dim_visitor', 'visitor_key', $tenantId, 'visitor_hash', $hash, [
            'first_seen_at' => gmdate('Y-m-d H:i:s'),
            'last_seen_at'  => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public static function path(int $tenantId, ?string $url): ?int
    {
        if ($url === null || $url === '') {
            return null;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $path = (string) Text::fit($path, 512);

        return self::intern('dim_path', 'path_id', $tenantId, 'path_hash', Hash::dim($path), [
            'path'      => $path,
            'page_type' => self::pageType($path),
        ]);
    }

    public static function referrer(int $tenantId, ?string $url): ?int
    {
        if ($url === null || $url === '') {
            return null;
        }

        $url  = (string) Text::fit($url, 512);
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

        return self::intern('dim_referrer', 'referrer_id', $tenantId, 'referrer_hash', Hash::dim($url), [
            'referrer_host' => Text::fitOrNull($host, 191),
            'referrer_url'  => $url,
        ]);
    }

    /**
     * Campaign from a URL's query string.
     *
     * Returns null when a URL carries no attribution at all, so untagged
     * traffic stays distinguishable from a campaign whose tags are empty.
     * gclid and fbclid are recorded as flags rather than values: the click id
     * itself is useless to us, but its presence is what separates "genuinely
     * direct" from "tagged, then stripped in transit" in the unattributed
     * diagnostics (section 10.2).
     */
    public static function campaign(int $tenantId, ?string $url): ?int
    {
        if ($url === null || $url === '') {
            return null;
        }

        $query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');
        if ($query === '') {
            return null;
        }

        parse_str($query, $q);

        $utm = [];
        foreach (['source', 'medium', 'campaign', 'content', 'term'] as $k) {
            $v = $q['utm_' . $k] ?? null;
            $utm[$k] = is_string($v) ? Text::fitOrNull($v, 191) : null;
        }

        $gclid  = isset($q['gclid'])  ? 1 : 0;
        $fbclid = isset($q['fbclid']) ? 1 : 0;

        if (array_filter($utm) === [] && $gclid === 0 && $fbclid === 0) {
            return null;
        }

        $tuple = implode("\x1f", [
            $utm['source'] ?? '', $utm['medium'] ?? '', $utm['campaign'] ?? '',
            $utm['content'] ?? '', $utm['term'] ?? '', (string) $gclid, (string) $fbclid,
        ]);

        return self::intern('dim_campaign', 'campaign_id', $tenantId, 'tuple_hash', Hash::dim($tuple), [
            'utm_source'   => $utm['source'],
            'utm_medium'   => $utm['medium'],
            'utm_campaign' => $utm['campaign'],
            'utm_content'  => $utm['content'],
            'utm_term'     => $utm['term'],
            'has_gclid'    => $gclid,
            'has_fbclid'   => $fbclid,
        ]);
    }

    public static function userAgent(int $tenantId, ?string $ua): ?int
    {
        if ($ua === null || $ua === '') {
            return null;
        }

        $ua = (string) Text::fit($ua, 400);
        [$device, $browser, $os] = self::parseUserAgent($ua);

        return self::intern('dim_useragent', 'ua_id', $tenantId, 'ua_hash', Hash::dim($ua), [
            'device_type' => $device,
            'browser'     => $browser,
            'os'          => $os,
        ]);
    }

    /**
     * Geo is shared across tenants and carries no personal data — a city name
     * identifies nobody — so unlike the other dimensions it is not partitioned
     * by tenant and needs no salt.
     *
     * @param array{country?:?string,region?:?string,city?:?string,lat?:?float,lon?:?float}|null $geo
     */
    public static function geo(?array $geo): ?int
    {
        if ($geo === null) {
            return null;
        }

        $country = $geo['country'] ?? null;
        $region  = $geo['region'] ?? null;
        $city    = $geo['city'] ?? null;

        if ($country === null && $city === null) {
            return null;
        }

        $key  = strtolower(($country ?? '') . '|' . ($region ?? '') . '|' . ($city ?? ''));
        $hash = Hash::dim($key);
        $memo = 'dim_geo|0|' . bin2hex($hash);

        if (array_key_exists($memo, self::$memo)) {
            return self::$memo[$memo];
        }

        $pdo  = Db::core();
        $stmt = $pdo->prepare('SELECT geo_id FROM dim_geo WHERE geo_hash = ?');
        $stmt->execute([$hash]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            $ins = $pdo->prepare(
                'INSERT INTO dim_geo (geo_hash, country, region, city, lat, lon)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE geo_id = LAST_INSERT_ID(geo_id)'
            );
            $ins->execute([
                $hash,
                Text::fit($country, 2),
                Text::fit($region, 96),
                Text::fit($city, 96),
                $geo['lat'] ?? null,
                $geo['lon'] ?? null,
            ]);
            $id = (int) $pdo->lastInsertId();
        }

        return self::remember($memo, (int) $id);
    }

    public static function searchTerm(int $tenantId, ?string $term): ?int
    {
        if ($term === null || trim($term) === '') {
            return null;
        }

        $term = (string) Text::fit(trim($term), 255);

        return self::intern('dim_search_term', 'search_term_id', $tenantId, 'term_hash', Hash::dim(strtolower($term)), [
            'term' => $term,
        ]);
    }

    public static function clickTarget(int $tenantId, ?string $label, ?string $selector, ?string $href): ?int
    {
        if ($label === null && $selector === null && $href === null) {
            return null;
        }

        $label    = Text::fit($label, 255);
        $selector = Text::fit($selector, 255);
        $href     = Text::fit($href, 512);

        $key = ($label ?? '') . "\x1f" . ($selector ?? '') . "\x1f" . ($href ?? '');

        return self::intern('dim_click_target', 'click_target_id', $tenantId, 'target_hash', Hash::dim($key), [
            'label'    => $label,
            'selector' => $selector,
            'href'     => $href,
        ]);
    }

    /** Called between import runs so a long process does not grow unbounded. */
    public static function flushMemo(): void
    {
        self::$memo = [];
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Look up a hash in a dimension table, inserting it if absent.
     *
     * SELECT first rather than INSERT ... ON DUPLICATE KEY, so repeated
     * lookups do not burn auto-increment values. Most calls never reach the
     * database at all because of the memo.
     *
     * @param array<string,mixed> $columns
     */
    private static function intern(
        string $table,
        string $idColumn,
        int $tenantId,
        string $hashColumn,
        string $hash,
        array $columns
    ): ?int {
        $memoKey = $table . '|' . $tenantId . '|' . bin2hex($hash);

        if (array_key_exists($memoKey, self::$memo)) {
            return self::$memo[$memoKey];
        }

        $pdo = Db::core();

        $sel = $pdo->prepare(
            "SELECT {$idColumn} FROM {$table} WHERE tenant_id = ? AND {$hashColumn} = ?"
        );
        $sel->execute([$tenantId, $hash]);
        $id = $sel->fetchColumn();

        if ($id !== false) {
            return self::remember($memoKey, (int) $id);
        }

        $cols   = array_merge(['tenant_id' => $tenantId, $hashColumn => $hash], $columns);
        $names  = implode(', ', array_keys($cols));
        $marks  = implode(', ', array_fill(0, count($cols), '?'));

        $ins = $pdo->prepare(
            "INSERT INTO {$table} ({$names}) VALUES ({$marks})
             ON DUPLICATE KEY UPDATE {$idColumn} = LAST_INSERT_ID({$idColumn})"
        );
        $ins->execute(array_values($cols));

        return self::remember($memoKey, (int) $pdo->lastInsertId());
    }

    private static function remember(string $key, ?int $value): ?int
    {
        if (count(self::$memo) >= self::MEMO_MAX) {
            self::$memo = [];
        }
        return self::$memo[$key] = $value;
    }

    /** Shopify URL conventions are stable enough to classify on. */
    private static function pageType(string $path): ?string
    {
        return match (true) {
            $path === '/' || $path === ''            => 'home',
            str_starts_with($path, '/products/')     => 'product',
            str_starts_with($path, '/collections/')  => 'collection',
            str_starts_with($path, '/cart')          => 'cart',
            str_starts_with($path, '/checkouts/')    => 'checkout',
            str_starts_with($path, '/search')        => 'search',
            str_starts_with($path, '/pages/')        => 'page',
            str_starts_with($path, '/blogs/')        => 'blog',
            str_starts_with($path, '/account')       => 'account',
            default                                  => 'other',
        };
    }

    /**
     * Enough user-agent parsing for device / browser / OS breakdowns.
     *
     * Deliberately crude. A full parser is a large dependency needing regular
     * updates, and the dashboard only asks "mobile or desktop, roughly which
     * browser" — not which build of Chrome. Order matters: Edge and Opera both
     * claim Chrome, and Chrome claims Safari.
     *
     * @return array{0:int,1:?string,2:?string} device_type, browser, os
     */
    private static function parseUserAgent(string $ua): array
    {
        $l = strtolower($ua);

        $device = match (true) {
            str_contains($l, 'bot') || str_contains($l, 'crawler')
                || str_contains($l, 'spider') || str_contains($l, 'headless') => 4,
            str_contains($l, 'ipad') || str_contains($l, 'tablet')            => 3,
            str_contains($l, 'mobi') || str_contains($l, 'android')
                || str_contains($l, 'iphone')                                 => 1,
            default                                                            => 2,
        };

        $browser = match (true) {
            str_contains($l, 'edg/')                                   => 'Edge',
            str_contains($l, 'opr/') || str_contains($l, 'opera')      => 'Opera',
            str_contains($l, 'samsungbrowser')                         => 'Samsung Internet',
            str_contains($l, 'firefox')                                => 'Firefox',
            str_contains($l, 'chrome') || str_contains($l, 'crios')    => 'Chrome',
            str_contains($l, 'safari')                                 => 'Safari',
            default                                                     => null,
        };

        $os = match (true) {
            str_contains($l, 'android')                             => 'Android',
            str_contains($l, 'iphone') || str_contains($l, 'ipad')
                || str_contains($l, 'ios')                          => 'iOS',
            str_contains($l, 'windows')                             => 'Windows',
            str_contains($l, 'mac os') || str_contains($l, 'macos') => 'macOS',
            str_contains($l, 'linux')                               => 'Linux',
            default                                                  => null,
        };

        return [$device, $browser, $os];
    }
}
