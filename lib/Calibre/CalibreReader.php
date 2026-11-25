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
    /** @var array<string,true> */
    private array $folders;

    /** @param \Closure $onClose */
    public function __construct(
        public readonly Folder $library,
        private CalibreDb $db,
        private \Closure $onClose,
    ) {
        $this->paths = $db->filePaths();
        $this->revisions = $db->revisions();
        $this->folders = $db->bookFolders();
    }

    /** Id of the book this file belongs to, 0 if Calibre does not know the file. */
    public function bookId(File $file): int {
        $prefix = rtrim($this->library->getPath(), '/') . '/';
        if (!str_starts_with($file->getPath(), $prefix)) {
            return 0;
        }
        return $this->paths[CalibreDb::pathKey(substr($file->getPath(), strlen($prefix)))] ?? 0;
    }

    /** Whether Calibre keeps this file next to a book without listing it as one of its formats. */
    public function isExtra(File $file): bool {
        $path = ltrim($this->library->getRelativePath($file->getPath()) ?? '', '/');
        if ($path === '' || $this->bookId($file) > 0) {
            return false;
        }
        for ($folder = dirname($path); $folder !== '.'; $folder = dirname($folder)) {
            if (isset($this->folders[CalibreDb::pathKey($folder)])) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{label: string, name: string, type: string, hierarchy: bool}> */
    public function columns(): array {
        return $this->db->columns();
    }

    public function tagsHierarchy(): bool {
        return $this->db->tagsHierarchy();
    }

    /** Stays the same when the book or the library is renamed, and is shared by all formats of the book. */
    public function work(int $id): string {
        return self::workOf($this->library, $id);
    }

    public static function workOf(Folder $library, int $id): string {
        return sha1('calibre/' . $library->getId() . '/' . $id);
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
