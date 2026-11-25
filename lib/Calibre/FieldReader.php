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
        return $out;
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
