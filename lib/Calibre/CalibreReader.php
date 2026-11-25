<?php

declare(strict_types=1);

namespace OCA\Opds\Calibre;

use OCP\Files\File;
use OCP\Files\Folder;

/** One library opened for the duration of a scan. */
class CalibreReader {
    /** @var array<string,int> */
    private array $paths;
    /** @var array<int,string> */
    private array $revisions;

    /** @param \Closure $onClose */
    public function __construct(
        public readonly Folder $library,
        private CalibreDb $db,
        private \Closure $onClose,
    ) {
        $this->paths = $db->filePaths();
        $this->revisions = $db->revisions();
    }

    /** Id of the book this file belongs to, 0 if Calibre does not know the file. */
    public function bookId(File $file): int {
        $prefix = rtrim($this->library->getPath(), '/') . '/';
        if (!str_starts_with($file->getPath(), $prefix)) {
            return 0;
        }
        return $this->paths[CalibreDb::pathKey(substr($file->getPath(), strlen($prefix)))] ?? 0;
    }

    public function revision(int $id): string {
        return $this->revisions[$id] ?? '';
    }

    /** @return array<string,mixed>|null */
    public function record(int $id): ?array {
        return $this->db->get($id);
    }

    public function close(): void {
        $this->db->close();
        ($this->onClose)();
    }
}
