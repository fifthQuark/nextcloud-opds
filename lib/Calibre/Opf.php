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
    private const USER = 'calibre:user_metadata:';

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
        $previous = [];
        $columns = array_column($book['columns'] ?? [], null, 'label');
        array_push($lines, ...self::unknown($existing, array_map(static fn (string $l): string => self::USER . '#' . $l, array_keys($columns)), $previous));
        foreach ($columns as $label => $column) {
            $entry(self::USER . '#' . $label, self::userMetadata($column, $previous[self::USER . '#' . $label] ?? null));
        }

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
     * A custom column as Calibre describes it, with the value of the book. What Calibre wrote before is kept.
     *
     * @param array{id: int, label: string, name: string, type: string, multiple: bool, names: bool, editable: bool, display: array<string,mixed>, value: mixed, extra: ?float} $column
     */
    private static function userMetadata(array $column, ?string $previous): string {
        $old = $previous !== null ? json_decode($previous, true) : null;
        $normalized = !in_array($column['type'], ['datetime', 'comments', 'int', 'bool', 'float'], true);
        $meta = (is_array($old) && ($old['datatype'] ?? '') === $column['type'] ? $old : []) + [
            'table' => 'custom_column_' . $column['id'], 'column' => 'value', 'datatype' => $column['type'],
            'is_multiple' => null, 'kind' => 'field', 'name' => $column['name'], 'search_terms' => ['#' . $column['label']],
            'label' => $column['label'], 'colnum' => $column['id'], 'display' => (object)$column['display'], 'is_custom' => true,
            'is_category' => $normalized, 'link_column' => 'value', 'category_sort' => 'value', 'is_csp' => false,
            'is_editable' => $column['editable'], 'is_multiple2' => (object)[],
        ];
        // What a restore of the database creates the column from
        $meta['name'] = $column['name'];
        $meta['display'] = (object)$column['display'];
        $meta['is_editable'] = $column['editable'];
        if ($column['multiple']) {
            $meta['is_multiple'] = '|';
            $meta['is_multiple2'] = $column['names']
                ? ['cache_to_list' => '|', 'ui_to_list' => '&', 'list_to_ui' => ' & ']
                : ['cache_to_list' => '|', 'ui_to_list' => ',', 'list_to_ui' => ', '];
        } else {
            $meta['is_multiple'] = null;
            $meta['is_multiple2'] = (object)[];
        }
        $value = $column['value'];
        if ($column['type'] === 'datetime' && $value !== null) {
            try {
                $date = new \DateTimeImmutable((string)$value, new \DateTimeZone('UTC'));
                $value = ['__class__' => 'datetime.datetime', '__value__' => $date->format('Y-m-d\TH:i:sP')];
            } catch (\Throwable $e) {
                $value = null;
            }
        }
        $meta['#value#'] = $value;
        $meta['#extra#'] = $column['extra'];
        return json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * Custom columns, links and contributors of the previous file.
     *
     * @param list<string> $replaced names of entries that are written anew
     * @param array<string,string> $previous gets their old content
     * @return list<string>
     */
    private static function unknown(string $existing, array $replaced = [], array &$previous = []): array {
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
            if (in_array($node->getAttribute('name'), $replaced, true)) {
                $previous[$node->getAttribute('name')] = $node->getAttribute('content');
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
