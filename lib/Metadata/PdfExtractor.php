<?php

declare(strict_types=1);

namespace OCA\Opds\Metadata;

/**
 * Reads the document information dictionary and XMP packet of a PDF without
 * a full parser. Works on the head and tail of the file so that large files
 * are cheap to inspect; compressed object streams are not decoded.
 */
class PdfExtractor {
    private const CHUNK = 3 * 1024 * 1024;

    /**
     * @param resource $handle readable stream positioned anywhere
     */
    public function extract($handle, int $size): ?Metadata {
        $data = $this->readHeadAndTail($handle, $size);
        if ($data === '' || !str_contains(substr($data, 0, 1024), '%PDF')) {
            return null;
        }
        $meta = new Metadata();
        $this->parseInfoDictionary($data, $meta);
        $this->parseXmp($data, $meta);
        return $meta;
    }

    /**
     * @param resource $handle
     */
    private function readHeadAndTail($handle, int $size): string {
        rewind($handle);
        if ($size <= 0 || $size <= 2 * self::CHUNK) {
            $all = stream_get_contents($handle, 2 * self::CHUNK);
            return $all === false ? '' : $all;
        }
        $head = stream_get_contents($handle, self::CHUNK);
        $tail = '';
        if (fseek($handle, $size - self::CHUNK) === 0) {
            $tail = stream_get_contents($handle) ?: '';
        }
        return ($head === false ? '' : $head) . "\n%%OPDS-SPLIT%%\n" . $tail;
    }

    private function parseInfoDictionary(string $data, Metadata $meta): void {
        $fields = [];
        foreach (['Title', 'Author', 'Subject', 'Keywords', 'Producer', 'Creator', 'CreationDate', 'ModDate'] as $key) {
            // Take the last occurrence: incremental updates append newer dictionaries.
            if (preg_match_all('/\/' . $key . '\s*(\((?:[^()\\\\]|\\\\.|\((?:[^()\\\\]|\\\\.)*\))*\)|<[0-9A-Fa-f\s]*>)/s', $data, $m)) {
                $values = array_filter(array_map([$this, 'decodeString'], $m[1]), static fn (string $v): bool => $v !== '');
                if ($values !== []) {
                    $fields[$key] = end($values);
                }
            }
        }

        $title = Text::clean($fields['Title'] ?? '');
        if ($title !== '' && !$this->isJunkTitle($title)) {
            $meta->title = $title;
        }
        $author = Text::clean($fields['Author'] ?? '');
        if ($author !== '' && !preg_match('/^(unknown|user|admin|administrator|owner)$/i', $author)) {
            foreach (preg_split('/\s*(?:;|&|\band\b)\s*/i', $author) ?: [] as $name) {
                $meta->addAuthor($name);
            }
        }
        if (!empty($fields['Subject'])) {
            $meta->description = Text::plain($fields['Subject']);
        }
        if (!empty($fields['Keywords'])) {
            foreach (preg_split('/\s*[,;]\s*/', $fields['Keywords']) ?: [] as $kw) {
                $meta->addSubject($kw);
            }
        }
        $meta->date = $fields['CreationDate'] ?? '';
    }

    private function parseXmp(string $data, Metadata $meta): void {
        if (!preg_match_all('/<x:xmpmeta.*?<\/x:xmpmeta>/s', $data, $m)) {
            return;
        }
        $packet = end($m[0]);
        $doc = Text::dom($packet);
        if ($doc === null) {
            return;
        }
        $xp = new \DOMXPath($doc);
        $li = '//' . Text::ln('li');

        if (!$meta->hasTitle()) {
            $title = Text::firstText($xp, '//' . Text::ln('title') . $li) ?: Text::firstText($xp, '//' . Text::ln('title'));
            if ($title !== '' && !$this->isJunkTitle($title)) {
                $meta->title = $title;
            }
        }
        if ($meta->authors === []) {
            $creators = Text::allText($xp, '//' . Text::ln('creator') . $li);
            if ($creators === []) {
                $creators = Text::allText($xp, '//' . Text::ln('creator'));
            }
            foreach ($creators as $creator) {
                $meta->addAuthor($creator);
            }
        }
        if ($meta->description === '') {
            $meta->description = Text::plain(Text::firstText($xp, '//' . Text::ln('description') . $li));
        }
        foreach (Text::allText($xp, '//' . Text::ln('subject') . $li) as $subject) {
            $meta->addSubject($subject);
        }
        if ($meta->publisher === '') {
            $meta->publisher = Text::firstText($xp, '//' . Text::ln('publisher') . $li) ?: Text::firstText($xp, '//' . Text::ln('publisher'));
        }
        if ($meta->language === '') {
            $meta->language = Text::language(Text::firstText($xp, '//' . Text::ln('language') . $li));
        }
        if ($meta->date === '') {
            $meta->date = Text::firstText($xp, '//' . Text::ln('CreateDate'))
                ?: Text::firstText($xp, '//' . Text::ln('date') . $li);
        }
        if ($meta->isbn === '') {
            foreach (Text::allText($xp, '//' . Text::ln('identifier') . $li) as $id) {
                $isbn = Text::isbn($id);
                if ($isbn !== '' && stripos($id, 'isbn') !== false) {
                    $meta->isbn = $isbn;
                    break;
                }
            }
        }
    }

    private function isJunkTitle(string $title): bool {
        return (bool)preg_match('/^(untitled|microsoft word\b|document\d*|new document|scan|scanned document|print|output|\.?pdf|.*\.(docx?|indd|qxd|odt|txt|tex|html?)$)/i', $title);
    }

    /** Decode a PDF literal "(...)" or hex "<...>" string to UTF-8. */
    private function decodeString(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if ($raw[0] === '<') {
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', $raw) ?? '';
            if (strlen($hex) % 2 === 1) {
                $hex .= '0';
            }
            $bytes = (string)hex2bin($hex);
        } else {
            $inner = substr($raw, 1, -1);
            $bytes = preg_replace_callback('/\\\\(\d{1,3}|.)/s', static function (array $m): string {
                $e = $m[1];
                if (ctype_digit($e)) {
                    return chr(octdec($e) & 0xFF);
                }
                return match ($e) {
                    'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C",
                    "\n" => '', "\r" => '',
                    default => $e,
                };
            }, $inner) ?? $inner;
        }
        if (str_starts_with($bytes, "\xFE\xFF")) {
            $out = @mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        } elseif (str_starts_with($bytes, "\xFF\xFE")) {
            $out = @mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $out = substr($bytes, 3);
        } elseif (mb_check_encoding($bytes, 'UTF-8')) {
            $out = $bytes;
        } else {
            // PDFDocEncoding is close enough to Latin-1 for the usual characters
            $out = @mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
        }
        return is_string($out) ? trim($out) : '';
    }
}
