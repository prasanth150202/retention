<?php
/**
 * Shopify OAuth — install URL and authorisation-code exchange.
 *
 * A port of shopify_key_fetch/shopify_token_exchange.py, whose HMAC and state
 * handling were correct and are reproduced here. Two things change: the
 * redirect moves from a localhost callback to a public HTTPS URL, and the
 * scope list drops from 75 to 8.
 *
 * ON THE SCOPES
 * read_all_orders and read_customers must ALSO be requested in the Partner
 * Dashboard app configuration — putting them in the scope string is not
 * enough. Without read_all_orders the Admin API returns only the last 60 days
 * of orders, which empties every retention, cohort and LTV metric this
 * platform exists to produce. verifyScopes() reports that after the exchange
 * rather than letting it be discovered months later via empty charts.
 */

declare(strict_types=1);

final class ShopifyOAuth
{
    /**
     * Eight scopes. The original script asked for 75, including gift card
     * transactions, Shopify Payments payouts, bank accounts, disputes, themes
     * and translations. Beyond being unnecessary, a consent screen of that
     * breadth is a fair reason for a client's developer to refuse the install.
     */
    public const SCOPES = [
        'read_all_orders',    // history beyond 60 days — retention depends on it
        'read_orders',
        'read_customers',     // protected customer data; declare it on the app too
        'read_products',
        'read_checkouts',     // abandoned checkouts
        'read_inventory',
        'read_price_rules',
        'read_locales',
    ];

    private const STATE_TTL = 900;   // 15 minutes

    /** Normalise anything the user might paste into a myshopify domain. */
    public static function normaliseShop(string $raw): string
    {
        $shop = strtolower(trim($raw));
        $shop = preg_replace('~^https?://~', '', $shop) ?? $shop;
        $shop = rtrim((string) $shop, '/');

        if (!str_contains($shop, '.')) {
            $shop .= '.myshopify.com';
        }
        if (!preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', $shop)) {
            throw new InvalidArgumentException(
                "'{$raw}' is not a valid myshopify.com domain."
            );
        }

        return $shop;
    }

    /**
     * Begin an install: park the state and return the URL to send the merchant.
     *
     * The client secret is stored encrypted because it is needed later, in the
     * callback, to verify the HMAC — and a plaintext app secret in a database
     * row would let anyone with a database dump forge callbacks.
     */
    public static function beginInstall(string $shop, string $clientId, string $clientSecret): string
    {
        $shop  = self::normaliseShop($shop);
        $state = bin2hex(random_bytes(24));

        Db::core()->prepare(
            'INSERT INTO oauth_state (state, shop_domain, client_id, client_secret_enc, created_at, expires_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? SECOND)'
        )->execute([
            $state,
            $shop,
            $clientId,
            Crypto::encrypt($clientSecret),
            self::STATE_TTL,
        ]);

        self::sweepExpiredStates();

        return 'https://' . $shop . '/admin/oauth/authorize?' . http_build_query([
            'client_id'    => $clientId,
            'scope'        => implode(',', self::SCOPES),
            'redirect_uri' => Config::require('hosts.oauth_redirect'),
            'state'        => $state,
        ]);
    }

    /**
     * Handle the callback and return the access token.
     *
     * Four checks before anything is trusted, in this order:
     *   1. the state exists, is unexpired and unused  (CSRF, replay)
     *   2. the shop matches the one we started with   (substitution)
     *   3. the HMAC verifies against the app secret   (authenticity)
     *   4. only then is the code exchanged
     *
     * @param array<string,mixed> $query  raw $_GET
     * @return array{shop:string,token:string,scopes:string,client_id:string}
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
            'SELECT shop_domain, client_id, client_secret_enc, consumed_at
               FROM oauth_state
              WHERE state = ? AND expires_at > UTC_TIMESTAMP()'
        );
        $stmt->execute([$state]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException(
                'This install link has expired or was already used. Start the connection again.'
            );
        }
        if ($row['consumed_at'] !== null) {
            throw new RuntimeException('This install link has already been used.');
        }

        $expectedShop = (string) $row['shop_domain'];
        if (self::normaliseShop($shop) !== $expectedShop) {
            throw new RuntimeException(
                "Callback is for {$shop} but the install was started for {$expectedShop}."
            );
        }

        $clientSecret = Crypto::decrypt((string) $row['client_secret_enc']);

        if (!self::verifyHmac($rawQueryString, $clientSecret)) {
            throw new RuntimeException(
                'HMAC verification failed — this callback did not come from Shopify.'
            );
        }

        // Single-use: mark consumed before the exchange, so a replayed callback
        // cannot mint a second token even if the first attempt fails.
        Db::core()->prepare('UPDATE oauth_state SET consumed_at = UTC_TIMESTAMP() WHERE state = ?')
            ->execute([$state]);

        $result = self::exchangeCode($expectedShop, (string) $row['client_id'], $clientSecret, $code);

        return [
            'shop'      => $expectedShop,
            'token'     => $result['access_token'],
            'scopes'    => $result['scope'] ?? '',
            'client_id' => (string) $row['client_id'],
        ];
    }

    /**
     * Verify the callback signature.
     *
     * Built from the RAW query string rather than by re-encoding $_GET.
     * Shopify signs the bytes it sent; re-encoding a parsed array can differ
     * in space and reserved-character handling, which produces an HMAC that
     * fails for no visible reason.
     */
    public static function verifyHmac(string $rawQueryString, string $clientSecret): bool
    {
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
        $computed = hash_hmac('sha256', implode('&', $parts), $clientSecret);

        return hash_equals($computed, $received);
    }

    /** @return array{access_token:string,scope?:string} */
    private static function exchangeCode(string $shop, string $clientId, string $clientSecret, string $code): array
    {
        $ch = curl_init("https://{$shop}/admin/oauth/access_token");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'code'          => $code,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $http !== 200) {
            throw new RuntimeException(
                "Token exchange failed (HTTP {$http})" . ($err !== '' ? ": {$err}" : '')
                // Shopify's body can echo the code back; it is single-use and
                // now spent, but there is no reason to print it.
            );
        }

        $data = json_decode((string) $body, true);

        if (!is_array($data) || !isset($data['access_token'])) {
            throw new RuntimeException('Shopify returned no access token.');
        }

        return $data;
    }

    /**
     * Which requested scopes were actually granted?
     *
     * read_all_orders is the one that matters. It is granted only if the app
     * has been approved for it in the Partner Dashboard, and its absence is
     * silent: the API simply returns 60 days of orders and every cohort chart
     * comes out empty, months after anyone would connect the two facts.
     *
     * @return array{granted:string[],missing:string[],history_limited:bool}
     */
    public static function verifyScopes(string $grantedScopes): array
    {
        $granted = array_filter(array_map('trim', explode(',', $grantedScopes)));
        $missing = array_values(array_diff(self::SCOPES, $granted));

        return [
            'granted'         => array_values($granted),
            'missing'         => $missing,
            'history_limited' => in_array('read_all_orders', $missing, true),
        ];
    }

    private static function sweepExpiredStates(): void
    {
        Db::core()->exec(
            'DELETE FROM oauth_state WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY'
        );
    }
}
