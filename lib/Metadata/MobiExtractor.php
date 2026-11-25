<?php

declare(strict_types=1);

namespace OCA\Opds\Metadata;

/**
 * Parses the PalmDOC/MOBI header and EXTH records of .mobi/.prc/.azw/.azw3
 * files, including the embedded cover image.
 */
class MobiExtractor {
    private const LANGUAGES = [
        1 => 'ar', 2 => 'bg', 3 => 'ca', 4 => 'zh', 5 => 'cs', 6 => 'da', 7 => 'de', 8 => 'el', 9 => 'en',
        10 => 'es', 11 => 'fi', 12 => 'fr', 13 => 'he', 14 => 'hu', 15 => 'is', 16 => 'it', 17 => 'ja',
        18 => 'ko', 19 => 'nl', 20 => 'no', 21 => 'pl', 22 => 'pt', 23 => 'rm', 24 => 'ro', 25 => 'ru',
        26 => 'hr', 27 => 'sk', 28 => 'sq', 29 => 'sv', 30 => 'th', 31 => 'tr', 32 => 'ur', 33 => 'id',
        34 => 'uk', 35 => 'be', 36 => 'sl', 37 => 'et', 38 => 'lv', 39 => 'lt', 41 => 'fa', 42 => 'vi',
        43 => 'hy', 44 => 'az', 45 => 'eu', 47 => 'ka', 54 => 'af', 55 => 'ka', 57 => 'hi', 62 => 'ms',
        63 => 'kk', 69 => 'ta', 73 => 'mr', 74 => 'sa',
    ];

    /**
     * @param resource $handle
     */
    public function extract($handle, int $size): ?Metadata {
        rewind($handle);
        $pdb = fread($handle, 78);
        if ($pdb === false || strlen($pdb) < 78) {
            return null;
        }
        $type = substr($pdb, 60, 8);
        if ($type !== 'BOOKMOBI' && $type !== 'TEXtREAd') {
            return null;
        }
        $numRecords = unpack('n', substr($pdb, 76, 2))[1];
        if ($numRecords < 1) {
            return null;
        }
        $list = fread($handle, $numRecords * 8);
        if ($list === false || strlen($list) < $numRecords * 8) {
            return null;
        }
        $offsets = [];
        for ($i = 0; $i < $numRecords; $i++) {
            $offsets[] = unpack('N', substr($list, $i * 8, 4))[1];
        }
        $offsets[] = $size > 0 ? $size : PHP_INT_MAX;

        $record0 = $this->readRecord($handle, $offsets, 0);
        if ($record0 === null || strlen($record0) < 0x20) {
            return null;
        }

        $meta = new Metadata();
        $meta->title = Text::clean(rtrim(substr($pdb, 0, 32), "\0"));

        if (substr($record0, 16, 4) !== 'MOBI') {
            return $meta;
        }
        $headerLength = unpack('N', substr($record0, 20, 4))[1];
        $encodingCode = unpack('N', substr($record0, 28, 4))[1];
        $encoding = $encodingCode === 65001 ? 'UTF-8' : 'Windows-1252';
        $toUtf8 = static function (string $s) use ($encoding): string {
            $out = @mb_convert_encoding($s, 'UTF-8', $encoding);
            return is_string($out) ? $out : '';
        };

        if ($headerLength >= 0x5C) {
            $nameOffset = unpack('N', substr($record0, 0x54, 4))[1];
            $nameLength = unpack('N', substr($record0, 0x58, 4))[1];
            if ($nameLength > 0 && $nameOffset + $nameLength <= strlen($record0)) {
                $fullName = Text::clean($toUtf8(substr($record0, $nameOffset, $nameLength)));
                if ($fullName !== '') {
                    $meta->title = $fullName;
                }
            }
            $locale = unpack('N', substr($record0, 0x5C, 4))[1];
            $meta->language = self::LANGUAGES[$locale & 0xFF] ?? '';
        }
        $firstImage = $headerLength >= 0x70 ? unpack('N', substr($record0, 0x6C, 4))[1] : 0;
        $exthFlags = $headerLength >= 0x84 ? unpack('N', substr($record0, 0x80, 4))[1] : 0;

        if (($exthFlags & 0x40) !== 0) {
            $this->parseExth($record0, 16 + $headerLength, $meta, $toUtf8, $handle, $offsets, $firstImage);
        }
        return $meta;
    }

    /**
     * @param resource $handle
     * @param int[] $offsets
     */
    private function parseExth(string $record0, int $start, Metadata $meta, callable $toUtf8, $handle, array $offsets, int $firstImage): void {
        if (substr($record0, $start, 4) !== 'EXTH') {
            return;
        }
        $count = unpack('N', substr($record0, $start + 8, 4))[1];
        $pos = $start + 12;
        $authors = [];
        $subjects = [];
        $coverOffset = null;
        $updatedTitle = '';
        for ($i = 0; $i < $count && $pos + 8 <= strlen($record0); $i++) {
            $type = unpack('N', substr($record0, $pos, 4))[1];
            $length = unpack('N', substr($record0, $pos + 4, 4))[1];
            if ($length < 8 || $pos + $length > strlen($record0)) {
                break;
            }
            $data = substr($record0, $pos + 8, $length - 8);
            $pos += $length;
            switch ($type) {
                case 100: $authors[] = Text::clean($toUtf8($data)); break;
                case 101: $meta->publisher = Text::clean($toUtf8($data)); break;
                case 103: $meta->description = Text::plain($toUtf8($data)); break;
                case 104: $meta->isbn = Text::isbn($data) ?: $meta->isbn; break;
                case 105: $subjects[] = Text::clean($toUtf8($data)); break;
                case 106: $meta->date = Text::clean($data); break;
                case 201: if (strlen($data) >= 4) { $coverOffset = unpack('N', substr($data, 0, 4))[1]; } break;
                case 503: $updatedTitle = Text::clean($toUtf8($data)); break;
                case 524: $meta->language = Text::language(trim($data)) ?: $meta->language; break;
            }
        }
        if ($updatedTitle !== '') {
            $meta->title = $updatedTitle;
        }
        foreach ($authors as $field) {
            foreach (Authors::split($field) as $author) {
                // Kindle files often store "Last, First"
                $meta->addAuthor(str_contains($author, ',') ? \OCA\Opds\Db\Sorting::displayName($author) : $author, str_contains($author, ',') ? $author : '');
            }
        }
        foreach ($subjects as $subject) {
            $meta->addSubject($subject);
        }
        if ($coverOffset !== null && $firstImage > 0 && $coverOffset !== 0xFFFFFFFF) {
            $index = $firstImage + $coverOffset;
            if ($index < count($offsets) - 1) {
                $meta->setCover($this->readRecord($handle, $offsets, $index, 8 * 1024 * 1024));
            }
        }
    }

    /**
     * @param resource $handle
     * @param int[] $offsets
     */
    private function readRecord($handle, array $offsets, int $index, int $max = 1024 * 1024): ?string {
        if (!isset($offsets[$index], $offsets[$index + 1])) {
            return null;
        }
        $length = $offsets[$index + 1] - $offsets[$index];
        if ($length <= 0 || $length > $max) {
            return null;
        }
        if (fseek($handle, $offsets[$index]) !== 0) {
            return null;
        }
        $data = fread($handle, $length);
        return $data === false ? null : $data;
    }
}
