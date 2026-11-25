<?php

declare(strict_types=1);

namespace OCA\Opds\Calibre;

use OCA\Opds\AppInfo\Application;
use OCA\Opds\Metadata\FilenameExtractor;
use OCA\Opds\Service\Config;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\ITempManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * A Calibre library in the files of a user: finds it, reads metadata.db, and
 * carries out changes to the database together with the folders, book files,
 * cover.jpg and metadata.opf that belong to them.
 *
 * metadata.db is never changed in place. A temporary copy is changed, checked
 * and written back, after making sure that nobody replaced the original in the
 * meantime. Files that were moved are moved back if anything fails.
 */
class CalibreLibrary {
    public const DB = 'metadata.db';
    private const COVER = 'cover.jpg';
    public const OPF = 'metadata.opf';
    private const MAX_DB_SIZE = 512 * 1024 * 1024;
    /** A new backup is made when the newest one is older than this (seconds) */
    private const BACKUP_INTERVAL = 3600;
    private const BACKUPS = 10;
    private const BACKUP_BYTES = 300 * 1024 * 1024;

    public function __construct(
        private IRootFolder $rootFolder,
        private IAppDataFactory $appData,
        private ITempManager $tempManager,
        private ILockingProvider $locks,
        private Config $config,
        private CoverImage $image,
        private LoggerInterface $logger,
    ) {
    }

    // ---------------------------------------------------------------- finding

    /** Whether a file belongs to the catalog: inside its folder, and not hidden unless the user wants those. */
    public function isListed(string $uid, Node $node): bool {
        $path = $this->catalogFolder($uid)?->getRelativePath($node->getPath());
        return $path !== null && ($this->config->showsHidden($uid) || !FilenameExtractor::isHidden($path));
    }

    /** @return list<Folder> folders with a metadata.db in or below the catalog folder */
    public function findAll(string $uid): array {
        $root = $this->catalogFolder($uid);
        $out = [];
        if ($root === null) {
            return $out;
        }
        foreach ($root->search(self::DB) as $node) {
            if ($node instanceof File && $node->getName() === self::DB && !str_contains($node->getPath(), '/.cal')
                && $this->isListed($uid, $node)) {
                $parent = $node->getParent();
                if ($parent instanceof Folder) {
                    $out[$parent->getPath()] = $parent;
                }
            }
        }
        ksort($out);
        return array_values($out);
    }

    /** The library a file belongs to, looking upwards but not above the catalog folder. */
    public function findFor(string $uid, Node $node): ?Folder {
        $root = $this->catalogFolder($uid);
        if ($root === null) {
            return null;
        }
        $limit = strlen($root->getPath());
        for ($folder = $node->getParent(); strlen($folder->getPath()) >= $limit; $folder = $folder->getParent()) {
            if ($folder instanceof Folder && $folder->nodeExists(self::DB) && $folder->get(self::DB) instanceof File) {
                return $folder;
            }
            if (strlen($folder->getPath()) <= 1) {
                break;
            }
        }
        return null;
    }

    public function relative(string $uid, Node $node): string {
        return $this->rootFolder->getUserFolder($uid)->getRelativePath($node->getPath()) ?? '';
    }

    /** Path of a file inside its library. */
    public function inLibrary(Folder $library, Node $node): string {
        return ltrim($library->getRelativePath($node->getPath()) ?? '', '/');
    }

    private function catalogFolder(string $uid): ?Folder {
        try {
            $node = $this->rootFolder->getUserFolder($uid)->get($this->config->getRoot($uid));
            return $node instanceof Folder ? $node : null;
        } catch (NotFoundException $e) {
            return null;
        }
    }

    // ---------------------------------------------------------------- reading

    /**
     * Opens a copy of the database for reading.
     *
     * @template T
     * @param callable(CalibreDb): T $fn
     * @return T
     */
    public function read(string $uid, Folder $library, callable $fn): mixed {
        $path = $this->download($this->dbFile($library));
        try {
            $db = new CalibreDb($path, $this->config->getTimezone($uid));
            try {
                return $fn($db);
            } finally {
                $db->close();
            }
        } finally {
            $this->cleanup($path);
        }
    }

