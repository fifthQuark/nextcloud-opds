<?php

declare(strict_types=1);

namespace OCA\Opds\Calibre;

/**
 * Writes the metadata.opf that Calibre keeps next to every book. It is the copy
 * from which Calibre can rebuild a lost or damaged metadata.db.
 */
final class Opf {
    private const OPF = 'http://www.idpf.org/2007/opf';
    private const DC = 'http://purl.org/dc/elements/1.1/';

    /**
     * @param array<string,mixed> $book record of CalibreDb::get()
     * @param string $existing previous content; custom columns and other entries unknown to this app are kept
     */
    public static function render(array $book, string $existing = ''): string {
        $lines = [];
        $dc = static function (string $name, string $text, array $attrs = []) use (&$lines): void {
            $lines[] = '<dc:' . $name . self::attributes($attrs) . '>' . self::escape($text) . '</dc:' . $name . '>';
        };
        $entry = static function (string $name, string $content) use (&$lines): void {
            $lines[] = '<meta' . self::attributes(['name' => $name, 'content' => $content]) . '/>';
        };

        $dc('identifier', (string)$book['id'], ['opf:scheme' => 'calibre', 'id' => 'calibre_id']);
        $dc('identifier', $book['uuid'], ['opf:scheme' => 'uuid', 'id' => 'uuid_id']);
        $dc('title', $book['title']);
        foreach ($book['authors'] as $author) {
            $dc('creator', $author, ['opf:file-as' => $book['author_sort'], 'opf:role' => 'aut']);
        }
        $dc('date', str_replace(' ', 'T', $book['pubdate_utc']));
        if ($book['comments'] !== '') {
            $dc('description', $book['comments']);
        }
        if ($book['publisher'] !== '') {
            $dc('publisher', $book['publisher']);
        }
        foreach ($book['identifiers'] as $type => $value) {
            $dc('identifier', $value, ['opf:scheme' => strtoupper((string)$type)]);
        }
        foreach ($book['languages'] as $code) {
            $dc('language', $code);
        }
        foreach ($book['tags'] as $tag) {
            $dc('subject', $tag);
        }
        if ($book['series'] !== '') {
            $entry('calibre:series', $book['series']);
            $entry('calibre:series_index', rtrim(rtrim(sprintf('%.2F', $book['series_index']), '0'), '.'));
        }
        if ($book['rating'] > 0) {
            $entry('calibre:rating', (string)$book['rating']);
        }
        $entry('calibre:timestamp', str_replace(' ', 'T', $book['timestamp']));
        $entry('calibre:title_sort', $book['sort']);
        array_push($lines, ...self::unknown($existing));

        $xml = "<?xml version='1.0' encoding='utf-8'?>\n"
            . '<package xmlns="' . self::OPF . '" unique-identifier="uuid_id" version="2.0">' . "\n"
            . '    <metadata xmlns:dc="' . self::DC . '" xmlns:opf="' . self::OPF . '">' . "\n";
        foreach ($lines as $line) {
            $xml .= '        ' . $line . "\n";
        }
        $xml .= "    </metadata>\n";
        if ($book['has_cover']) {
            $xml .= "    <guide>\n        <reference type=\"cover\" title=\"Cover\" href=\"cover.jpg\"/>\n    </guide>\n";
        }
        return $xml . "</package>\n";
    }

    /**
     * Custom columns, links and contributors of the previous file.
     *
     * @return list<string>
     */
    private static function unknown(string $existing): array {
        $old = new \DOMDocument();
        if (trim($existing) === '' || stripos($existing, '<!DOCTYPE') !== false || !@$old->loadXML($existing, LIBXML_NONET)) {
            return [];
        }
        $xp = new \DOMXPath($old);
        $xp->registerNamespace('o', self::OPF);
        $xp->registerNamespace('dc', self::DC);
        $known = ['calibre:series', 'calibre:series_index', 'calibre:rating', 'calibre:timestamp', 'calibre:title_sort'];
        $lines = [];
        foreach ($xp->query('//o:metadata/o:meta | //o:metadata/dc:contributor | //o:metadata/dc:rights | //o:metadata/dc:source') ?: [] as $node) {
            if (!$node instanceof \DOMElement || in_array($node->getAttribute('name'), $known, true)) {
                continue;
            }
            $attrs = [];
            foreach ($node->attributes as $attr) {
                $attrs[($attr->namespaceURI === self::OPF ? 'opf:' : '') . $attr->localName] = $attr->value;
            }
            $name = ($node->namespaceURI === self::DC ? 'dc:' : '') . $node->localName;
            $lines[] = '<' . $name . self::attributes($attrs) . ($node->textContent === '' ? '/>' : '>' . self::escape($node->textContent) . '</' . $name . '>');
        }
        return $lines;
    }

    /** @param array<string,string> $attrs */
    private static function attributes(array $attrs): string {
        $out = '';
        foreach ($attrs as $key => $value) {
            $out .= ' ' . $key . '="' . self::escape($value) . '"';
        }
        return $out;
    }

    private static function escape(string $text): string {
        $text = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
