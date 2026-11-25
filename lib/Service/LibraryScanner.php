<?php

declare(strict_types=1);

namespace OCA\Opds\Service;

use OCA\Opds\AppInfo\Application;
use OCA\Opds\Calibre\CalibreDb;
use OCA\Opds\Calibre\CalibreLibrary;
use OCA\Opds\Calibre\CalibreReader;
use OCA\Opds\Calibre\Languages;
use OCA\Opds\Calibre\Sqlite;
use OCA\Opds\Db\Book;
use OCA\Opds\Db\BookRepository;
use OCA\Opds\Db\Sorting;
use OCA\Opds\Metadata\FilenameExtractor;
use OCA\Opds\Metadata\MetadataExtractor;
use OCA\Opds\Metadata\Text;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IPreview;
use Psr\Log\LoggerInterface;

/**
 * Walks the configured library folder and keeps the book index in sync.
 */
class LibraryScanner {
    public function __construct(
        private IRootFolder $rootFolder,
        private Config $config,
        private BookRepository $repository,
        private MetadataExtractor $extractor,
        private CoverService $covers,
        private CalibreLibrary $calibre,
        private IPreview $preview,
        private ITimeFactory $time,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Incrementally (re)index the library of a user.
     *
     * @param int|null $timeBudget seconds after which indexing of new/changed files stops (pruning still happens)
     * @param bool $full re-read metadata of every file, even unchanged ones
     */
    public function scan(string $uid, ?int $timeBudget = null, bool $full = false): ScanResult {
        $result = new ScanResult();
        $start = microtime(true);
        $now = $this->time->getTime();
        $readers = [];

        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            $rootPath = $this->config->getRoot($uid);
            try {
                $root = $userFolder->get($rootPath);
            } catch (NotFoundException $e) {
                $result->error = 'Library folder "' . $rootPath . '" does not exist';
                return $this->finish($uid, $result, $start, $now);
            }
            if (!$root instanceof Folder) {
                $result->error = '"' . $rootPath . '" is not a folder';
                return $this->finish($uid, $result, $start, $now);
            }

            $files = $this->collectFiles($root, $this->config->getExtensions($uid));
            $result->total = count($files);
            $existing = $this->repository->getStates($uid);
            $failed = [];
            if ($this->config->isCalibreEnabled($uid) && Sqlite::available()) {
                $readers = $this->calibre->openAll($uid, $failed);
            }
            $pattern = $this->config->getFilenamePattern($uid);

            $seen = [];
            foreach ($files as $file) {
                $fileId = $file->getId();
                $seen[$fileId] = true;
                $isNew = !isset($existing[$fileId]);
                $path = $userFolder->getRelativePath($file->getPath()) ?? $file->getPath();
                [$reader, $calibreId] = $this->calibreBook($readers, $file);
                $rev = $reader !== null ? $reader->revision($calibreId) : '';
                if (!$isNew && $reader === null && $existing[$fileId]['rev'] !== '' && $this->isBelow($file, $failed)) {
                    // Keep what is known until the library can be read again
                    $result->unchanged++;
                    continue;
                }
                if (!$isNew && !$full && $existing[$fileId] === ['etag' => $file->getEtag(), 'path' => $path, 'rev' => $rev]) {
                    $result->unchanged++;
                    continue;
                }
                if ($timeBudget !== null && (microtime(true) - $start) > $timeBudget) {
                    $result->complete = false;
                    continue;
                }
                try {
                    $this->index($uid, $path, $file, $pattern, $now, $reader?->library, $reader?->record($calibreId));
                    $isNew ? $result->added++ : $result->updated++;
                } catch (\Throwable $e) {
                    $result->errors++;
                    $this->logger->warning('OPDS: failed to index ' . $file->getPath() . ': ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
                }
            }

            $missing = array_keys(array_diff_key($existing, $seen));
            if ($missing !== []) {
                $this->repository->deleteByFileIds($uid, $missing);
                foreach ($missing as $fileId) {
                    $this->covers->delete($uid, (int)$fileId);
                }
                $result->removed = count($missing);
            }
        } catch (\Throwable $e) {
            $result->error = 'Scan failed: ' . $e->getMessage();
            $this->logger->error('OPDS: scan failed for ' . $uid, ['app' => Application::APP_ID, 'exception' => $e]);
        } finally {
            foreach ($readers as $reader) {
                $reader->close();
            }
        }

        return $this->finish($uid, $result, $start, $now);
    }

    /** Brings the index up to date after a book of a Calibre library was edited. */
    public function refreshCalibreBook(string $uid, Folder $library, int $id): void {
        $record = $this->calibre->read($uid, $library, static fn (CalibreDb $db): ?array => $db->get($id));
        if ($record === null || $record['path'] === '') {
            return;
        }
        $userFolder = $this->rootFolder->getUserFolder($uid);
        $folder = $library->get($record['path']);
        if (!$folder instanceof Folder) {
            return;
        }
        $extensions = $this->config->getExtensions($uid);
        $wanted = [];
        foreach ($record['formats'] as $format => $name) {
            $wanted[CalibreDb::pathKey($name . '.' . $format)] = true;
        }
        foreach ($folder->getDirectoryListing() as $file) {
            if ($file instanceof File && isset($wanted[CalibreDb::pathKey($file->getName())])
                && in_array(FilenameExtractor::format($file->getName()), $extensions, true)) {
                $this->index($uid, $userFolder->getRelativePath($file->getPath()) ?? $file->getPath(), $file, '', $this->time->getTime(), $library, $record);
            }
        }
    }

    /** Run a scan if the configured interval has passed since the last one. */
    public function scanIfStale(string $uid, int $timeBudget = 20): ?ScanResult {
        $interval = $this->config->getScanInterval($uid);
        $last = $this->config->getLastScan($uid);
        $now = $this->time->getTime();
        if ($last === 0 || ($interval > 0 && $now - $last >= $interval * 60)) {
            // Claim the slot first so concurrent requests do not all start scanning
            $this->config->setLastScan($uid, $now);
            return $this->scan($uid, $timeBudget);
        }
        return null;
    }

    public function reset(string $uid): void {
        $this->repository->deleteAll($uid);
        $this->covers->deleteAll($uid);
        $this->config->setLastScan($uid, 0);
        $this->config->setLastScanReport($uid, '');
    }

    /**
     * @param string[] $extensions
     * @return File[]
     */
    private function collectFiles(Folder $root, array $extensions): array {
        $files = [];
        foreach ($extensions as $ext) {
            foreach ($root->search('.' . $ext) as $node) {
                if (!$node instanceof File) {
                    continue;
                }
                // .caltrash holds books deleted in Calibre
                if (FilenameExtractor::format($node->getName()) !== $ext || str_contains($node->getPath(), '/.caltrash/')) {
                    continue;
                }
                $files[$node->getId()] = $node;
            }
        }
        uasort($files, static fn (File $a, File $b): int => strcmp($a->getPath(), $b->getPath()));
        return array_values($files);
    }

    /** @param array<string,mixed>|null $record what Calibre knows about the book */
    private function index(string $uid, string $relativePath, File $file, string $pattern, int $now, ?Folder $library = null, ?array $record = null): void {
        $book = $this->repository->findByFileId($uid, $file->getId()) ?? new Book();
        $book->userId = $uid;
        $book->fileId = $file->getId();
        $book->path = $relativePath;
        $book->parent = dirname($relativePath);
        if ($book->parent === '.' || $book->parent === '') {
            $book->parent = '/';
        }
        $book->name = $file->getName();
        $book->etag = $file->getEtag();
        $book->mtime = $file->getMTime();
        $book->size = $file->getSize();
        $book->mimetype = $file->getMimeType();
        $book->format = FilenameExtractor::format($file->getName());
        if ($book->indexedAt === 0) {
            $book->indexedAt = $now;
        }

        if ($library !== null && $record !== null) {
            $this->applyCalibre($uid, $book, $library, $record);
            $this->repository->save($book);
            return;
        }
        $book->metaRev = '';
        $meta = $this->extractor->read($file, $pattern);
        $this->extractor->apply($book, $meta);

        if ($meta->coverData !== null && $this->covers->store($uid, $book->fileId, $meta->coverData)) {
            $book->hasCover = true;
        } else {
            $this->covers->delete($uid, $book->fileId);
            // Nextcloud can render previews of some formats (PDF with Imagick) on request
            $book->hasCover = $this->previewAvailable($file);
        }

        $this->repository->save($book);
    }

    /** @param list<string> $folders */
    private function isBelow(File $file, array $folders): bool {
        foreach ($folders as $folder) {
            if (str_starts_with($file->getPath(), $folder)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<CalibreReader> $readers
     * @return array{0: ?CalibreReader, 1: int}
     */
    private function calibreBook(array $readers, File $file): array {
        foreach ($readers as $reader) {
            $id = $reader->bookId($file);
            if ($id > 0) {
                return [$reader, $id];
            }
        }
        return [null, 0];
    }

    /**
     * What Calibre knows about a book replaces the metadata inside the file.
     *
     * @param array<string,mixed> $record
     */
    private function applyCalibre(string $uid, Book $book, Folder $library, array $record): void {
        $book->title = $record['title'];
        $book->titleSort = Sorting::titleSortKey($record['sort'] !== '' ? $record['sort'] : $record['title']);
        $book->titleLetter = Sorting::letterOf($book->titleSort);
        $book->authors = $record['authors'] === ['Unknown'] ? [] : $record['authors'];
        $book->authorSort = $book->authors !== [] ? Sorting::fold($record['author_sort']) : '';
        $book->publisher = $record['publisher'];
        $book->series = $record['series'];
        $book->seriesIndex = $record['series_index'];
        $book->subjects = $record['tags'];
        $book->language = Languages::fromCalibre($record['languages'][0] ?? '');
        $book->pubdate = $record['pubdate'];
        $book->pubyear = $book->pubdate !== '' ? (int)substr($book->pubdate, 0, 4) : 0;
        $book->description = Text::plain($record['comments']);
        $book->isbn = $record['identifiers']['isbn'] ?? '';
        $book->metaRev = $record['rev'];
        $added = strtotime($record['timestamp']);
        if ($added !== false && $added > 0) {
            $book->indexedAt = $added;
        }
        $cover = $this->calibre->coverFile($library, $record);
        $book->hasCover = $cover !== null && $this->covers->store($uid, $book->fileId, $cover->getContent());
        if (!$book->hasCover) {
            $this->covers->delete($uid, $book->fileId);
        }
    }

    private function previewAvailable(File $file): bool {
        // Only formats whose first page is a meaningful cover; text previews are not.
        if (!in_array(FilenameExtractor::format($file->getName()), ['pdf', 'djvu', 'djv'], true)) {
            return false;
        }
        try {
            return $this->preview->isAvailable($file);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function finish(string $uid, ScanResult $result, float $start, int $now): ScanResult {
        $result->seconds = round(microtime(true) - $start, 2);
        $result->finishedAt = $now;
        $this->config->setLastScan($uid, $result->error === '' ? $now : 0);
        try {
            $this->config->setLastScanReport($uid, $result->toJson());
        } catch (\JsonException $e) {
            // ignore
        }
        return $result;
    }
}
