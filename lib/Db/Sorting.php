<?php

declare(strict_types=1);

namespace OCA\Opds\Db;

/**
 * Helpers producing database-friendly sort keys.
 */
final class Sorting {
    /** Leading articles that are ignored when sorting titles (en, de, fr, es, it, nl, pt). */
    private const ARTICLES = [
        'the', 'a', 'an',
        'der', 'die', 'das', 'ein', 'eine',
        'le', 'la', 'les', 'l', 'un', 'une',
        'el', 'los', 'las', 'una',
        'il', 'lo', 'gli', 'uno',
        'de', 'het',
        'o', 'os', 'as', 'um', 'uma',
    ];

    /** Lower-case, accent-folded key with leading articles and punctuation removed. */
    public static function sortKey(string $value): string {
        $key = self::fold($value);
        $key = preg_replace('/^[^\p{L}\p{N}]+/u', '', $key) ?? $key;
        // "L'étranger" -> "etranger"
        if (preg_match('/^([a-z]+)[\'\x{2019}\s]+(.+)$/u', $key, $m) && in_array($m[1], self::ARTICLES, true)) {
            $key = $m[2];
        }
        return trim($key);
    }

    /** Sort key for a title keeping the natural order of numbers ("Vol. 2" before "Vol. 10"). */
    public static function titleSortKey(string $title): string {
        $key = self::sortKey($title);
        return preg_replace_callback('/\d+/', static fn (array $m): string => str_pad($m[0], 8, '0', STR_PAD_LEFT), $key) ?? $key;
    }

    /** "Jane Austen" -> "austen, jane"; "Austen, Jane" stays. */
    public static function personSortKey(string $name): string {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if (str_contains($name, ',')) {
            return self::fold($name);
        }
        $parts = preg_split('/\s+/', $name) ?: [$name];
        if (count($parts) === 1) {
            return self::fold($name);
        }
        $suffixes = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'phd', 'md'];
        $last = array_pop($parts);
        if (in_array(strtolower($last), $suffixes, true) && count($parts) > 1) {
            $suffix = $last;
            $last = array_pop($parts) . ' ' . $suffix;
        }
        // Keep particles with the last name: "Ludwig van Beethoven" -> "van beethoven, ludwig"
        $particles = ['van', 'von', 'de', 'del', 'della', 'di', 'da', 'du', 'des', 'le', 'la', 'den', 'der', 'ter', 'ten', 'af', 'av', 'zu', 'y', 'e'];
        while (count($parts) > 1 && in_array(strtolower(end($parts)), $particles, true)) {
            $last = array_pop($parts) . ' ' . $last;
        }
        return self::fold($last . ', ' . implode(' ', $parts));
    }

    /** "Austen, Jane" -> "Jane Austen" (display form from a file-as value). */
    public static function displayName(string $fileAs): string {
        if (preg_match('/^\s*([^,]+),\s*(.+?)\s*$/', $fileAs, $m)) {
            return $m[2] . ' ' . $m[1];
        }
        return trim($fileAs);
    }

    /** Uppercase A-Z letter group of a sort key, '#' for anything else. */
    public static function letterOf(string $key): string {
        $first = strtoupper(mb_substr(trim($key), 0, 1));
        return ($first !== '' && $first >= 'A' && $first <= 'Z' && strlen($first) === 1) ? $first : '#';
    }

    /** Lower-case with accents stripped where the platform can do it. */
    public static function fold(string $value): string {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        if (class_exists(\Transliterator::class)) {
            static $tr = null;
            $tr ??= \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($tr !== null) {
                $out = $tr->transliterate($value);
                if ($out !== false) {
                    return $out;
                }
            }
        }
        if (class_exists(\Normalizer::class)) {
            $out = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if ($out !== false) {
                return preg_replace('/\p{Mn}+/u', '', $out) ?? $value;
            }
        }
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return ($ascii !== false && $ascii !== '') ? strtolower($ascii) : $value;
    }
}