    /**
     * Opens every library of the user. Close the readers afterwards.
     *
     * @param list<string> $failed gets the paths of libraries that cannot be read
     * @return list<CalibreReader>
     */
    public function openAll(string $uid, array &$failed = []): array {
        $readers = [];
        foreach ($this->findAll($uid) as $library) {
            $path = null;
            try {
                $path = $this->download($this->dbFile($library));
                $copy = $path;
                $readers[] = new CalibreReader($library, new CalibreDb($path, $this->config->getTimezone($uid)), fn () => $this->cleanup($copy));
            } catch (\Throwable $e) {
                if ($path !== null) {
                    $this->cleanup($path);
                }
                $failed[] = rtrim($library->getPath(), '/') . '/';
                $this->logger->warning('OPDS: cannot read the Calibre library ' . $library->getPath() . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
            }
        }
        // The innermost library of a file comes first
        usort($readers, static fn (CalibreReader $a, CalibreReader $b): int => strlen($b->library->getPath()) <=> strlen($a->library->getPath()));
        return $readers;
    }

    /** @return string '' when books of this library can be edited, otherwise why not */
    public function readOnlyReason(Folder $library, CalibreDb $db): string {
        if (!$library->isCreatable() || !$library->isUpdateable() || !$this->dbFile($library)->isUpdateable()) {
            return 'You may not change this folder';
        }
        return $db->readOnlyReason();
    }

    /** @param array<string,mixed> $record */
    public function coverFile(Folder $library, array $record): ?File {
        if (!$record['has_cover'] || $record['path'] === '') {
            return null;
        }
        try {
            $node = $library->get($record['path'] . '/' . self::COVER);
            return $node instanceof File ? $node : null;
        } catch (NotFoundException $e) {
            return null;
        }
    }

    // ---------------------------------------------------------------- editing

    /**
     * Changes a book and renames its folder and files to match.
     *
     * @param array<string,mixed> $fields see CalibreDb::FIELDS
     * @return array<string,mixed> the record afterwards
     */
    public function edit(string $uid, Folder $library, int $id, array $fields): array {
        return $this->change($uid, $library, function (CalibreDb $db, array &$undo) use ($library, $id, $fields): array {
            $db->transaction(function () use ($db, $library, $id, $fields, &$undo): void {
                $before = $this->record($db, $id);
                $db->update($id, $fields);
                $after = $this->record($db, $id);
                // Only then: names in other scripts are transliterated a little differently by
                // Calibre, and its folders should not be renamed because of, say, a new tag
                if ($before['title'] !== $after['title'] || ($before['authors'][0] ?? '') !== ($after['authors'][0] ?? '')) {
                    $this->relocate($db, $library, $id, $undo);
                }
            });
            return $this->record($db, $id);
        }, $id);
    }

    /**
     * @param string|null $data image of any supported type, null removes the cover
     * @return array<string,mixed> the record afterwards
     */
    public function setCover(string $uid, Folder $library, int $id, ?string $data): array {
        $jpeg = $data !== null ? $this->image->toJpeg($data) : null;
        return $this->change($uid, $library, function (CalibreDb $db, array &$undo) use ($library, $id, $jpeg): array {
            $folder = $this->bookFolder($library, $this->record($db, $id));
            $db->transaction(static fn () => $db->setHasCover($id, $jpeg !== null));
            $this->writeFile($folder, self::COVER, $jpeg, $undo);
            return $this->record($db, $id);
        }, $id);
    }

    /**
     * Adds books to the library, copying or moving their files. Stops when the time is used up.
     *
     * @param iterable<int,callable> $books number => loads a book; gets a filter for its files
     * @param bool $move move files that are inside the library folder already
     * @return array{last: string, added: int, skipped: int, failed: list<string>, done: bool}
     */
    public function import(string $uid, Folder $library, iterable $books, int $seconds, bool $move = false): array {
        $left = [];
        $result = $this->change($uid, $library, function (CalibreDb $db, array &$undo) use ($uid, $library, $books, $seconds, $move, &$left): array {
            $start = microtime(true);
            $out = ['last' => '', 'added' => 0, 'skipped' => 0, 'failed' => [], 'done' => true];
            $wanted = $move ? $this->movable($uid, $library, $db, $out['skipped']) : null;
            $db->transaction(function () use ($db, $library, $books, $seconds, $move, $wanted, $start, &$out, &$undo, &$left): void {
                foreach ($books as $key => $load) {
                    if (microtime(true) - $start > $seconds && $out['last'] !== '') {
                        $out['done'] = false;
                        return;
                    }
                    $steps = [];
                    $book = $load($wanted);
                    if ($move && $book === null) {
                        // Already part of the library, or counted by the filter
                    } elseif ($book === null || (!$move && $db->findSame($book['fields']['title'], $book['fields']['authors'][0] ?? 'Unknown') > 0)) {
                        $out['skipped']++;
                    } else {
                        try {
                            $from = array_map(fn (File $f): string => dirname($this->inLibrary($library, $f)), $move ? $book['files'] : []);
                            $db->savepoint(function () use ($db, $library, $book, $move, &$steps): void {
                                $this->addBook($db, $library, $book, $move, $steps);
                            });
                            $out['added']++;
                            array_push($undo, ...($move ? $steps : []));
                            array_push($left, ...$from);
                        } catch (\Throwable $e) {
                            // One unreadable file must not block the rest
                            $this->rollback($library, $move ? $steps : []);
                            $this->logger->warning('OPDS: could not copy ' . $book['fields']['title'] . ' to the Calibre library: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
                            $out['failed'][] = (string)$book['fields']['title'];
                        }
                    }
                    $out['last'] = (string)$key;
                }
            });
            return $out;
        });
        // Only now that nothing has to be moved back any more
        foreach (array_unique($left) as $path) {
            for (; $path !== '.' && $path !== ''; $path = dirname($path)) {
                try {
                    $this->removeIfEmpty($library, $path);
                } catch (\Throwable $e) {
                    break;
                }
            }
        }
        return $result;
    }

    /** @return \Closure(File): bool whether a file can be moved to the folder of a new book */
    private function movable(string $uid, Folder $library, CalibreDb $db, int &$skipped): \Closure {
        $known = $db->filePaths();
        $folders = $db->bookFolders();
        $others = [];
        foreach ($this->findAll($uid) as $other) {
            if ($other->getId() !== $library->getId()) {
                $others[] = rtrim($other->getPath(), '/') . '/';
            }
        }
        $mount = $library->getMountPoint()->getMountPoint();
        return function (File $file) use ($library, $known, $folders, $others, $mount, &$skipped): bool {
            $path = $this->inLibrary($library, $file);
            if ($path === '' || isset($known[CalibreDb::pathKey($path)])) {
                return false;
            }
            foreach ($others as $other) {
                if (str_starts_with($file->getPath(), $other)) {
                    return false;
                }
            }
            // Shown or not, what was hidden must not end up in a folder that everyone sees
            if (FilenameExtractor::isHidden($path)) {
                $skipped++;
                return false;
            }
            // Extra files of a book that Calibre does not list as a format
            for ($folder = dirname($path); $folder !== '.'; $folder = dirname($folder)) {
                if (isset($folders[CalibreDb::pathKey($folder)])) {
                    $skipped++;
                    return false;
                }
            }
            // Moving a file out of a share or an external storage would take it away from others
            if ($file->getMountPoint()->getMountPoint() !== $mount || !$file->isDeletable() || !$file->isUpdateable()) {
                $skipped++;
                return false;
            }
            return true;
        };
    }

    /** Whether a library is the catalog folder itself, so that books are moved instead of copied. */
    public function isCatalog(string $uid, Folder $library): bool {
        return $this->catalogFolder($uid)?->getId() === $library->getId();
    }

    /**
     * Creates an empty library in a new or empty folder, or in the catalog folder.
     *
     * @param bool $inPlace the user agreed to have the books of the catalog folder moved
     */
    public function create(string $uid, string $path, bool $inPlace = false): Folder {
        if ($inPlace) {
            return $this->createInCatalog($uid);
        }
        $userFolder = $this->rootFolder->getUserFolder($uid);
        $path = '/' . trim(str_replace('\\', '/', $path), '/');
        if ($path === '/' || str_contains($path . '/', '/../') || str_contains($path . '/', '/./')) {
            throw new CalibreException('Choose a folder for the new library');
        }
        $root = rtrim($this->config->getRoot($uid), '/') . '/';
        if ($path . '/' === $root) {
            throw new CalibreException('This is the current library folder. Use the button for the current folder to have its books moved instead of copied');
        }
        if (str_starts_with($path . '/', $root)) {
            throw new CalibreException('Choose the current library folder itself or a folder outside of it, otherwise every book would be listed twice');
        }
        try {
            $folder = $userFolder->get($path);
        } catch (NotFoundException $e) {
            $folder = $userFolder;
            foreach (explode('/', trim($path, '/')) as $part) {
                $folder = $folder->nodeExists($part) ? $folder->get($part) : $folder->newFolder($part);
                if (!$folder instanceof Folder) {
                    throw new CalibreException($path . ' is not a folder');
                }
            }
        }
        if (!$folder instanceof Folder) {
            throw new CalibreException($path . ' is not a folder');
        }
        if ($folder->nodeExists(self::DB)) {
            throw new CalibreException('There already is a Calibre library in ' . $path);
        }
        if ($folder->getDirectoryListing() !== []) {
            throw new CalibreException($path . ' is not empty. Choose a new or empty folder');
        }
        $this->createDb($uid, $folder);
        return $folder;
    }

    /** A library that is there already is kept: books it does not know yet are added to it. */
    private function createInCatalog(string $uid): Folder {
        $folder = $this->catalogFolder($uid);
        if ($folder === null) {
            throw new CalibreException('The library folder does not exist');
        }
        if ($this->config->getRoot($uid) === '/') {
            throw new CalibreException('The library folder is the folder with all your files. Choose a folder with books only as library folder first, otherwise all kinds of documents would be moved');
        }
        if (!$folder->nodeExists(self::DB)) {
            $this->createDb($uid, $folder);
        }
        return $folder;
    }

    private function createDb(string $uid, Folder $folder): void {
        $temp = $this->temp();
        try {
            CalibreDb::create($temp, $this->config->getTimezone($uid))->close();
            $this->upload($folder->newFile(self::DB), $temp);
        } finally {
            $this->cleanup($temp);
        }
    }

    // ---------------------------------------------------------------- backups

    /** @return list<array{name: string, time: int, size: int}> newest first */
    public function backups(string $uid, Folder $library): array {
        $out = [];
        foreach ($this->backupFiles($uid, $library) as $time => $file) {
            $out[] = ['name' => $file->getName(), 'time' => $time, 'size' => (int)$file->getSize()];
        }
        return $out;
    }

    public function backup(string $uid, Folder $library, string $name): ?ISimpleFile {
        foreach ($this->backupFiles($uid, $library) as $file) {
            if ($file->getName() === $name) {
                return $file;
            }
        }
        return null;
    }

    /** @return array<int,ISimpleFile> time => file, newest first */
    private function backupFiles(string $uid, Folder $library): array {
        $prefix = $this->backupPrefix($library);
        $out = [];
        try {
            $folder = $this->appData->get(Application::APP_ID)->getFolder($this->backupFolderName($uid));
        } catch (NotFoundException $e) {
            return [];
        }
        foreach ($folder->getDirectoryListing() as $file) {
            if (preg_match('/^' . $prefix . '-(\d+)\.db$/', $file->getName(), $m)) {
                $out[(int)$m[1]] = $file;
            }
        }
        krsort($out);
        return $out;
    }

    private function makeBackup(string $uid, Folder $library, string $path): void {
        $files = $this->backupFiles($uid, $library);
        $now = time();
        if ($files !== [] && $now - array_key_first($files) < self::BACKUP_INTERVAL) {
            return;
        }
        $data = $this->appData->get(Application::APP_ID);
        try {
            $folder = $data->getFolder($this->backupFolderName($uid));
        } catch (NotFoundException $e) {
            $folder = $data->newFolder($this->backupFolderName($uid));
        }
        $in = fopen($path, 'rb');
        $folder->newFile($this->backupPrefix($library) . '-' . $now . '.db', $in);
        if (is_resource($in)) {
            fclose($in);
        }
        $size = (int)filesize($path);
        $kept = 1;
        foreach ($files as $file) {
            $size += (int)$file->getSize();
            $kept++;
            if ($kept > 2 && ($kept > self::BACKUPS || $size > self::BACKUP_BYTES)) {
                $file->delete();
            }
        }
    }

    private function backupFolderName(string $uid): string {
        return 'calibre-' . hash('sha256', $uid);
    }

    private function backupPrefix(Folder $library): string {
        return 'library' . $library->getId();
    }

    // -------------------------------------------------------------- internals

    /**
     * The frame of every change: lock, copy, change, verify, write back.
     *
     * @template T
     * @param callable(CalibreDb, array): T $fn gets a list to which it adds steps that undo its file changes
     * @param int|null $opfFor book whose metadata.opf is rewritten afterwards
     * @return T
     */
    private function change(string $uid, Folder $library, callable $fn, ?int $opfFor = null): mixed {
        $lock = 'opds/calibre/' . $library->getId();
        try {
            $this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Calibre library');
        } catch (LockedException $e) {
            throw new CalibreException('Another change to this library is still being saved. Try again in a moment');
        }
        $temp = null;
        $undo = [];
        try {
            $dbFile = $this->dbFile($library);
            foreach ([self::DB . '-journal', self::DB . '-wal'] as $name) {
                if ($library->nodeExists($name) && $library->get($name)->getSize() > 0) {
                    throw new CalibreException('Calibre seems to be using this library right now (' . $name . ' exists). Close Calibre and let the files synchronise first');
                }
            }
            $temp = $this->download($dbFile);
            $before = sha1_file($temp);
            $original = $this->temp();
            copy($temp, $original);

            try {
                $db = new CalibreDb($temp, $this->config->getTimezone($uid));
                try {
                    $reason = $this->readOnlyReason($library, $db);
                    if ($reason !== '') {
                        throw new CalibreException($reason);
                    }
                    $result = $fn($db, $undo);
                    $record = $opfFor !== null ? $db->get($opfFor) : null;
                } finally {
                    $db->close();
                }

                $this->makeBackup($uid, $library, $original);
                if ($this->hash($dbFile) !== $before) {
                    throw new CalibreException('The library was changed by someone else at the same time. Nothing was saved; please try again');
                }
                $this->upload($dbFile, $temp);
            } catch (\Throwable $e) {
                $this->rollback($library, $undo);
                throw $e;
            } finally {
                $this->cleanup($original);
            }
            if ($record !== null) {
                $this->writeOpf($library, $record);
            }
            return $result;
        } finally {
            if ($temp !== null) {
                $this->cleanup($temp);
            }
            $this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    /** @param list<callable> $undo */
    private function rollback(Folder $library, array $undo): void {
        foreach (array_reverse($undo) as $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                $this->logger->error('OPDS: could not undo a change in ' . $library->getPath() . ': ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
            }
        }
    }

    /**
     * Renames the folder and the files of a book the way Calibre would.
     *
     * @param list<callable> $undo
     */
    private function relocate(CalibreDb $db, Folder $library, int $id, array &$undo): void {
        $record = $this->record($db, $id);
        if ($record['path'] === '') {
            return;
        }
        $author = $record['authors'][0] ?? 'Unknown';
        $path = $this->adoptAuthorFolder($library, Naming::bookPath($id, $record['title'], $author));
        $longest = $record['formats'] === [] ? 10 : max(array_map('strlen', array_keys($record['formats']))) + 1;
        $name = Naming::fileName($record['title'], $author, $longest);
        $sameFiles = array_filter($record['formats'], static fn (string $n): bool => $n !== $name) === [];
        if ($path === $record['path'] && $sameFiles) {
            return;
        }

        $folder = $this->bookFolder($library, $record);
        if ($path !== $record['path']) {
            if ($library->nodeExists($path)) {
                throw new CalibreException('There already is a folder "' . $path . '" in the library. Rename or remove it first');
            }
            $parent = dirname($path);
            if (!$library->nodeExists($parent)) {
                $library->newFolder($parent);
                $undo[] = static function () use ($library, $parent): void {
                    $node = $library->get($parent);
                    if ($node instanceof Folder && $node->getDirectoryListing() === []) {
                        $node->delete();
                    }
                };
            }
            $oldPath = $record['path'];
            $folder->move($library->getPath() . '/' . $path);
            $undo[] = static function () use ($library, $path, $oldPath): void {
                if (!$library->nodeExists(dirname($oldPath))) {
                    $library->newFolder(dirname($oldPath));
                }
                $library->get($path)->move($library->getPath() . '/' . $oldPath);
            };
            $this->removeIfEmpty($library, dirname($oldPath));
            $folder = $library->get($path);
            if (!$folder instanceof Folder) {
                throw new CalibreException('Could not move the folder of the book');
            }
        }

        $formats = [];
        foreach ($record['formats'] as $format => $oldName) {
            $file = $this->formatFile($folder, $oldName, $format);
            if ($file === null) {
                // Missing from the folder already: Calibre reports it in "Check library"
                $formats[$format] = $oldName;
                continue;
            }
            $formats[$format] = $name;
            $from = $file->getName();
            $to = $name . '.' . strtolower($format);
            if ($from !== $to) {
                if ($folder->nodeExists($to)) {
                    throw new CalibreException('There already is a file "' . $to . '" in the folder of this book');
                }
                $file->move($folder->getPath() . '/' . $to);
                $folderPath = $folder->getPath();
                $undo[] = fn () => $this->rootFolder->get($folderPath . '/' . $to)->move($folderPath . '/' . $from);
            }
        }
        $db->setFiles($id, $path, $formats);
    }

    /** Uses an existing author folder that differs only in capitals, which sync clients on Windows and macOS cannot tell apart. */
    private function adoptAuthorFolder(Folder $library, string $path): string {
        [$author, $rest] = explode('/', $path, 2);
        if ($library->nodeExists($author)) {
            return $path;
        }
        foreach ($library->getDirectoryListing() as $node) {
            if ($node instanceof Folder && mb_strtolower($node->getName()) === mb_strtolower($author)) {
                return $node->getName() . '/' . $rest;
            }
        }
        return $path;
    }

    /**
     * @param array{fields: array<string,mixed>, files: list<File>, cover: ?string, added: int} $book
     * @param list<callable> $undo
     */
    private function addBook(CalibreDb $db, Folder $library, array $book, bool $move, array &$undo): void {
        $fields = $book['fields'];
        $id = $db->add($fields, $move ? $book['added'] : null);
        $author = $fields['authors'][0] ?? 'Unknown';
        $path = $this->adoptAuthorFolder($library, Naming::bookPath($id, $fields['title'], $author));
        $folder = $library;
        $at = '';
        foreach (explode('/', $path) as $part) {
            $at = ltrim($at . '/' . $part, '/');
            if ($folder->nodeExists($part)) {
                $folder = $folder->get($part);
            } else {
                $folder = $folder->newFolder($part);
                $undo[] = fn () => $this->removeIfEmpty($library, $at);
            }
            if (!$folder instanceof Folder) {
                throw new CalibreException('"' . $path . '" cannot be used as a folder');
            }
        }
        $formats = [];
        foreach ($book['files'] as $file) {
            // book.kepub.epub is an EPUB for Calibre, and only one file per format is possible
            $format = strtoupper(FilenameExtractor::format($file->getName()));
            $formats[$format] ??= $file;
        }
        $longest = $formats === [] ? 10 : max(array_map('strlen', array_keys($formats))) + 1;
        $name = Naming::fileName($fields['title'], $author, $longest);
        foreach ($formats as $format => $file) {
            $target = $name . '.' . strtolower($format);
            if ($move) {
                $this->moveInto($folder, $target, $file, $undo);
            } else {
                if ($folder->nodeExists($target)) {
                    // Left over from an interrupted import of the same book
                    $folder->get($target)->delete();
                }
                $file->copy($folder->getPath() . '/' . $target);
            }
            $db->addFormat($id, $format, $name, (int)$file->getSize());
        }
        $db->setFiles($id, $path, []);
        if ($book['cover'] !== null) {
            try {
                $this->writeFile($folder, self::COVER, $this->image->toJpeg($book['cover']), $undo);
                $db->setHasCover($id, true);
            } catch (CalibreException $e) {
                // a damaged cover is no reason to leave out the book
            }
        }
        if (!$folder->nodeExists(self::OPF)) {
            $undo[] = static function () use ($folder): void {
                if ($folder->nodeExists(self::OPF)) {
                    $folder->get(self::OPF)->delete();
                }
            };
        }
        $this->writeOpf($library, $this->record($db, $id));
    }

    /** @param list<callable> $undo */
    private function moveInto(Folder $folder, string $target, File $file, array &$undo): void {
        $from = $file->getPath();
        $to = $folder->getPath() . '/' . $target;
        if ($from === $to) {
            // Moved by an earlier attempt that was cut off before the database was saved
            return;
        }
        if ($folder->nodeExists($target)) {
            throw new CalibreException('There already is a file "' . $target . '" in the folder of this book');
        }
        $file->move($to);
        $undo[] = fn () => $this->rootFolder->get($to)->move($from);
    }

    /** @return array<string,mixed> */
    private function record(CalibreDb $db, int $id): array {
        return $db->get($id) ?? throw new CalibreException('This book is no longer in the Calibre database');
    }

    /** @param array<string,mixed> $record */
    private function bookFolder(Folder $library, array $record): Folder {
        // The path comes from the database: it must not lead out of the library
        $path = trim($record['path'], '/');
        if ($path === '' || in_array('..', explode('/', $path), true)) {
            throw new CalibreException('The Calibre database has no valid folder for this book');
        }
        try {
            $node = $library->get($path);
        } catch (NotFoundException $e) {
            throw new CalibreException('The folder of this book ("' . $path . '") is missing');
        }
        if (!$node instanceof Folder) {
            throw new CalibreException('"' . $path . '" is not a folder');
        }
        return $node;
    }

    private function formatFile(Folder $folder, string $name, string $format): ?File {
        $wanted = $name . '.' . strtolower($format);
        if (str_contains($wanted, '/')) {
            return null;
        }
        if ($folder->nodeExists($wanted)) {
            $node = $folder->get($wanted);
            return $node instanceof File ? $node : null;
        }
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof File && CalibreDb::pathKey($node->getName()) === CalibreDb::pathKey($wanted)) {
                return $node;
            }
        }
        return null;
    }

    private function removeIfEmpty(Folder $library, string $path): void {
        try {
            $node = $path !== '.' && $path !== '' ? $library->get($path) : null;
            if ($node instanceof Folder && $node->getDirectoryListing() === []) {
                $node->delete();
            }
        } catch (NotFoundException $e) {
            // nothing to tidy up
        }
    }

    /**
     * @param string|null $data null deletes the file
     * @param list<callable> $undo
     */
    private function writeFile(Folder $folder, string $name, ?string $data, array &$undo): void {
        $file = $folder->nodeExists($name) ? $folder->get($name) : null;
        $old = $file instanceof File ? $file->getContent() : null;
        $path = $folder->getPath();
        $undo[] = function () use ($path, $name, $old): void {
            $folder = $this->rootFolder->get($path);
            if (!$folder instanceof Folder) {
                return;
            }
            if ($old === null) {
                if ($folder->nodeExists($name)) {
                    $folder->get($name)->delete();
                }
            } elseif ($folder->nodeExists($name)) {
                $folder->get($name)->putContent($old);
            } else {
                $folder->newFile($name, $old);
            }
        };
        if ($data === null) {
            $file?->delete();
        } elseif ($file instanceof File) {
            $file->putContent($data);
        } else {
            $folder->newFile($name, $data);
        }
    }

    /** @param array<string,mixed> $record */
    private function writeOpf(Folder $library, array $record): void {
        try {
            $folder = $this->bookFolder($library, $record);
            $file = $folder->nodeExists(self::OPF) ? $folder->get(self::OPF) : null;
            $xml = Opf::render($record, $file instanceof File ? $file->getContent() : '');
            $file instanceof File ? $file->putContent($xml) : $folder->newFile(self::OPF, $xml);
        } catch (\Throwable $e) {
            // Only a safety copy; Calibre writes it again because the book is marked as changed
            $this->logger->warning('OPDS: could not write metadata.opf: ' . $e->getMessage(), ['app' => Application::APP_ID]);
        }
    }

    private function dbFile(Folder $library): File {
        try {
            $node = $library->get(self::DB);
        } catch (NotFoundException $e) {
            $node = null;
        }
        if (!$node instanceof File) {
            throw new CalibreException('There is no metadata.db in this folder');
        }
        return $node;
    }

    private function temp(): string {
        $path = $this->tempManager->getTemporaryFile('.db');
        if ($path === false) {
            throw new \RuntimeException('Cannot create temporary file');
        }
        return $path;
    }

    private function download(File $file): string {
        if ($file->getSize() > self::MAX_DB_SIZE) {
            throw new CalibreException('metadata.db is too large');
        }
        $path = $this->temp();
        $in = $file->fopen('r');
        $out = fopen($path, 'wb');
        if ($in === false || $out === false) {
            throw new CalibreException('metadata.db cannot be read');
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        return $path;
    }

    private function upload(File $file, string $path): void {
        $in = fopen($path, 'rb');
        if ($in === false) {
            throw new \RuntimeException('Cannot read temporary file');
        }
        try {
            $file->putContent($in);
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }
    }

    private function hash(File $file): string {
        $in = $file->fopen('r');
        if ($in === false) {
            throw new CalibreException('metadata.db cannot be read');
        }
        $ctx = hash_init('sha1');
        hash_update_stream($ctx, $in);
        fclose($in);
        return hash_final($ctx);
    }

    private function cleanup(string $path): void {
        foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
            if (file_exists($path . $suffix)) {
                @unlink($path . $suffix);
            }
        }
    }
}
