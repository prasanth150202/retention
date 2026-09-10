<?php
/**
 * Merchant sessions for a non-embedded app.
 *
 * When a merchant opens a non-embedded app from the Shopify admin, Shopify
 * navigates to the app URL with `shop`, `hmac` and `timestamp` — signed with
 * the app secret, the same scheme as the OAuth callback. Verifying that
 * signature is proof the request came from Shopify on behalf of a merchant who
 * is signed in to that store, so it is enough to establish a session.
 *
 * That is why non-embedded costs almost nothing here: there is no App Bridge,
 * no session token, no token exchange. The signature already in place for
 * OAuth does the work.
 *
 * Sessions are kept separate from staff sessions (Auth). A merchant sees one
 * store; Digifyce staff see all of them. Conflating the two is how a merchant
 * ends up looking at someone else's revenue.
 */

declare(strict_types=1);

final class Merchant
{
    private const SESSION_NAME = 'odys_merchant';
    private const IDLE_TIMEOUT = 43200;   // 12 hours

    /** Reject a signed request older than this — a replayed link should die. */
    private const MAX_SIGNATURE_AGE = 300;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => !self::isPlainHttp(),
            // Lax, not Strict: the merchant arrives by navigation from
            // admin.shopify.com, and Strict would drop the cookie on that
            // first cross-site hop and log them straight back out.
            'samesite' => 'Lax',
        ]);

        session_start();

        if (isset($_SESSION['m_last_seen'])
            && time() - (int) $_SESSION['m_last_seen'] > self::IDLE_TIMEOUT) {
            self::logout();
            session_start();
        }

        $_SESSION['m_last_seen'] = time();
    }

    /**
     * Establish a session from a Shopify-signed request, if one is present.
     *
     * Returns the tenant id when the request authenticated a merchant, or null
     * when there was no signed request to act on — which is not an error, it
     * just means "carry on with whatever session already exists".
     *
     * @param array<string,mixed> $query  raw $_GET
     */
    public static function fromSignedRequest(array $query, string $rawQueryString): ?int
    {
        $shop = (string) ($query['shop'] ?? '');
        $hmac = (string) ($query['hmac'] ?? '');

        if ($shop === '' || $hmac === '') {
            return null;
        }
        if (!ShopifyOAuth::configured()) {
            return null;
        }

        if (!ShopifyOAuth::verifyHmac($rawQueryString)) {
            return null;   // unsigned or forged; do not create a session
        }

        // A signed URL is a bearer credential for as long as it verifies.
        // Bounding its age means one leaked out of a browser history or a
        // support ticket stops working quickly.
        $ts = (int) ($query['timestamp'] ?? 0);
        if ($ts > 0 && abs(time() - $ts) > self::MAX_SIGNATURE_AGE) {
            return null;
        }

        try {
            $normalised = ShopifyOAuth::normaliseShop($shop);
        } catch (Throwable) {
            return null;
        }

        $tenant = Tenant::findByShop($normalised);

        if (!$tenant) {
            return null;   // signed, but we have never seen this store
        }

        self::startSession((int) $tenant['tenant_id'], $normalised);

        return (int) $tenant['tenant_id'];
    }

    public static function startSession(int $tenantId, string $shopDomain): void
    {
        self::start();
        session_regenerate_id(true);

        $_SESSION['m_tenant_id']  = $tenantId;
        $_SESSION['m_shop']       = $shopDomain;
        $_SESSION['m_last_seen']  = time();
    }

    public static function check(): bool
    {
        self::start();
        return isset($_SESSION['m_tenant_id']);
    }

    public static function tenantId(): ?int
    {
        return self::check() ? (int) $_SESSION['m_tenant_id'] : null;
    }

    public static function shop(): ?string
    {
        return self::check() ? (string) $_SESSION['m_shop'] : null;
    }

    /** @return array<string,mixed>|null */
    public static function tenant(): ?array
    {
        $id = self::tenantId();
        return $id === null ? null : Tenant::find($id);
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }

        session_destroy();
    }

    /**
     * Where to send a merchant who arrived without a session.
     *
     * If we know the shop, start an install: under managed installation the
     * scopes are already granted, so an already-installed store passes
     * straight through and comes back with a token rather than seeing a
     * consent screen again.
     */
    public static function loginRedirect(?string $shop): string
    {
        if ($shop === null || $shop === '') {
            return '/?p=no-shop';
        }

        try {
            return ShopifyOAuth::authorizeUrl($shop);
        } catch (Throwable) {
            return '/?p=no-shop';
        }
    }

    private static function isPlainHttp(): bool
    {
        return PHP_SAPI === 'cli'
            || ($_SERVER['HTTP_HOST'] ?? '') === ''
            || str_starts_with((string) ($_SERVER['HTTP_HOST'] ?? ''), '127.0.0.1')
            || str_starts_with((string) ($_SERVER['HTTP_HOST'] ?? ''), 'localhost');
    }
}
