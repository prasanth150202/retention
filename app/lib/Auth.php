<?php
/**
 * Staff authentication.
 *
 * Session-based, because this is a small internal tool: a handful of Digifyce
 * staff, no client logins yet. Token auth and roles would be scaffolding for
 * users who do not exist.
 *
 * Client logins are a planned addition (TECHNICAL_PLAN.md section 19), which
 * is why every query is tenant-scoped already — adding accounts later is
 * additive rather than a rewrite.
 */

declare(strict_types=1);

final class Auth
{
    private const SESSION_NAME = 'odysseus';
    private const IDLE_TIMEOUT = 43200;   // 12 hours

    private static ?array $user = null;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(self::SESSION_NAME);

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,                      // not readable from JS
            'secure'   => !self::isPlainHttp(),      // HTTPS only in production
            'samesite' => 'Lax',                     // survives normal navigation,
                                                     // blocks cross-site POSTs
        ]);

        session_start();

        // Idle expiry. Shared machines are common and a session that never
        // ends is a session someone else eventually uses.
        if (isset($_SESSION['last_seen']) && time() - (int) $_SESSION['last_seen'] > self::IDLE_TIMEOUT) {
            self::logout();
            session_start();
        }

        $_SESSION['last_seen'] = time();
    }

    /**
     * Verify credentials and open a session.
     *
     * The failure message is deliberately identical whether the address is
     * unknown or the password is wrong — distinguishing them tells an attacker
     * which addresses are real.
     */
    public static function login(string $email, string $password): bool
    {
        self::start();

        $stmt = Db::core()->prepare(
            'SELECT user_id, email, password_hash, display_name, is_active
               FROM staff_users WHERE email = ?'
        );
        $stmt->execute([strtolower(trim($email))]);
        $row = $stmt->fetch();

        // Hash a dummy value when the user is absent, so a missing account
        // does not return measurably faster than a wrong password.
        $hash = $row['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';

        if (!password_verify($password, $hash) || !$row || (int) $row['is_active'] !== 1) {
            return false;
        }

        // New session id on privilege change, so a fixated id is useless.
        session_regenerate_id(true);

        $_SESSION['user_id']   = (int) $row['user_id'];
        $_SESSION['email']     = $row['email'];
        $_SESSION['name']      = $row['display_name'];
        $_SESSION['last_seen'] = time();

        Db::core()->prepare('UPDATE staff_users SET last_login_at = UTC_TIMESTAMP() WHERE user_id = ?')
            ->execute([(int) $row['user_id']]);

        return true;
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
        self::$user = null;
    }

    public static function check(): bool
    {
        self::start();
        return isset($_SESSION['user_id']);
    }

    /** @return array{id:int,email:string,name:string}|null */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }

        return self::$user ??= [
            'id'    => (int) $_SESSION['user_id'],
            'email' => (string) $_SESSION['email'],
            'name'  => (string) $_SESSION['name'],
        ];
    }

    /** Redirect to the login page unless signed in. */
    public static function require(): void
    {
        if (!self::check()) {
            header('Location: /?p=login');
            exit;
        }
    }

    // -----------------------------------------------------------------
    // CSRF
    // -----------------------------------------------------------------

    public static function csrfToken(): string
    {
        self::start();
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::csrfToken()) . '">';
    }

    /**
     * Verify the token on any state-changing request.
     *
     * SameSite=Lax already blocks most cross-site POSTs, but it is a browser
     * behaviour rather than a guarantee, so the token is checked as well.
     */
    public static function csrfCheck(): void
    {
        self::start();

        $sent = (string) ($_POST['_csrf'] ?? '');

        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
            http_response_code(419);
            exit('Session expired. Go back, reload the page and try again.');
        }
    }

    // -----------------------------------------------------------------
    // User management
    // -----------------------------------------------------------------

    public static function createUser(string $email, string $password, string $name): int
    {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('That is not a valid email address.');
        }
        if (strlen($password) < 12) {
            throw new InvalidArgumentException(
                'Use at least 12 characters. This account can read every connected '
                . "store's revenue and customer data."
            );
        }

        $pdo = Db::core();
        $pdo->prepare(
            'INSERT INTO staff_users (email, password_hash, display_name, is_active, created_at)
             VALUES (?, ?, ?, 1, UTC_TIMESTAMP())'
        )->execute([
            $email,
            password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            $name !== '' ? $name : $email,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function userCount(): int
    {
        return (int) Db::core()->query('SELECT COUNT(*) FROM staff_users')->fetchColumn();
    }

    private static function isPlainHttp(): bool
    {
        // CLI and local development over http; never true behind Hostinger,
        // which terminates TLS in front of PHP.
        return PHP_SAPI === 'cli'
            || ($_SERVER['HTTP_HOST'] ?? '') === ''
            || str_starts_with((string) ($_SERVER['HTTP_HOST'] ?? ''), '127.0.0.1')
            || str_starts_with((string) ($_SERVER['HTTP_HOST'] ?? ''), 'localhost');
    }
}
