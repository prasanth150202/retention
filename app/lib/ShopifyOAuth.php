<?php
/**
 * Shopify OAuth for a public app.
 *
 * Descended from shopify_key_fetch/shopify_token_exchange.py, whose HMAC and
 * state handling were correct and are preserved. Three things changed with
 * public distribution:
 *
 *   1. ONE app, not one per store. The client id and secret are ours and live
 *      in .env, rather than being pasted per install. oauth_state therefore no
 *      longer carries an encrypted client secret — only the CSRF nonce.
 *
 *   2. EXPIRING TOKENS ARE MANDATORY. Public apps cannot use non-expiring
 *      offline tokens for the GraphQL Admin API. Requests carry expiring=1 and
 *      the response includes a refresh token. See refresh().
 *
 *   3. Managed installation. Scopes are declared in shopify.app.toml and
 *      granted by Shopify at install, so the merchant is not prompted a second
 *      time when we redirect to authorize. We still build that redirect — it
 *      is how a non-embedded app obtains a token, since token exchange needs
 *      an App Bridge session token and there is no App Bridge here.
 */

declare(strict_types=1);

final class ShopifyOAuth
{
    /**
     * Must match [access_scopes] in shopify/shopify.app.toml.
     *
     * Under managed installation Shopify grants what the TOML declares; this
     * list is what we ask for in the redirect and what verifyScopes() checks
     * the grant against. Drift between the two is silent, which is why
     * scopesMatchToml() exists and the self-test runs it.
     */
    public const SCOPES = [
        'read_all_orders',      // history beyond 60 days — retention needs it
        'read_orders',
        'read_customers',       // protected customer data; email + phone, hashed
        'read_products',
        'read_checkouts',
        'read_inventory',
        'read_price_rules',
        'read_locales',
        'write_pixels',         // create the web pixel on install
        'read_customer_events', // receive what it emits
    ];

    private const STATE_TTL = 900;   // 15 minutes

    public static function clientId(): string
    {
        $v = (string) Config::get('shopify.client_id', '');
        if ($v === '') {
            throw new RuntimeException(
                'SHOPIFY_CLIENT_ID is not set. Find it in the Partner Dashboard under '
                . 'your app > Client credentials.'
            );
        }
        return $v;
    }

    public static function clientSecret(): string
    {
        $v = (string) Config::get('shopify.client_secret', '');
        if ($v === '') {
            throw new RuntimeException('SHOPIFY_CLIENT_SECRET is not set.');
        }
        return $v;
    }

    public static function configured(): bool
    {
        return (string) Config::get('shopify.client_id', '') !== ''
            && (string) Config::get('shopify.client_secret', '') !== '';
    }

    public static function normaliseShop(string $raw): string
    {
        $shop = strtolower(trim($raw));
        $shop = preg_replace('~^https?://~', '', $shop) ?? $shop;
        $shop = rtrim((string) $shop, '/');

        if (!str_contains($shop, '.')) {
            $shop .= '.myshopify.com';
        }
        if (!preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', $shop)) {
            throw new InvalidArgumentException("'{$raw}' is not a valid myshopify.com domain.");
        }

        return $shop;
    }

    /**
     * Where to send a merchant who has no token yet.
     *
     * With managed installation the scopes are already granted, so this
     * normally passes straight through without a consent screen and returns
     * with a code.
     */
    public static function authorizeUrl(string $shop): string
    {
        $shop  = self::normaliseShop($shop);
        $state = bin2hex(random_bytes(24));

        Db::core()->prepare(
            'INSERT INTO oauth_state (state, shop_domain, client_id, client_secret_enc, created_at, expires_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? SECOND)'
        )->execute([
            $state,
            $shop,
            self::clientId(),
            // Retained for the column's NOT NULL constraint. A public app has
            // one secret, held in .env, so there is nothing per-install to
            // store — and an empty blob is better than a copy of the secret
            // in every row.
            Crypto::encrypt(''),
            self::STATE_TTL,
        ]);

        self::sweepExpiredStates();

        return 'https://' . $shop . '/admin/oauth/authorize?' . http_build_query([
            'client_id'    => self::clientId(),
            'scope'        => implode(',', self::SCOPES),
            'redirect_uri' => Config::require('hosts.oauth_redirect'),
            'state'        => $state,
        ]);
    }

