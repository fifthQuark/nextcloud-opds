<?php

declare(strict_types=1);

namespace OCA\Opds\Service;

use OCA\Opds\AppInfo\Application;
use OCA\Opds\Db\Book;
use OCA\Opds\Db\BookRepository;
use OCA\Opds\Metadata\FilenameExtractor;
use OCA\Opds\Metadata\MetadataExtractor;
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
            $existing = $this->repository->getEtags($uid);
            $pattern = $this->config->getFilenamePattern($uid);

            $seen = [];
            foreach ($files as $file) {
                $fileId = $file->getId();
                $seen[$fileId] = true;
                $isNew = !isset($existing[$fileId]);
                if (!$isNew && !$full && $existing[$fileId] === $file->getEtag()) {
                    $result->unchanged++;
                    continue;
                }
                if ($timeBudget !== null && (microtime(true) - $start) > $timeBudget) {
                    $result->complete = false;
                    continue;
                }
                try {
                    $this->index($uid, $userFolder->getRelativePath($file->getPath()) ?? $file->getPath(), $file, $pattern, $now);
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
        }

        return $this->finish($uid, $result, $start, $now);
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
                if (FilenameExtractor::format($node->getName()) !== $ext) {
                    continue;
                }
                $files[$node->getId()] = $node;
            }
        }
        uasort($files, static fn (File $a, File $b): int => strcmp($a->getPath(), $b->getPath()));
        return array_values($files);
    }

    private function index(string $uid, string $relativePath, File $file, string $pattern, int $now): void {
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
