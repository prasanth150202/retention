<?php
/**
 * Configuration accessor.
 *
 * Wraps the array returned by config/config.php with dot-path lookup, so
 * callers write Config::get('db.host') instead of threading an array through
 * every constructor.
 *
 * config/config.php is tracked in git and holds no secrets; everything
 * sensitive comes from .env via Env.
 */

declare(strict_types=1);

final class Config
{
    private static ?array $data = null;

    /**
     * @param string|null $envFile Override the environment file. Used by CLI
     *                             tools with --env so one checkout can target
     *                             local and production without swapping files.
     */
    public static function init(?string $envFile = null): void
    {
        if (self::$data !== null) {
            return;
        }

        $root = dirname(__DIR__, 2);

        // Env::load() is first-wins, so loading an override here makes
        // config.php's own load() call a no-op.
        Env::load($envFile ?? $root . '/.env');

        self::$data = require $root . '/config/config.php';
    }

    /** @return mixed */
    public static function get(string $path, mixed $default = null): mixed
    {
        if (self::$data === null) {
            self::init();
        }

        $value = self::$data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** Like get(), but throws instead of returning a default. */
    public static function require(string $path): mixed
    {
        $sentinel = "\0__missing__\0";
        $value    = self::get($path, $sentinel);

        if ($value === $sentinel) {
            throw new RuntimeException("Missing required configuration value: {$path}");
        }

        return $value;
    }

    public static function all(): array
    {
        if (self::$data === null) {
            self::init();
        }
        return self::$data;
    }

    /** Test seam. Not for application code. */
    public static function reset(): void
    {
        self::$data = null;
    }
}
