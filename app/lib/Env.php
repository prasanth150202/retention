<?php
/**
 * Minimal .env reader.
 *
 * Deliberately dependency-free: this project has no composer install and no
 * build step on the server (see TECHNICAL_PLAN.md §11.1), so pulling in
 * vlucas/phpdotenv for ~40 lines of parsing is not worth the deployment
 * complexity.
 *
 * Values are read into a private array, NOT into $_ENV or getenv(). Keeping
 * credentials out of the superglobals means a stray var_dump() or a phpinfo()
 * page cannot leak the database password.
 */

declare(strict_types=1);

final class Env
{
    private static array $vars   = [];
    private static bool  $loaded = false;

    public static function load(string $file): void
    {
        if (self::$loaded) {
            return;
        }

        if (!is_file($file)) {
            throw new RuntimeException(
                "Missing environment file: {$file}\n"
                . "Copy .env.example to .env and fill in the database credentials."
            );
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException("Cannot read environment file: {$file}");
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));

            // Strip one layer of matching quotes, if present.
            $len = strlen($val);
            if ($len >= 2
                && (($val[0] === '"' && $val[$len - 1] === '"')
                 || ($val[0] === "'" && $val[$len - 1] === "'"))
            ) {
                $val = substr($val, 1, -1);
            }

            self::$vars[$key] = $val;
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $val = self::$vars[$key] ?? null;
        return ($val === null || $val === '') ? $default : $val;
    }

    /** Fails loudly rather than silently running against the wrong database. */
    public static function require(string $key): string
    {
        $val = self::get($key);
        if ($val === null) {
            throw new RuntimeException("Required environment value '{$key}' is not set in .env");
        }
        return $val;
    }

    /**
     * All keys matching a prefix, with the prefix stripped.
     *
     * Used to discover per-shard credential blocks (DB_SHARD_2026_USER,
     * DB_SHARD_2027_USER, …) without config.php having to know in advance
     * which years exist. Hostinger issues one credential per database, so
     * each yearly shard may carry its own user and password.
     */
    public static function allWithPrefix(string $prefix): array
    {
        $out = [];
        foreach (self::$vars as $key => $val) {
            if (str_starts_with($key, $prefix)) {
                $out[substr($key, strlen($prefix))] = $val;
            }
        }
        return $out;
    }

    public static function int(string $key, int $default = 0): int
    {
        $val = self::get($key);
        return $val === null ? $default : (int) $val;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $val = self::get($key);
        if ($val === null) {
            return $default;
        }
        return in_array(strtolower($val), ['1', 'true', 'yes', 'on'], true);
    }
}
