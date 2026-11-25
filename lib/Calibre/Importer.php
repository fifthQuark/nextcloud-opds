<?php

declare(strict_types=1);

namespace OCA\Opds\Calibre;

use OCA\Opds\Db\Book;
use OCA\Opds\Db\BookRepository;
use OCA\Opds\Metadata\MetadataExtractor;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;

/** Copies the indexed books into a Calibre library. The originals stay untouched. */
class Importer {
    public function __construct(
        private BookRepository $repository,
        private IRootFolder $rootFolder,
        private CalibreLibrary $calibre,
        private MetadataExtractor $extractor,
        private FieldReader $fields,
    ) {
    }

    /**
     * @param string $after key returned as "last" by the previous call, '' to start
     * @return array{last: string, added: int, skipped: int, failed: list<string>, done: bool, processed: int, total: int}
     */
    public function batch(string $uid, Folder $library, string $after, int $seconds): array {
        $keys = $this->repository->listWorkKeys($uid);
        $start = 0;
        if ($after !== '') {
            $at = array_search($after, $keys, true);
            if ($at === false) {
                throw new CalibreException('The list of books changed in the meantime. Start copying again; books that were copied already are skipped');
            }
            $start = $at + 1;
        }
        $result = $this->calibre->import($uid, $library, $this->load($uid, array_slice($keys, $start)), $seconds);
        $at = array_search($result['last'], $keys, true);
        return $result + ['processed' => $at === false ? $start : $at + 1, 'total' => count($keys)];
    }

    /**
     * @param list<string> $keys
     * @return \Generator<string,callable>
     */
    private function load(string $uid, array $keys): \Generator {
        foreach ($keys as $key) {
            yield $key => fn (): ?array => $this->book($uid, $key);
        }
    }

    /** @return array{fields: array<string,mixed>, files: list<File>, cover: ?string}|null */
    private function book(string $uid, string $key): ?array {
        $books = $this->repository->findWorks($uid, [$key])[$key] ?? [];
        $files = [];
        foreach ($books as $book) {
            foreach ($this->rootFolder->getUserFolder($uid)->getById($book->fileId) as $node) {
                if ($node instanceof File) {
                    $files[] = $node;
                    break;
                }
            }
        }
        if ($files === []) {
            return null;
        }
        $main = $books[0];
        $cover = null;
        foreach ($files as $file) {
            $cover = $this->extractor->read($file, 'title')->coverData;
            if ($cover !== null) {
                break;
            }
        }
        return ['fields' => $this->toFields($main), 'files' => $files, 'cover' => $cover];
    }

    /** @return array<string,mixed> */
    private function toFields(Book $book): array {
        $in = [
            'title' => $book->title !== '' ? $book->title : 'Unknown',
            'authors' => $book->authors,
            'series' => $book->series,
            'series_index' => $book->seriesIndex,
            'tags' => $book->subjects,
            'publisher' => $book->publisher,
            'comments' => $book->description,
            'identifiers' => $book->isbn !== '' ? ['isbn' => $book->isbn] : [],
        ];
        foreach (['languages' => $book->language !== '' ? [$book->language] : [], 'pubdate' => $book->pubdate] as $key => $value) {
            try {
                $this->fields->read([$key => $value]);
                $in[$key] = $value;
            } catch (CalibreException $e) {
                // unknown language code or odd date in the file: left empty
            }
        }
        return $this->fields->read($in);
    }
}
