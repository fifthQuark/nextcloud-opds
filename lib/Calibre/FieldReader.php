<?php

declare(strict_types=1);

namespace OCA\Opds\Calibre;

use OCA\Opds\Metadata\Text;

/** Checks and normalises edited fields before they reach the database. */
class FieldReader {
    /**
     * @param array<string,mixed> $in only known keys are used, absent ones stay unchanged
     * @return array<string,mixed> fields for CalibreDb::update()
     */
    public function read(array $in): array {
        $out = [];
        foreach (['title', 'sort', 'author_sort', 'series', 'publisher'] as $key) {
            if (array_key_exists($key, $in)) {
                $out[$key] = $this->text($in[$key], 1000);
            }
        }
        if (array_key_exists('title', $out) && $out['title'] === '') {
            throw new CalibreException('The title must not be empty');
        }
        foreach (['authors' => 100, 'tags' => 500] as $key => $max) {
            if (array_key_exists($key, $in)) {
                $out[$key] = $this->list($in[$key], $max);
            }
        }
        if (array_key_exists('authors', $out) && $out['authors'] === []) {
            $out['authors'] = ['Unknown'];
        }
        if (array_key_exists('tags', $out)) {
            // Calibre separates tags with commas
            $out['tags'] = $this->list(array_map(static fn (string $t): string => str_replace(',', ';', $t), $out['tags']), 500);
        }
        if (array_key_exists('series_index', $in)) {
            if (!is_numeric($in['series_index']) || abs((float)$in['series_index']) > 1_000_000) {
                throw new CalibreException('The number in the series must be a number');
            }
            $out['series_index'] = round((float)$in['series_index'], 2);
        }
        if (array_key_exists('rating', $in)) {
            $out['rating'] = max(0, min(10, (int)$in['rating']));
        }
        if (array_key_exists('pubdate', $in)) {
            $out['pubdate'] = $this->date((string)$in['pubdate']);
        }
        if (array_key_exists('languages', $in)) {
            $out['languages'] = [];
            foreach ($this->list($in['languages'], 20) as $name) {
                $code = Languages::toCalibre($name);
                if ($code === null) {
                    throw new CalibreException('"' . $name . '" is not a language known to this app. Use its English name or a three-letter code');
                }
                $out['languages'][] = $code;
            }
        }
        if (array_key_exists('comments', $in)) {
            $out['comments'] = self::paragraphs((string)$in['comments']);
        }
        if (array_key_exists('identifiers', $in) && is_array($in['identifiers'])) {
            $out['identifiers'] = [];
            foreach ($in['identifiers'] as $type => $value) {
                $type = strtolower($this->text($type, 50));
                $value = $this->text($value, 300);
                if ($type === '' || $value === '') {
                    continue;
                }
                if (preg_match('/[:,\s]/', $type)) {
                    throw new CalibreException('"' . $type . '" cannot be used as the name of an identifier');
                }
                $out['identifiers'][$type] = str_replace(',', '|', $value);
            }
        }
        if (array_key_exists('custom', $in) && is_array($in['custom'])) {
            // Checked against the type of each column when the library is open
            $out['custom'] = array_slice($in['custom'], 0, 200, true);
        }
        return $out;
    }

    /**
     * Changes for several books at once. Absent keys stay unchanged in every book.
     *
     * @param array<string,mixed> $in authors, series, series_start, publisher, pubdate, languages, rating,
     *     tags_add, tags_remove, tags_clear, custom (label => value), custom_add and custom_remove (label => list)
     * @return array<string,mixed> for CalibreDb::bulk()
     */
    public function bulk(array $in): array {
        $out = array_intersect_key($this->read(array_intersect_key($in, array_flip(['authors', 'series', 'publisher', 'pubdate', 'languages', 'rating']))), $in);
        if (array_key_exists('authors', $out) && $this->list($in['authors'], 100) === []) {
            // Books always have an author, so no authors means no change
            unset($out['authors']);
        }
        if (array_key_exists('series_start', $in) && $in['series_start'] !== null && $in['series_start'] !== '') {
            if (($out['series'] ?? '') === '') {
                throw new CalibreException('Books can only be numbered in a series that is set at the same time');
            }
            if (!is_numeric($in['series_start']) || abs((float)$in['series_start']) > 1_000_000) {
                throw new CalibreException('The first number in the series must be a number');
            }
            $out['series_start'] = round((float)$in['series_start'], 2);
        }
        foreach (['tags_add', 'tags_remove'] as $key) {
            if (array_key_exists($key, $in)) {
                $tags = $this->read(['tags' => $in[$key]])['tags'];
                if ($tags !== []) {
                    $out[$key] = $tags;
                }
            }
        }
        if (($in['tags_clear'] ?? false) === true) {
            $out['tags_clear'] = true;
        }
        if (array_key_exists('custom', $in) && is_array($in['custom']) && $in['custom'] !== []) {
            $out['custom'] = array_slice($in['custom'], 0, 200, true);
        }
        foreach (['custom_add', 'custom_remove'] as $key) {
            foreach (is_array($in[$key] ?? null) ? array_slice($in[$key], 0, 200, true) : [] as $label => $values) {
                $values = $this->list($values, 500);
                if ($values !== []) {
                    $out[$key][(string)$label] = $values;
                }
            }
        }
        if ($out === []) {
            throw new CalibreException('Nothing to change. Fill in at least one field');
        }
        return $out;
    }

