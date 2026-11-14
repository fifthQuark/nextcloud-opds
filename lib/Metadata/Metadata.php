<?php

declare(strict_types=1);

namespace OCA\Opds\Metadata;

/**
 * Raw metadata as read from a file, before normalisation into a Book.
 */
class Metadata {
    public string $title = '';
    /** @var string[] display names */
    public array $authors = [];
    /** @var string[] optional explicit sort names ("Austen, Jane"), parallel to $authors */
    public array $authorsSort = [];
    public string $publisher = '';
    public string $series = '';
    public float $seriesIndex = 0.0;
    /** @var string[] */
    public array $subjects = [];
    public string $language = '';
    /** Any date string; normalised later */
    public string $date = '';
    public string $description = '';
    public string $isbn = '';
    public ?string $coverData = null;
    public string $coverMime = '';

    public function addAuthor(string $name, string $sortName = ''): void {
        $name = Text::clean($name);
        if ($name === '') {
            return;
        }
        foreach ($this->authors as $existing) {
            if (mb_strtolower($existing) === mb_strtolower($name)) {
                return;
            }
        }
        $this->authors[] = $name;
        $this->authorsSort[] = Text::clean($sortName);
    }

    public function addSubject(string $subject): void {
        foreach (preg_split('/\s*(?:;|\/|\|)\s*/', $subject) ?: [] as $part) {
            $part = Text::clean($part);
            if ($part === '' || mb_strlen($part) > 200) {
                continue;
            }
            foreach ($this->subjects as $existing) {
                if (mb_strtolower($existing) === mb_strtolower($part)) {
                    continue 2;
                }
            }
            $this->subjects[] = $part;
        }
    }

    public function setCover(?string $data): void {
        if ($data === null || $data === '') {
            return;
        }
        $mime = Text::sniffImageMime($data);
        if ($mime === '') {
            return;
        }
        $this->coverData = $data;
        $this->coverMime = $mime;
    }

    public function hasTitle(): bool {
        return $this->title !== '';
    }
}
