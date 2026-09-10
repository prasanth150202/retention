<?php
/**
 * Storage-safe text handling.
 *
 * Separate from Fmt, which is about display. This is about not corrupting a
 * string on its way into a column.
 *
 * WHY THIS EXISTS
 * Every value bound for a VARCHAR used to be cut with substr(), which counts
 * BYTES. The columns count CHARACTERS — VARCHAR(191) holds 191 characters,
 * which in utf8mb4 can be up to 764 bytes. So the byte cut was both far more
 * aggressive than needed and capable of slicing a multibyte character in half.
 *
 * A half character used to be stored as mojibake. Since the application began
 * setting strict SQL mode, MySQL rejects it outright:
 *
 *     SQLSTATE[22007] 1366 Incorrect string value: '\xE0\xA4...'
 *
 * which turns a cosmetic blemish into a failed write. For a Devanagari product
 * title, an emoji in a campaign name, or an accented city, that is a routine
 * input, not an exotic one.
 */

declare(strict_types=1);

final class Text
{
    /**
     * Cut a string to fit a column, counting characters and never splitting one.
     *
     * @param int $chars the column's declared length, in characters
     */
    public static function fit(?string $value, int $chars): ?string
    {
        if ($value === null) {
            return null;
        }

        // Anything already invalid — a mangled URL parameter, a truncated
        // header — is repaired here rather than being handed to the database
        // to reject. Substitution loses a character; rejection loses the row.
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return mb_strlen($value, 'UTF-8') <= $chars
            ? $value
            : mb_substr($value, 0, $chars, 'UTF-8');
    }

    /**
     * The same, but an empty result becomes null.
     *
     * Callers writing optional columns want NULL rather than '', so that
     * "absent" and "present but blank" stay distinguishable.
     */
    public static function fitOrNull(?string $value, int $chars): ?string
    {
        $out = self::fit($value, $chars);

        return ($out === null || $out === '') ? null : $out;
    }

    /**
     * Is mbstring available?
     *
     * Everything above depends on it. Shared hosting normally has it, but
     * "normally" is not a guarantee, and discovering its absence through a
     * fatal error on the products tab is not the way to find out.
     */
    public static function mbstringAvailable(): bool
    {
        return function_exists('mb_substr')
            && function_exists('mb_strlen')
            && function_exists('mb_check_encoding');
    }
}
