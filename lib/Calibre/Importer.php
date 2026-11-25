<?php

declare(strict_types=1);

namespace OCA\Opds\Calibre;

use OCA\Opds\Db\Book;
use OCA\Opds\Db\BookRepository;
use OCA\Opds\Metadata\EpubExtractor;
use OCA\Opds\Metadata\MetadataExtractor;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;

/**
 * Copies the indexed books into a Calibre library and leaves the originals untouched,
 * or, if the library is the catalog folder itself, moves them to where Calibre wants them.
 */
class Importer {
    public function __construct(
        private BookRepository $repository,
        private IRootFolder $rootFolder,
        private CalibreLibrary $calibre,
        private MetadataExtractor $extractor,
        private EpubExtractor $opf,
        private FieldReader $fields,
    ) {
    }

    /**
     * @param string $after "last" of the previous call, '' to start
     * @param bool $move the library is the catalog folder
     * @return array{last: string, added: int, skipped: int, failed: list<string>, done: bool, processed: int, total: int}
     */
    public function batch(string $uid, Folder $library, string $after, int $seconds, bool $move = false): array {
        // Not the keys: these change when a scan finds the moved files while this is going on
        $works = $this->repository->listWorkKeys($uid);
        $todo = array_filter($works, static fn (int $n): bool => $n > (int)$after);
        $result = $this->calibre->import($uid, $library, $this->load($uid, $todo), $seconds, $move);
        if ($result['last'] === '') {
            $result['last'] = $after;
        }
        $last = (int)$result['last'];
        return $result + ['processed' => count(array_filter($works, static fn (int $n): bool => $n <= $last)), 'total' => count($works)];
    }

    /**
     * @param array<string,int> $works
     * @return \Generator<int,callable>
     */
    private function load(string $uid, array $works): \Generator {
        foreach ($works as $key => $number) {
            yield $number => fn (?callable $wanted = null): ?array => $this->book($uid, (string)$key, $wanted);
        }
    }

    /**
     * @param (callable(File): bool)|null $wanted
     * @return array{fields: array<string,mixed>, files: list<File>, cover: ?string, added: int}|null
     */
    private function book(string $uid, string $key, ?callable $wanted): ?array {
        $books = $this->repository->findWorks($uid, [$key])[$key] ?? [];
        $files = [];
        foreach ($books as $book) {
            foreach ($this->rootFolder->getUserFolder($uid)->getById($book->fileId) as $node) {
                if ($node instanceof File && ($wanted === null || $wanted($node))) {
                    $files[] = $node;
                    break;
                }
            }
        }
        if ($files === []) {
            return null;
        }
        $main = $books[0];
        if ($wanted !== null) {
            $this->applyOpf($main, $files[0]);
        }
        $cover = null;
        foreach ($files as $file) {
            $cover = $this->extractor->read($file, 'title')->coverData;
            if ($cover !== null) {
                break;
            }
        }
        return ['fields' => $this->toFields($main), 'files' => $files, 'cover' => $cover, 'added' => $main->indexedAt];
    }

    /**
     * A file that is in a folder like "Title (12)" with a metadata.opf was moved there by Calibre, or
     * by a conversion that was cut off before the database was saved. Its name no longer follows the
     * pattern of the user then, so the metadata.opf is the better source.
     */
    private function applyOpf(Book $book, File $file): void {
        $folder = $file->getParent();
        if (!$folder instanceof Folder || preg_match('/ \(\d+\)$/', $folder->getName()) !== 1 || !$folder->nodeExists(CalibreLibrary::OPF)) {
            return;
        }
        try {
            $node = $folder->get(CalibreLibrary::OPF);
            $meta = $node instanceof File ? $this->opf->parse($node->getContent()) : null;
        } catch (\Throwable $e) {
            return;
        }
        if ($meta !== null && $meta->hasTitle()) {
            // The date is stored there in UTC, which may be the day before
            $date = $book->pubdate;
            $this->extractor->apply($book, $meta);
            $book->pubdate = $date;
        }
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