    /**
     * Handle the callback and return a token set.
     *
     * Four checks before anything is trusted:
     *   1. state exists, unexpired, unused   (CSRF, replay)
     *   2. shop matches the one we started   (substitution)
     *   3. HMAC verifies                     (authenticity)
     *   4. only then exchange the code
     *
     * @param array<string,mixed> $query raw $_GET
     * @return array{shop:string,access_token:string,refresh_token:?string,expires_in:?int,refresh_token_expires_in:?int,scope:string}
     */
    public static function completeInstall(array $query, string $rawQueryString): array
    {
        $state = (string) ($query['state'] ?? '');
        $shop  = (string) ($query['shop'] ?? '');
        $code  = (string) ($query['code'] ?? '');

        if ($state === '' || $code === '' || $shop === '') {
            throw new RuntimeException('Incomplete callback from Shopify.');
        }

        $stmt = Db::core()->prepare(
            'SELECT shop_domain, consumed_at FROM oauth_state
              WHERE state = ? AND expires_at > UTC_TIMESTAMP()'
        );
        $stmt->execute([$state]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException(
                'This install link has expired. Start the installation again from Shopify.'
            );
        }
        if ($row['consumed_at'] !== null) {
            throw new RuntimeException('This install link has already been used.');
        }

        $expectedShop = (string) $row['shop_domain'];
        if (self::normaliseShop($shop) !== $expectedShop) {
            throw new RuntimeException(
                "Callback is for {$shop} but the install began for {$expectedShop}."
            );
        }

        if (!self::verifyHmac($rawQueryString)) {
            throw new RuntimeException(
                'HMAC verification failed — this callback did not come from Shopify.'
            );
        }

        // Single-use, marked before the exchange so a replayed callback cannot
        // mint a second token even if the first attempt fails.
        Db::core()->prepare('UPDATE oauth_state SET consumed_at = UTC_TIMESTAMP() WHERE state = ?')
            ->execute([$state]);

        $t = self::post($expectedShop, [
            'client_id'     => self::clientId(),
            'client_secret' => self::clientSecret(),
            'code'          => $code,
            // Public apps must use expiring tokens for the GraphQL Admin API.
            'expiring'      => 1,
        ]);

        return [
            'shop'                     => $expectedShop,
            'access_token'             => $t['access_token'],
            'refresh_token'            => $t['refresh_token'] ?? null,
            'expires_in'               => isset($t['expires_in']) ? (int) $t['expires_in'] : null,
            'refresh_token_expires_in' => isset($t['refresh_token_expires_in'])
                ? (int) $t['refresh_token_expires_in'] : null,
            'scope'                    => (string) ($t['scope'] ?? ''),
        ];
    }

