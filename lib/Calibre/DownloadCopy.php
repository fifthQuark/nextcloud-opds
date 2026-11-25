<?php

declare(strict_types=1);

namespace OCA\Opds\Calibre;

use OCA\Opds\AppInfo\Application;
use OCA\Opds\Db\Book;
use OCA\Opds\Service\Config;
use OCP\Files\File;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * The file a reader gets: for an EPUB in a Calibre library a copy with the metadata and
 * cover from Calibre. The stored file is never changed.
 */
class DownloadCopy {
    /** Part of the ETag. Increase it when the same record gives a different file */
    private const VERSION = 1;
    private const FORMATS = ['epub', 'kepub'];
    /** Larger books are sent as they are, because copying takes too long for a request */
    private const MAX_SIZE = 200 * 1024 * 1024;

    public function __construct(
        private Config $config,
        private CalibreLibrary $calibre,
        private EpubMetadata $epub,
        private ITempManager $tempManager,
        private LoggerInterface $logger,
    ) {
    }

    /** Whether the file sent may change without the stored file having changed. */
    public function applies(string $uid, Book $book): bool {
        return $book->metaRev !== '' && in_array($book->format, self::FORMATS, true)
            && $this->config->isCalibreEnabled($uid) && $this->config->embedsMetadata($uid) && Sqlite::available();
    }

    /** @return array{path: string, size: int, etag: string}|null null: send the stored file */
    public function make(string $uid, Book $book, File $file): ?array {
        if (!$this->applies($uid, $book) || $file->getSize() > self::MAX_SIZE || !class_exists(\ZipArchive::class)) {
            return null;
        }
        $path = null;
        try {
            $library = $this->calibre->findFor($uid, $file);
            $record = $library === null ? null : $this->calibre->read($uid, $library, function (CalibreDb $db) use ($library, $file): ?array {
                $id = $db->filePaths()[CalibreDb::pathKey($this->calibre->inLibrary($library, $file))] ?? 0;
                return $id > 0 ? $db->get($id) : null;
            });
            if ($library === null || $record === null) {
                return null;
            }
            $cover = $this->calibre->coverFile($library, $record);
            $path = $this->copy($file);
            if ($this->epub->write($path, $record, $cover?->getContent())) {
                clearstatcache(true, $path);
                $etag = sha1(implode('|', [self::VERSION, $file->getEtag(), $record['rev'], $cover?->getEtag() ?? '']));
                return ['path' => $path, 'size' => (int)filesize($path), 'etag' => $etag];
            }
        } catch (\Throwable $e) {
            // The book itself is more important than what is written in it
            $this->logger->warning('OPDS: could not put the metadata into a copy of ' . $book->path . ': ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
        }
        if ($path !== null) {
            @unlink($path);
        }
        return null;
    }

    private function copy(File $file): string {
        $path = $this->tempManager->getTemporaryFile('.epub');
        if (!is_string($path) || $path === '') {
            throw new \RuntimeException('Cannot create a temporary file');
        }
        $in = $file->fopen('r');
        $out = fopen($path, 'w');
        if ($in === false || $out === false) {
            throw new \RuntimeException('Cannot copy the file');
        }
        $bytes = stream_copy_to_stream($in, $out);
        fclose($in);
        if (!fclose($out) || $bytes !== (int)$file->getSize()) {
            @unlink($path);
            throw new \RuntimeException('Cannot copy the file completely');
        }
        return $path;
    }
}
