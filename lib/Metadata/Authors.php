<?php

declare(strict_types=1);

namespace OCA\Opds\Metadata;

use OCA\Opds\Db\Sorting;

/**
 * Separates author fields that name several people, e.g.
 * "Terry Pratchett & Neil Gaiman", "Gaiman, Neil; Pratchett, Terry" or
 * "Ann Leckie, N. K. Jemisin and Martha Wells".
 *
 * Commas and words such as "and" also occur inside a single name ("Le Guin, Ursula K.",
 * "Department of Health and Human Services"), so those only separate when every part
 * looks like a full personal name. When in doubt the field is left alone.
 */
final class Authors {
    /** Words that start a surname, so "Le Guin, Ursula Kroeber" is one person. */
    private const PARTICLES = [
        'van', 'von', 'de', 'del', 'della', 'di', 'da', 'dos', 'du', 'des', 'le', 'la', 'den', 'der', 'ter', 'ten',
        'af', 'av', 'zu', 'el', 'al', 'st', 'st.', 'saint', 'san', 'santa', 'mac', 'mc', 'ben', 'bin', 'ibn',
    ];

    /** Words that appear in the names of organisations, not of people. */
    private const ORGANISATION = [
        'of', 'for', 'the', 'university', 'press', 'institute', 'inc', 'inc.', 'ltd', 'ltd.', 'llc', 'gmbh', 'company',
        'co.', 'corporation', 'society', 'association', 'department', 'ministry', 'office', 'committee', 'council',
        'foundation', 'editors', 'team', 'staff', 'group', 'publishing', 'books', 'magazine', 'others',
    ];

    private const SUFFIXES = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'phd', 'ph.d.', 'md', 'm.d.', 'esq', 'esq.'];

    /**
     * @param string $sortName the file-as value belonging to the field, if any
     * @return list<string>
     */
    public static function split(string $value, string $sortName = ''): array {
        $names = [];
        foreach (self::splitSeparators($value) as $chunk) {
            foreach (self::splitList($chunk, $sortName) as $name) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Splits a name together with its sort name.
     *
     * @return list<array{0: string, 1: string}> display name and sort name ('' when unknown)
     */
    public static function expand(string $value, string $sortName = ''): array {
        $names = self::split($value, $sortName);
        if (count($names) === 1) {
            return [[$names[0], trim($sortName)]];
        }
        $sorts = self::splitSeparators($sortName);
        $paired = count($sorts) === count($names);
        $out = [];
        foreach ($names as $i => $name) {
            // The parts of a sort name derived from the whole field do not match the people
            $out[] = [$name, $paired && Sorting::sameWords($name, $sorts[$i]) ? $sorts[$i] : ''];
        }
        return $out;
    }

    /**
     * Separators that are never part of a name.
     *
     * @return list<string>
     */
    private static function splitSeparators(string $value): array {
        $parts = preg_split('/\s*[;\x{FF1B}\x{3001}]\s*|\s+[&|\/]\s+/u', trim($value)) ?: [$value];
        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => preg_match('/[\p{L}\p{N}]/u', $p) === 1));
    }

    /** @return list<string> */
    private static function splitList(string $chunk, string $sortName): array {
        $parts = preg_split('/\s*,?\s+(?:and|with|und|et)\s+/iu', $chunk) ?: [$chunk];
        $pieces = [];
        $lastFirst = true;
        foreach ($parts as $part) {
            $byComma = preg_split('/\s*,\s*/u', trim($part)) ?: [$part];
            $lastFirst = $lastFirst && count($byComma) === 2;
            array_push($pieces, ...$byComma);
        }
        if (count($parts) > 1 && $lastFirst) {
            // "Gaiman, Neil and Pratchett, Terry"
            return array_map('trim', $parts);
        }
        if (count($pieces) < 2) {
            return [$chunk];
        }
        foreach ($pieces as $piece) {
            if (!self::isFullName($piece)) {
                return [$chunk];
            }
        }
        if (count($parts) === 1 && count($pieces) === 2 && self::isLastFirst($pieces[0], $pieces[1], $chunk, $sortName)) {
            return [$chunk];
        }
        return $pieces;
    }

    /** Whether "a, b" is one person written as "Surname, Given names". */
    private static function isLastFirst(string $a, string $b, string $whole, string $sortName): bool {
        if ($sortName !== '' && Sorting::fold($sortName) === Sorting::fold($whole)) {
            return true;
        }
        $first = mb_strtolower(explode(' ', $a)[0]);
        // Given names often end in an initial, surnames never do
        return in_array($first, self::PARTICLES, true) || preg_match('/(?:^|\s)(?:\p{L}\.?|(?:\p{L}\.)+)$/u', $b) === 1;
    }

    private static function isFullName(string $name): bool {
        $words = preg_split('/\s+/u', trim($name)) ?: [];
        if (count($words) < 2 || count($words) > 5) {
            return false;
        }
        foreach ($words as $i => $word) {
            $lower = mb_strtolower($word);
            if (in_array($lower, self::ORGANISATION, true) || ($i === 0 && in_array($lower, self::SUFFIXES, true))) {
                return false;
            }
        }
        return true;
    }
}