    /**
     * Trade a refresh token for a fresh access token.
     *
     * Avoids sending the merchant back through authorization, which for a
     * background sync job would mean simply stopping until someone noticed.
     *
     * @return array{access_token:string,refresh_token:?string,expires_in:?int,refresh_token_expires_in:?int,scope:string}
     */
    public static function refresh(string $shop, string $refreshToken): array
    {
        $t = self::post(self::normaliseShop($shop), [
            'client_id'     => self::clientId(),
            'client_secret' => self::clientSecret(),
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        return [
            'access_token'             => $t['access_token'],
            'refresh_token'            => $t['refresh_token'] ?? $refreshToken,
            'expires_in'               => isset($t['expires_in']) ? (int) $t['expires_in'] : null,
            'refresh_token_expires_in' => isset($t['refresh_token_expires_in'])
                ? (int) $t['refresh_token_expires_in'] : null,
            'scope'                    => (string) ($t['scope'] ?? ''),
        ];
    }

    /**
     * Verify a Shopify-signed query string.
     *
     * Used for the OAuth callback AND for the app URL a merchant lands on from
     * the admin, which is signed the same way — which is what makes merchant
     * login for a non-embedded app almost free.
     *
     * Built from the RAW query string rather than by re-encoding $_GET:
     * Shopify signs the bytes it sent, and re-encoding a parsed array can
     * differ in space and reserved-character handling, producing a failure
     * with no visible cause.
     */
    public static function verifyHmac(string $rawQueryString, ?string $secret = null): bool
    {
        $secret ??= self::clientSecret();

        $received = '';
        $parts    = [];

        foreach (explode('&', $rawQueryString) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');

            if ($k === 'hmac') {
                $received = urldecode($v);
                continue;
            }
            if ($k === 'signature') {
                continue;   // legacy, excluded from the digest
            }
            $parts[$k] = $pair;
        }

        if ($received === '') {
            return false;
        }

        ksort($parts);

        return hash_equals(hash_hmac('sha256', implode('&', $parts), $secret), $received);
    }

    /**
     * Which requested scopes were actually granted?
     *
     * read_all_orders is the one that matters: it is granted only after a
     * reviewed access request, and its absence is silent — the API simply
     * returns 60 days of orders and every cohort chart comes out empty.
     *
     * @return array{granted:string[],missing:string[],history_limited:bool}
     */
    public static function verifyScopes(string $grantedScopes): array
    {
        $granted = array_values(array_filter(array_map('trim', explode(',', $grantedScopes))));
        $missing = array_values(array_diff(self::SCOPES, $granted));

        return [
            'granted'         => $granted,
            'missing'         => $missing,
            'history_limited' => in_array('read_all_orders', $missing, true),
        ];
    }

    /**
     * Do the scopes here match shopify.app.toml?
     *
     * Managed installation grants what the TOML declares, so if the two drift
     * the app requests one set and receives another, and nothing complains.
     *
     * @return array{match:bool,only_in_php:string[],only_in_toml:string[]}
     */
    public static function scopesMatchToml(?string $tomlPath = null): array
    {
        $tomlPath ??= dirname(__DIR__, 2) . '/shopify/shopify.app.toml';

        if (!is_file($tomlPath)) {
            return ['match' => false, 'only_in_php' => self::SCOPES, 'only_in_toml' => []];
        }

        $toml = (string) file_get_contents($tomlPath);
        preg_match('/^\s*scopes\s*=\s*"([^"]*)"/m', $toml, $m);

        $inToml = array_values(array_filter(array_map('trim', explode(',', $m[1] ?? ''))));

        return [
            'match'        => array_diff(self::SCOPES, $inToml) === []
                           && array_diff($inToml, self::SCOPES) === [],
            'only_in_php'  => array_values(array_diff(self::SCOPES, $inToml)),
            'only_in_toml' => array_values(array_diff($inToml, self::SCOPES)),
        ];
    }

    // -----------------------------------------------------------------

    /** @param array<string,mixed> $fields */
    private static function post(string $shop, array $fields): array
    {
        $ch = curl_init("https://{$shop}/admin/oauth/access_token");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($fields),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $http !== 200) {
            // Never echo the response: it can contain the code or the secret.
            throw new RuntimeException(
                "Shopify token endpoint returned HTTP {$http}" . ($err !== '' ? ": {$err}" : '')
            );
        }

        $data = json_decode((string) $body, true);

        if (!is_array($data) || !isset($data['access_token'])) {
            throw new RuntimeException('Shopify returned no access token.');
        }

        return $data;
    }

    private static function sweepExpiredStates(): void
    {
        Db::core()->exec('DELETE FROM oauth_state WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    }
}
