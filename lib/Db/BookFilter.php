<?php

declare(strict_types=1);

namespace OCA\Opds\Db;

/**
 * Criteria for selecting books. All fields are optional and combined with AND.
 */
class BookFilter {
    public ?string $author = null;
    public ?string $subject = null;
    public ?string $publisher = null;
    public ?string $series = null;
    /** Only books that belong to no series */
    public ?bool $standalone = null;
    public ?string $language = null;
    public ?string $format = null;
    public ?int $year = null;
    /** First letter of the sort title (A-Z) or '#' for everything else */
    public ?string $letter = null;
    /** Exact parent folder (relative to the user's home) */
    public ?string $folder = null;
    /** Free text search over title, author, subjects, series and publisher */
    public ?string $query = null;

    public function isEmpty(): bool {
        foreach (get_object_vars($this) as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,string> query parameters reproducing this filter */
    public function toParams(): array {
        $params = [];
        foreach (get_object_vars($this) as $key => $value) {
            if ($value !== null && $value !== '') {
                $params[$key === 'query' ? 'q' : $key] = (string)$value;
            }
        }
        return $params;
    }
}
