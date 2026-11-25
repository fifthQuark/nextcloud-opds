<?php

declare(strict_types=1);

namespace OCA\Opds\Db;

use OCA\Opds\Metadata\FilenameExtractor;

/**
 * Plain data holder for one indexed book. Filled by the metadata extractors
 * and persisted by BookRepository.
 */
class Book {
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

    /** Same value for the files of one book in different formats ("Emma.epub", "Emma.pdf"). */
    public function work(): string {
        return sha1($this->parent . '/' . mb_strtolower(FilenameExtractor::stripExtension($this->name)));
    }

    /** Changes whenever the cover may look different; used to bypass browser caches. */
    public function coverVersion(): string {
        return substr(sha1($this->etag . '/' . $this->metaRev), 0, 10);
    }

    public function authorString(): string {
        return implode(', ', $this->authors);
    }
}
