<?php

declare(strict_types=1);

namespace OCA\Opds\Db;

use OCA\Opds\Metadata\FilenameExtractor;

/**
 * Plain data holder for one indexed book. Filled by the metadata extractors
 * and persisted by BookRepository.
 */
class Book {
    /** Types of custom columns whose values make a useful list */
    public const BROWSABLE = ['text', 'enumeration', 'series', 'bool', 'rating'];

    public int $id = 0;
    public string $userId = '';
    public int $fileId = 0;
    /** Path relative to the user's home folder, e.g. /Books/Austen/Emma.epub */
    public string $path = '';
    /** Parent directory of $path, e.g. /Books/Austen */
    public string $parent = '';
    public string $name = '';
    public string $etag = '';
    public int $mtime = 0;
    public int $size = 0;
    public string $mimetype = 'application/octet-stream';
    /** Lower-case file extension, e.g. epub */
    public string $format = '';

    public string $title = '';
    public string $titleSort = '';
    public string $titleLetter = '#';
    /** @var string[] */
    public array $authors = [];
    public string $authorSort = '';
    public string $publisher = '';
    public string $series = '';
    public float $seriesIndex = 0.0;
    /** @var string[] */
    public array $subjects = [];
    public string $language = '';
    /** ISO date (YYYY, YYYY-MM or YYYY-MM-DD) */
    public string $pubdate = '';
    public int $pubyear = 0;
    public string $description = '';
    public string $isbn = '';
    public bool $hasCover = false;
    public int $indexedAt = 0;
    /** Revision of the Calibre record the metadata came from, '' for metadata read from the file */
    public string $metaRev = '';

    // Only known for books in a Calibre library:

    /** 0 to 10, which is twice the number of stars */
    public int $rating = 0;
    /** @var string[] all languages, $language is the first; empty when there is just that one */
    public array $languages = [];
    /** @var array<string,string> */
    public array $identifiers = [];
    /** @var array<string,string> */
    public array $authorSorts = [];
    /** "A.B" among the subjects means B below A */
    public bool $subjectsNested = false;
    /** @var list<array{key: string, name: string, type: string, values: list<string>, text: string, sorts: list<string>, nested: bool}> */
    public array $custom = [];
    /** Set when something else than the file name ties the formats together */
    public string $workKey = '';

    /** Short enough for the index, also for long labels. */
    public static function columnKey(string $label): string {
        return mb_strlen($label) <= 15 ? $label : '~' . substr(sha1($label), 0, 14);
    }

    /** @return string[] */
    public function allLanguages(): array {
        return $this->languages !== [] ? $this->languages : ($this->language !== '' ? [$this->language] : []);
    }

    /**
     * What the book is listed under, besides the columns of its row.
     *
     * @return list<array{0: string, 1: string, 2: string}> kind, value, sort key
     */
    public function terms(): array {
        $terms = [];
        foreach ($this->authors as $author) {
            $sort = $this->authorSorts[$author] ?? '';
            $terms[] = ['author', $author, $sort !== '' ? Sorting::fold($sort) : Sorting::personSortKey($author)];
        }
        foreach ($this->subjects as $subject) {
            foreach ($this->subjectsNested ? self::withParents($subject) : [$subject] as $value) {
                $terms[] = ['subject', $value, Sorting::sortKey($value)];
            }
        }
        foreach (array_slice($this->languages, 1) as $language) {
            $terms[] = ['language', $language, $language];
        }
        foreach ($this->custom as $column) {
            if (!in_array($column['type'], self::BROWSABLE, true)) {
                continue;
            }
            foreach ($column['values'] as $i => $value) {
                foreach ($column['nested'] ? self::withParents($value) : [$value] as $v) {
                    $terms[] = ['#' . $column['key'], $v, ($column['sorts'][$i] ?? '') !== '' ? $column['sorts'][$i] : Sorting::titleSortKey($v)];
                }
            }
        }
        return $terms;
    }

    /** @return list<string> "A.B" -> "A", "A.B"; as in Calibre, "Dr. Who" is not divided */
    private static function withParents(string $value): array {
        $parts = array_values(array_filter(array_map('trim', explode('.', $value)), static fn (string $p): bool => $p !== ''));
        if (implode('.', $parts) !== $value) {
            return [$value];
        }
        $out = [];
        foreach (array_keys($parts) as $i) {
            $out[] = implode('.', array_slice($parts, 0, $i + 1));
        }
        return $out;
    }

    /** Same value for the files of one book in different formats ("Emma.epub", "Emma.pdf"). */
    public function work(): string {
        return $this->workKey !== '' ? $this->workKey : sha1($this->parent . '/' . mb_strtolower(FilenameExtractor::stripExtension($this->name)));
    }

    /** Changes whenever the cover may look different; used to bypass browser caches. */
    public function coverVersion(): string {
        return substr(sha1($this->etag . '/' . $this->metaRev), 0, 10);
    }

    /**
     * Name under which the file is downloaded. Calibre keeps titles and authors in other scripts only
     * in its database and names files in Latin letters ("sypvr `l hbh"), so its books get "Title - Author"
     * from the record. Other files keep their own name.
     */
    public function downloadName(): string {
        if ($this->metaRev === '' || $this->title === '') {
            return $this->name !== '' ? $this->name : 'book.' . $this->format;
        }
        $lower = strtolower($this->name);
        $extension = str_ends_with($lower, '.kepub.epub') ? '.kepub.epub'
            : (str_contains($this->name, '.') ? '.' . pathinfo($this->name, PATHINFO_EXTENSION) : '.' . $this->format);
        $name = $this->title . (isset($this->authors[0]) ? ' - ' . $this->authors[0] : '');
        // What is not allowed in file names on Windows, Android and e-readers
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '_', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        // Most file systems allow 255 bytes; letters of other scripts take two to four
        $name = mb_strcut($name, 0, 200 - strlen($extension), 'UTF-8');
        $name = rtrim($name, ' .');
        return $name !== '' ? $name . $extension : $this->name;
    }

    public function authorString(): string {
        return implode(', ', $this->authors);
    }
}
