<?php

declare(strict_types=1);

namespace OCA\Opds\Metadata;

/**
 * String utilities shared by the extractors.
 */
final class Text {
    /** Trim, collapse whitespace and make sure the result is valid UTF-8. */
    public static function clean(string $value): string {
        $value = self::utf8($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    /** Strip markup and squash whitespace, keeping paragraph breaks as newlines. */
    public static function plain(string $html, int $maxLength = 4000): string {
        $html = self::utf8($html);
        $html = preg_replace('/<\s*(br|\/p|\/div|\/li|\/h[1-6])\s*\/?>/i', "\n", $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x0B\f\r]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;
        $text = trim($text);
        if (mb_strlen($text) > $maxLength) {
            $text = rtrim(mb_substr($text, 0, $maxLength - 1)) . '…';
        }
        return $text;
    }

    public static function utf8(string $value): string {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        return is_string($converted) ? $converted : '';
    }

    /**
     * Normalise a free-form date to YYYY, YYYY-MM or YYYY-MM-DD. Returns '' if
     * no year can be found.
     */
    public static function date(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        // PDF dates: D:20200115093000+01'00'
        if (preg_match('/^D:(\d{4})(\d{2})?(\d{2})?/', $value, $m)) {
            return self::assembleDate($m[1], $m[2] ?? '', $m[3] ?? '');
        }
        if (preg_match('/^(\d{4})(?:[-\/.](\d{1,2}))?(?:[-\/.](\d{1,2}))?/', $value, $m)) {
            return self::assembleDate($m[1], $m[2] ?? '', $m[3] ?? '');
        }
        // 15 January 2020, January 2020, Jan 15, 2020
        $ts = strtotime($value);
        if ($ts !== false && $ts > -2208988800) {
            return date('Y-m-d', $ts);
        }
        if (preg_match('/\b(1[5-9]\d{2}|20\d{2})\b/', $value, $m)) {
            return $m[1];
        }
        return '';
    }

    private static function assembleDate(string $y, string $m, string $d): string {
        $year = (int)$y;
        if ($year < 1 || $year > 9999) {
            return '';
        }
        $out = sprintf('%04d', $year);
        $month = (int)$m;
        if ($month >= 1 && $month <= 12) {
            $out .= sprintf('-%02d', $month);
            $day = (int)$d;
            if ($day >= 1 && $day <= 31) {
                $out .= sprintf('-%02d', $day);
            }
        }
        return $out;
    }

    /** Extract a bare ISBN-10/13 from an identifier string, or ''. */
    public static function isbn(string $value): string {
        $digits = preg_replace('/[^0-9Xx]/', '', $value) ?? '';
        if (strlen($digits) === 13 && (str_starts_with($digits, '978') || str_starts_with($digits, '979'))) {
            return $digits;
        }
        if (strlen($digits) === 10) {
            return strtoupper($digits);
        }
        return '';
    }

    /** Lower-case BCP-47-ish language tag ("en", "pt-BR"), or ''. */
    public static function language(string $value): string {
        $value = trim($value);
        if ($value === '' || !preg_match('/^([a-zA-Z]{2,3})(?:[-_]([a-zA-Z]{2,4}))?/', $value, $m)) {
            return '';
        }
        $lang = strtolower($m[1]);
        return isset($m[2]) ? $lang . '-' . strtoupper($m[2]) : $lang;
    }

    public static function sniffImageMime(string $data): string {
        if (str_starts_with($data, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($data, 'GIF8')) {
            return 'image/gif';
        }
        if (str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (str_starts_with($data, 'BM')) {
            return 'image/bmp';
        }
        return '';
    }

    public static function isImageName(string $name): bool {
        return (bool)preg_match('/\.(jpe?g|png|gif|webp|bmp)$/i', $name);
    }

    /** Load XML into a DOMDocument without network access or warnings. */
    public static function dom(string $xml): ?\DOMDocument {
        if (trim($xml) === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA | LIBXML_PARSEHUGE);
        if (!$ok) {
            // Second attempt in recovering mode for slightly broken files (unescaped '&' and the like)
            $doc = new \DOMDocument();
            $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA | LIBXML_PARSEHUGE | LIBXML_RECOVER) && $doc->documentElement !== null;
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $doc : null;
    }

    /** Text content of the first element matching a namespace agnostic local name path. */
    public static function firstText(\DOMXPath $xp, string $query, ?\DOMNode $context = null): string {
        $nodes = $xp->query($query, $context);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        return self::clean($nodes->item(0)->textContent ?? '');
    }

    /** @return string[] */
    public static function allText(\DOMXPath $xp, string $query, ?\DOMNode $context = null): array {
        $out = [];
        $nodes = $xp->query($query, $context);
        if ($nodes === false) {
            return $out;
        }
        foreach ($nodes as $node) {
            $text = self::clean($node->textContent ?? '');
            if ($text !== '') {
                $out[] = $text;
            }
        }
        return $out;
    }

    /** Namespace agnostic element selector: ln('metadata','title') => "*[local-name()='metadata']/*[local-name()='title']" */
    public static function ln(string ...$names): string {
        return implode('/', array_map(static fn (string $n): string => "*[local-name()='" . $n . "']", $names));
    }
}
