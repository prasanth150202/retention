<?php
/**
 * Display formatting.
 *
 * Small, but it lives here rather than in the views because one rule has to
 * hold across every panel: NULL IS NOT ZERO. A conversion rate with no
 * visitors, an average order value with no orders, a change against a period
 * that had nothing — none of those are zero, they are unknown, and printing
 * "0%" invites a merchant to hunt for a problem that does not exist.
 *
 * Every formatter takes null and renders an em dash.
 */

declare(strict_types=1);

final class Fmt
{
    public const NONE = '—';

    /** Symbols for the currencies this is likely to see; falls back to the code. */
    private const SYMBOLS = [
        'INR' => '₹',
        'USD' => '$',
        'GBP' => '£',
        'EUR' => '€',
        'AED' => 'AED ',
        'AUD' => 'A$',
        'CAD' => 'C$',
    ];

    /** Money stored in minor units (paise, cents). */
    public static function money(?int $minor, string $currency = 'INR', bool $round = true): string
    {
        if ($minor === null) {
            return self::NONE;
        }

        $symbol = self::SYMBOLS[strtoupper($currency)] ?? (strtoupper($currency) . ' ');
        $major  = $minor / 100;

        // Whole rupees on a dashboard: the paise are noise at this scale, and
        // two extra digits on every row makes a table harder to scan.
        return $symbol . number_format($major, $round && $major >= 100 ? 0 : 2);
    }

    /** Money as a compact figure for a headline tile: ₹1.2L, ₹3.4Cr. */
    public static function moneyShort(?int $minor, string $currency = 'INR'): string
    {
        if ($minor === null) {
            return self::NONE;
        }

        $symbol = self::SYMBOLS[strtoupper($currency)] ?? (strtoupper($currency) . ' ');
        $major  = $minor / 100;

        // Indian numbering, because the first stores using this are Indian and
        // a merchant reading "₹12,00,000" as "1.2M" has to stop and convert.
        if (strtoupper($currency) === 'INR') {
            if ($major >= 10000000) {
                return $symbol . self::trim($major / 10000000) . 'Cr';
            }
            if ($major >= 100000) {
                return $symbol . self::trim($major / 100000) . 'L';
            }
            if ($major >= 1000) {
                return $symbol . number_format($major, 0);
            }

            return $symbol . number_format($major, 0);
        }

        if ($major >= 1000000) {
            return $symbol . self::trim($major / 1000000) . 'M';
        }
        if ($major >= 1000) {
            return $symbol . self::trim($major / 1000) . 'K';
        }

        return $symbol . number_format($major, 0);
    }

    public static function num(?int $n): string
    {
        return $n === null ? self::NONE : number_format($n);
    }

    public static function pct(?float $p, int $decimals = 1): string
    {
        return $p === null ? self::NONE : number_format($p, $decimals) . '%';
    }

    /**
     * A change against the previous period, signed.
     *
     * Returns the text and which direction it points, so the view can colour
     * it — but not whether that direction is good. A rise in refunds is not
     * good news, and the formatter has no way to know which metric it is
     * looking at.
     *
     * @return array{text:string,dir:string}
     */
    public static function delta(?float $pct): array
    {
        if ($pct === null) {
            return ['text' => '', 'dir' => 'flat'];
        }

        if (abs($pct) < 0.05) {
            return ['text' => 'no change', 'dir' => 'flat'];
        }

        return [
            'text' => ($pct > 0 ? '+' : '') . number_format($pct, 1) . '%',
            'dir'  => $pct > 0 ? 'up' : 'down',
        ];
    }

    /** A date as a merchant would write it. */
    public static function date(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return self::NONE;
        }

        try {
            return (new DateTimeImmutable($iso))->format('j M Y');
        } catch (Throwable) {
            return $iso;
        }
    }

    public static function month(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return self::NONE;
        }

        try {
            return (new DateTimeImmutable($iso))->format('M Y');
        } catch (Throwable) {
            return $iso;
        }
    }

    /** Shorten a URL path for a table cell without losing which page it is. */
    public static function path(?string $path, int $max = 48): string
    {
        if ($path === null || $path === '') {
            return '/';
        }

        if (strlen($path) <= $max) {
            return $path;
        }

        // Keep the end: the identifying part of a Shopify URL is the handle,
        // and truncating from the right hides exactly that.
        return '…' . substr($path, -($max - 1));
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private static function trim(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1), '0'), '.');
    }
}
