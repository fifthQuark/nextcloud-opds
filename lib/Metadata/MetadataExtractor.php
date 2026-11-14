<?php

declare(strict_types=1);

namespace OCA\Opds\Metadata;

use OCA\Opds\Db\Book;
use OCA\Opds\Db\Sorting;
use OCP\Files\File;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Picks the right extractor for a file, merges the result with the filename
 * fallback and normalises everything into a Book.
 */
class MetadataExtractor {
    public function __construct(
        private ITempManager $tempManager,
        private LoggerInterface $logger,
        private EpubExtractor $epub = new EpubExtractor(),
        private PdfExtractor $pdf = new PdfExtractor(),
        private ComicExtractor $comic = new ComicExtractor(),
        private MobiExtractor $mobi = new MobiExtractor(),
        private Fb2Extractor $fb2 = new Fb2Extractor(),
        private OfficeExtractor $office = new OfficeExtractor(),
        private FilenameExtractor $filename = new FilenameExtractor(),
    ) {
    }

    public function read(File $file, string $filenamePattern): Metadata {
        $name = $file->getName();
        $format = FilenameExtractor::format($name);
        $fallback = $this->filename->extract($name, $filenamePattern);

        $meta = null;
        $tempPath = null;
        try {
            $meta = $this->readEmbedded($file, $format, $tempPath);
        } catch (\Throwable $e) {
            $this->logger->info('OPDS: could not read metadata of ' . $file->getPath() . ': ' . $e->getMessage(), ['app' => 'opds']);
        } finally {
            if ($tempPath !== null && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
        $meta ??= new Metadata();

        // Fill gaps from the filename
        if (!$meta->hasTitle()) {
            $meta->title = $fallback->title;
        }
        if ($meta->authors === []) {
            foreach ($fallback->authors as $i => $author) {
                $meta->addAuthor($author, $fallback->authorsSort[$i] ?? '');
            }
        }
        if ($meta->date === '') {
            $meta->date = $fallback->date;
        }
        return $meta;
    }

    public function apply(Book $book, Metadata $meta): void {
        $book->title = $meta->title !== '' ? $meta->title : $book->name;
        $book->titleSort = Sorting::titleSortKey($book->title);
        $book->titleLetter = Sorting::letterOf($book->titleSort);
        $book->authors = $meta->authors;
        $sortNames = [];
        foreach ($meta->authors as $i => $author) {
            $explicit = $meta->authorsSort[$i] ?? '';
            $sortNames[] = $explicit !== '' ? Sorting::fold($explicit) : Sorting::personSortKey($author);
        }
        $book->authorSort = implode(' & ', $sortNames);
        $book->publisher = $meta->publisher;
        $book->series = $meta->series;
        $book->seriesIndex = $meta->seriesIndex;
        $book->subjects = $meta->subjects;
        $book->language = $meta->language;
        $book->pubdate = Text::date($meta->date);
        $book->pubyear = $book->pubdate !== '' ? (int)substr($book->pubdate, 0, 4) : 0;
        $book->description = $meta->description;
        $book->isbn = $meta->isbn;
    }

    private function readEmbedded(File $file, string $format, ?string &$tempPath): ?Metadata {
        switch ($format) {
            case 'epub':
            case 'kepub':
                return $this->epub->extract($this->localPath($file, $tempPath));
            case 'pdf':
                return $this->withHandle($file, fn ($h) => $this->pdf->extract($h, $file->getSize()));
            case 'mobi':
            case 'prc':
            case 'azw':
            case 'azw3':
            case 'azw4':
                return $this->withHandle($file, fn ($h) => $this->mobi->extract($h, $file->getSize()));
            case 'cbz':
            case 'cbr':
                return $this->comic->extract($this->localPath($file, $tempPath), $format);
            case 'fb2':
                return $this->fb2->extract($this->localPath($file, $tempPath), str_ends_with(strtolower($file->getName()), '.zip'));
            case 'docx':
            case 'odt':
                return $this->office->extract($this->localPath($file, $tempPath), $format);
            default:
                return null;
        }
    }

    /**
     * Path of the file on local disk. Remote storages are copied to a temp
     * file that the caller removes.
     */
    private function localPath(File $file, ?string &$tempPath): string {
        $storage = $file->getStorage();
        $local = $storage->getLocalFile($file->getInternalPath());
        if (is_string($local) && $local !== '' && is_readable($local)) {
            // Non-local storages (and the encryption wrapper) hand out a temporary
            // copy that we remove ourselves instead of waiting for request shutdown.
            $tempBase = rtrim($this->tempManager->getTempBaseDir(), '/') . '/';
            if (!$this->isPersistentLocal($storage) || str_starts_with($local, $tempBase)) {
                $tempPath = $local;
            }
            return $local;
        }
        $tempPath = $this->tempManager->getTemporaryFile('.' . FilenameExtractor::format($file->getName()));
        if ($tempPath === false) {
            throw new \RuntimeException('Cannot create temporary file');
        }
        $in = $file->fopen('r');
        if ($in === false) {
            throw new \RuntimeException('Cannot open file');
        }
        $out = fopen($tempPath, 'w');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException('Cannot write temporary file');
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        return $tempPath;
    }

    private function isPersistentLocal(\OCP\Files\Storage\IStorage $storage): bool {
        return $storage->instanceOfStorage(\OC\Files\Storage\Local::class)
            && !$storage->instanceOfStorage(\OC\Files\Storage\Temporary::class);
    }

    /**
     * @template T
     * @param callable(resource): T $fn
     * @return T|null
     */
    private function withHandle(File $file, callable $fn) {
        $handle = $file->fopen('r');
        if ($handle === false) {
            return null;
        }
        try {
            $meta = stream_get_meta_data($handle);
            if (empty($meta['seekable'])) {
                // Non-seekable stream (e.g. object storage): buffer it in a temp file
                $tmp = tmpfile();
                if ($tmp === false) {
                    return null;
                }
                stream_copy_to_stream($handle, $tmp);
                fclose($handle);
                $handle = $tmp;
            }
            return $fn($handle);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
