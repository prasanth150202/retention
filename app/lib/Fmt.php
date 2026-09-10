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
        $decimals = $round && $major >= 100 ? 0 : 2;

        return $symbol . self::group($major, $decimals, strtoupper($currency));
    }

    /**
     * Digit grouping, in the convention of the currency.
     *
     * INR groups the last three digits and then in twos — 1,23,456 rather than
     * 123,456. moneyShort() already speaks in lakh and crore for exactly this
     * reason, and a table of ₹123,456 beside a tile reading ₹1.2L reads as two
     * different systems. Indian merchants write it the Indian way; matching
     * that is the difference between a figure being read and being converted.
     */
    private static function group(float $value, int $decimals, string $currency): string
    {
        if ($currency !== 'INR') {
            return number_format($value, $decimals);
        }

        $sign  = $value < 0 ? '-' : '';
        $fixed = number_format(abs($value), $decimals, '.', '');

        [$whole, $fraction] = array_pad(explode('.', $fixed, 2), 2, null);

        if (strlen($whole) > 3) {
            $last  = substr($whole, -3);
            $rest  = substr($whole, 0, -3);
            // Every two digits from the right, in the leading part only.
            $rest  = (string) preg_replace('/\B(?=(?:\d{2})+$)/', ',', $rest);
            $whole = $rest . ',' . $last;
        }

        return $sign . $whole . ($fraction !== null ? '.' . $fraction : '');
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
        //
        // Each threshold also asks whether the SMALLER unit would round up to a
        // full one of the larger. Without that, ₹99,99,999.99 prints as "₹100L"
        // — and a hundred lakh is a crore. The same trap sits between K and M.
        if (strtoupper($currency) === 'INR') {
            if ($major >= 10000000 || self::roundsTo($major, 100000, 100)) {
                return $symbol . self::trim($major / 10000000) . 'Cr';
            }
            if ($major >= 100000 || self::roundsTo($major, 1000, 100)) {
                return $symbol . self::trim($major / 100000) . 'L';
            }

            return $symbol . number_format($major, 0);
        }

        if ($major >= 1000000000 || self::roundsTo($major, 1000000, 1000)) {
            return $symbol . self::trim($major / 1000000000) . 'B';
        }
        if ($major >= 1000000 || self::roundsTo($major, 1000, 1000)) {
            return $symbol . self::trim($major / 1000000) . 'M';
        }
        if ($major >= 1000) {
            return $symbol . self::trim($major / 1000) . 'K';
        }

        return $symbol . number_format($major, 0);
    }

    /**
     * Would this value, shown in `$unit`s to one decimal, round up to `$limit`
     * of them — and so belong in the next unit up instead?
     */
    private static function roundsTo(float $value, float $unit, float $limit): bool
    {
        return $value >= $unit && round($value / $unit, 1) >= $limit;
    }

    /**
     * Width of a bar, as a percentage.
     *
     * A floor keeps a small-but-real value visible instead of collapsing it to
     * an invisible hairline. Zero gets no floor and no bar: a stage nobody
     * reached must look like a stage nobody reached, not like a trace of
     * activity. Same rule as rendering null as a dash rather than 0%.
     */
    public static function barWidth(int|float|null $value, int|float|null $max, float $floor = 0.5): float
    {
        if ($value === null || $max === null || $value <= 0 || $max <= 0) {
            return 0.0;
        }

        return max($floor, $value / $max * 100);
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

    /**
     * Shorten a label for a table cell.
     *
     * Truncates from the right and marks it, so a long value takes one line
     * instead of eight. The full text goes in a title attribute at the call
     * site — nothing is hidden, only folded.
     */
    public static function clip(?string $text, int $max = 28): string
    {
        $text = trim((string) $text);

        if ($text === '' || mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }

    /** Shorten a URL path for a table cell without losing which page it is. */
    public static function path(?string $path, int $max = 48): string
    {
        if ($path === null || $path === '') {
            return '/';
        }

        if (mb_strlen($path) <= $max) {
            return $path;
        }

        // Keep the end: the identifying part of a Shopify URL is the handle,
        // and truncating from the right hides exactly that.
        //
        // Counted in characters, not bytes. A byte slice can land inside a
        // multibyte character, and htmlspecialchars() returns an EMPTY STRING
        // for invalid UTF-8 — so a path with an accent or a Devanagari handle
        // rendered as a blank cell rather than a shortened one.
        return '…' . mb_substr($path, -($max - 1));
    }

    public static function e(?string $s): string
    {
        // ENT_SUBSTITUTE matters more than it looks. Without it
        // htmlspecialchars() returns an EMPTY STRING for invalid UTF-8, so one
        // bad byte anywhere in a value blanks the whole cell — losing the
        // figure entirely rather than showing one odd character.
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function trim(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1), '0'), '.');
    }
}
