<?php

declare(strict_types=1);

namespace OCA\Opds\Calibre;

use OCA\Opds\Db\Sorting;

/**
 * Calibre's rules for sort names and for the names of folders and files in a
 * library, so that Calibre finds everything where it expects it.
 */
final class Naming {
    private const PATH_LIMIT = 100;
    private const RESERVED = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];

    private const ARTICLES = [
        'eng' => ['A\s+', 'The\s+', 'An\s+'],
        'epo' => ['La\s+', "L'", 'L´'],
        'spa' => ['El\s+', 'La\s+', 'Lo\s+', 'Los\s+', 'Las\s+', 'Un\s+', 'Una\s+', 'Unos\s+', 'Unas\s+'],
        'fra' => ['Le\s+', 'La\s+', "L'", 'L´', 'L’', 'Les\s+', 'Un\s+', 'Une\s+', 'Des\s+', 'De\s+(?:La\s+)?', "D'", 'D´', 'D’'],
        'pol' => [],
        'ita' => ['Lo\s+', 'Il\s+', "L'", 'L´', 'La\s+', 'Gli\s+', 'I\s+', 'Le\s+', 'Uno\s+', 'Un\s+', 'Una\s+', "Un'", 'Un´', 'Dei\s+', 'Degli\s+', 'Delle\s+', 'Del\s+', 'Della\s+', 'Dello\s+', "Dell'", 'Dell´'],
        'por' => ['A\s+', 'O\s+', 'Os\s+', 'As\s+', 'Um\s+', 'Uns\s+', 'Uma\s+', 'Umas\s+'],
        'ron' => ['Un\s+', 'O\s+', 'Nişte\s+'],
        'deu' => ['Der\s+', 'Die\s+', 'Das\s+', 'Den\s+', 'Ein\s+', 'Eine\s+', 'Einen\s+', 'Dem\s+', 'Des\s+', 'Einem\s+', 'Eines\s+'],
        'nld' => ['De\s+', 'Het\s+', 'Een\s+', "'n\s+", "'s\s+", 'Ene\s+', 'Ener\s+', 'Enes\s+', 'Den\s+', 'Der\s+', 'Des\s+', "'t\s+"],
        'swe' => ['En\s+', 'Ett\s+', 'Det\s+', 'Den\s+', 'De\s+'],
        'tur' => ['Bir\s+'],
        'afr' => ["'n\s+", 'Die\s+'],
        'ell' => ['O\s+', 'I\s+', 'To\s+', 'Ta\s+', 'Tus\s+', 'Tis\s+', "'Enas\s+", "'Mia\s+", "'Ena\s+", "'Enan\s+"],
        'hun' => ['A\s+', 'Az\s+', 'Egy\s+'],
    ];

    private const COPY_WORDS = ['agency', 'corporation', 'company', 'co.', 'council', 'committee', 'inc.', 'institute', 'national',
        'society', 'club', 'team', 'software', 'games', 'entertainment', 'media', 'studios'];
    private const PREFIXES = ['mr', 'mrs', 'ms', 'dr', 'prof'];
    private const SUFFIXES = ['jr', 'sr', 'inc', 'ph.d', 'phd', 'md', 'm.d', 'i', 'ii', 'iii', 'iv', 'junior', 'senior'];

    /** "The Colour of Magic" -> "Colour of Magic, The" */
    public static function titleSort(string $title, string $lang = 'eng'): string {
        $title = trim($title);
        $articles = self::ARTICLES[$lang] ?? self::ARTICLES['eng'];
        $bare = preg_replace('/^["\'“‘«](.+?)["\'”’»]?$/u', '$1', $title) ?? $title;
        if ($articles !== [] && preg_match('/^(' . implode('|', $articles) . ')(.+)$/iu', $bare, $m)) {
            return trim($m[2] . ', ' . trim($m[1]));
        }
        return trim($bare);
    }

    /** "Terry Pratchett" -> "Pratchett, Terry" */
    public static function authorSort(string $author): string {
        $plain = trim(preg_replace('/[(\[{][^)\]}]*[)\]}]/u', '', $author) ?? $author);
        if (str_contains($plain, ',')) {
            return $author;
        }
        $tokens = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lower = array_map('mb_strtolower', $tokens);
        if (count($tokens) < 2 || array_intersect($lower, self::COPY_WORDS) !== []) {
            return $author;
        }
        $in = static fn (string $t, array $list): bool => in_array($t, $list, true) || in_array(rtrim($t, '.'), $list, true);
        $first = 0;
        while ($first < count($tokens) && $in($lower[$first], self::PREFIXES)) {
            $first++;
        }
        $last = count($tokens) - 1;
        while ($last >= $first && $in($lower[$last], self::SUFFIXES)) {
            $last--;
        }
        if ($first >= count($tokens) || $last < $first) {
            return $author;
        }
        $parts = array_merge([$tokens[$last]], array_slice($tokens, $first, $last - $first));
        if (count($parts) > 1) {
            $parts[0] .= ',';
        }
        return implode(' ', array_merge($parts, array_slice($tokens, $last + 1)));
    }

    /** Folder of a book relative to the library, "Author/Title (12)". */
    public static function bookPath(int $id, string $title, string $author): string {
        $suffix = ' (' . $id . ')';
        $limit = self::PATH_LIMIT - intdiv(strlen($suffix), 2) - 2;
        $author = rtrim(substr(self::asciiName($author), 0, $limit), ' .');
        $title = rtrim(substr(self::asciiName(ltrim($title)), 0, $limit));
        if ($author === '') {
            $author = 'Unknown';
        }
        if (in_array(strtoupper($author), self::RESERVED, true)) {
            $author .= 'w';
        }
        return $author . '/' . ($title === '' ? 'Unknown' : $title) . $suffix;
    }

    /** File name without extension, "Title - Author". */
    public static function fileName(string $title, string $author, int $longestExtension): string {
        $limit = intdiv(self::PATH_LIMIT - max($longestExtension, 14) - 2, 2);
        $author = substr(self::asciiName($author), 0, $limit);
        $title = rtrim(substr(self::asciiName(ltrim($title)), 0, $limit));
        // Leading/trailing blanks are refused by Windows clients and by Nextcloud
        $name = trim(rtrim(($title === '' ? 'Unknown' : $title) . ' - ' . $author, '.'));
        return $name === '' ? 'Unknown' : $name;
    }

    public static function uuid4(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** ASCII name that is valid on every platform (always a single path component). */
    private static function asciiName(string $name): string {
        $ascii = self::ascii($name);
        $ascii = str_replace(['\\', '|', '?', '*', '<', '"', ':', '>', '+', '/'], '_', $ascii);
        $ascii = preg_replace('/[\x00-\x1F\x7F]/', '_', $ascii) ?? $ascii;
        $ascii = trim(preg_replace('/\s/', ' ', $ascii) ?? $ascii);
        $ascii = preg_replace('/^\.+$/', '_', $ascii) ?? $ascii;
        $ascii = str_replace('..', '_', $ascii);
        if ($ascii !== '' && str_ends_with($ascii, '.')) {
            $ascii = substr($ascii, 0, -1) . '_';
        }
        return str_starts_with($ascii, '.') ? '_' . substr($ascii, 1) : $ascii;
    }

    private static function ascii(string $value): string {
        if (preg_match('/^[\x00-\x7F]*$/', $value)) {
            return $value;
        }
        $value = str_replace('—', '--', $value);
        if (class_exists(\Transliterator::class)) {
            static $tr = null;
            $tr ??= \Transliterator::create('Any-Latin; Latin-ASCII');
            $out = $tr?->transliterate($value);
            if (is_string($out)) {
                return preg_replace('/[^\x00-\x7F]/u', '_', $out) ?? '';
            }
        }
        $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return is_string($out) && $out !== '' ? $out : (preg_replace('/[^\x00-\x7F]/', '_', $value) ?? '');
    }

    /** Whether two names differ only in upper and lower case or accents. */
    public static function sameName(string $a, string $b): bool {
        return Sorting::fold($a) === Sorting::fold($b);
    }
}