    /**
     * A value for a custom column of Calibre.
     *
     * @param array{type: string, name: string, multiple: bool, names: bool, display: array<string,mixed>, order: array<string,int>} $column
     * @return mixed list<string> for lists, ['value' => string, 'index' => float] for series, null or '' for no value
     */
    public static function column(array $column, mixed $value): mixed {
        $name = '"' . $column['name'] . '"';
        $text = static fn (mixed $v, int $max = 1000): string => is_scalar($v) ? mb_substr(Text::clean((string)$v), 0, $max) : '';
        $empty = $value === null || $value === '' || $value === [];
        switch ($column['type']) {
            case 'text':
                if (!$column['multiple']) {
                    return $text($value);
                }
                $out = [];
                foreach (is_array($value) ? $value : [] as $item) {
                    $item = $text($item, 500);
                    // The separators of Calibre: & between names, commas otherwise
                    $item = $column['names'] ? trim(str_replace('&', '+', $item)) : trim(str_replace(',', ';', $item));
                    if ($item !== '') {
                        $out[mb_strtolower($item)] ??= $item;
                    }
                }
                return array_slice(array_values($out), 0, 500);
            case 'enumeration':
                $value = $text($value);
                if ($value === '') {
                    return '';
                }
                foreach (array_keys($column['order']) as $allowed) {
                    if (mb_strtolower((string)$allowed) === mb_strtolower($value)) {
                        return (string)$allowed;
                    }
                }
                throw new CalibreException($name . ' can only be one of: ' . implode(', ', array_map('strval', array_keys($column['order']))));
            case 'series':
                $series = is_array($value) ? $text($value['value'] ?? '') : $text($value);
                $index = is_array($value) ? ($value['index'] ?? 1) : 1;
                if ($index === '' || $index === null) {
                    $index = 1;
                }
                if (!is_numeric($index) || abs((float)$index) > 1_000_000) {
                    throw new CalibreException('The number in ' . $name . ' must be a number');
                }
                return ['value' => $series, 'index' => round((float)$index, 2)];
            case 'comments':
                $value = is_scalar($value) ? (string)$value : '';
                return CalibreDb::isHtml($column['display']) ? self::paragraphs($value) : mb_substr(trim(str_replace("\r", '', Text::utf8($value))), 0, 100000);
            case 'datetime':
                return self::day($text($value));
            case 'int':
            case 'float':
                if ($empty) {
                    return null;
                }
                if (!is_numeric($value) || abs((float)$value) > 1e15 || ($column['type'] === 'int' && (float)$value !== floor((float)$value))) {
                    throw new CalibreException($name . ' must be ' . ($column['type'] === 'int' ? 'a whole number' : 'a number'));
                }
                return $column['type'] === 'int' ? (int)$value : (float)$value;
            case 'bool':
                if ($empty) {
                    return null;
                }
                if (is_bool($value)) {
                    return $value;
                }
                return match (mb_strtolower($text($value))) {
                    'yes', 'true', '1' => true,
                    'no', 'false', '0' => false,
                    default => throw new CalibreException($name . ' must be Yes, No or empty'),
                };
            case 'rating':
                $rating = is_numeric($value) ? max(0, min(10, (int)round((float)$value))) : 0;
                // Half stars only where the column allows them
                return ($column['display']['allow_half_stars'] ?? false) ? $rating : 2 * (int)round($rating / 2);
            default:
                throw new CalibreException($name . ' cannot be edited here');
        }
    }

    /**
     * The new name of an author, series, tag, publisher or value of a custom column.
     *
     * @param array{multiple: bool, names: bool}|null $column for "#label"
     */
    public function newName(string $kind, mixed $name, ?array $column = null): string {
        $name = $this->text($name, 1000);
        if ($name === '') {
            throw new CalibreException('The new name must not be empty');
        }
        if (($kind === 'authors' || ($column['names'] ?? false)) && str_contains($name, '&')) {
            throw new CalibreException('A name cannot contain &, which separates names in Calibre');
        }
        if ($kind === 'tags' || (($column['multiple'] ?? false) && !($column['names'] ?? false))) {
            $name = str_replace(',', ';', $name);
        }
        return $name;
    }

    /** Plain text as the HTML that Calibre stores. */
    public static function paragraphs(string $text): string {
        $text = trim(str_replace("\r", '', Text::utf8($text)));
        if ($text === '') {
            return '';
        }
        $html = '';
        foreach (preg_split('/\n\s*\n|\n/', mb_substr($text, 0, 100000)) ?: [] as $part) {
            if (trim($part) !== '') {
                $html .= '<p>' . htmlspecialchars(trim($part), ENT_NOQUOTES, 'UTF-8') . '</p>';
            }
        }
        return $html;
    }

    /** "1999" or "1999-05" -> a full date */
    public function date(string $value): string {
        return self::day($value);
    }

    private static function day(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^(\d{4})(?:-(\d{1,2}))?(?:-(\d{1,2}))?$/', $value, $m)) {
            throw new CalibreException('The date must look like 1999-12-31');
        }
        [$y, $mo, $d] = [(int)$m[1], (int)($m[2] ?? 1), (int)($m[3] ?? 1)];
        if ($y <= 101 || !checkdate($mo, $d, $y)) {
            throw new CalibreException('"' . $value . '" is not a valid date');
        }
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    private function text(mixed $value, int $max): string {
        return is_scalar($value) ? mb_substr(Text::clean((string)$value), 0, $max) : '';
    }

    /** @return list<string> */
    private function list(mixed $value, int $max): array {
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $item = $this->text($item, 500);
            if ($item !== '') {
                $out[mb_strtolower($item)] ??= $item;
            }
        }
        return array_slice(array_values($out), 0, $max);
    }
}
