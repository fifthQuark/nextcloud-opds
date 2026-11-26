<?php

declare(strict_types=1);

namespace OCA\Opds\Controller;

use OCA\Opds\AppInfo\Application;
use OCA\Opds\Calibre\CalibreDb;
use OCA\Opds\Calibre\CalibreException;
use OCA\Opds\Calibre\CalibreLibrary;
use OCA\Opds\Calibre\CoverImage;
use OCA\Opds\Calibre\FieldReader;
use OCA\Opds\Calibre\Importer;
use OCA\Opds\Calibre\Sqlite;
use OCA\Opds\Db\BookRepository;
use OCA\Opds\Metadata\MetadataExtractor;
use OCA\Opds\Service\Config;
use OCA\Opds\Service\LibraryScanner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Beta: editing Calibre libraries. Everything except the switch itself is
 * refused unless the user has turned the beta on.
 */
class CalibreController extends Controller {
    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private IRootFolder $rootFolder,
        private Config $config,
        private BookRepository $repository,
        private LibraryScanner $scanner,
        private CalibreLibrary $calibre,
        private FieldReader $fields,
        private Importer $importer,
        private MetadataExtractor $extractor,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function enable(bool $enabled = false): JSONResponse {
        $uid = $this->uid();
        if ($enabled && !Sqlite::available()) {
            return new JSONResponse(['error' => 'This server has no SQLite support for PHP (sqlite3 or pdo_sqlite), which Calibre libraries need'], Http::STATUS_BAD_REQUEST);
        }
        $this->config->setCalibreEnabled($uid, $enabled);
        // Have the next visit take over (or drop) what Calibre knows about the books
        $this->config->setLastScan($uid, 0);
        return new JSONResponse(['enabled' => $enabled]);
    }

    /** Whether downloaded EPUBs get the metadata from Calibre. */
    #[NoAdminRequired]
    public function downloads(bool $embed = true): JSONResponse {
        return $this->guard(function (string $uid) use ($embed): JSONResponse {
            $this->config->setEmbedsMetadata($uid, $embed);
            return new JSONResponse(['embed' => $embed]);
        });
    }

    /**
     * @param array<string,mixed> $fields
     */
    #[NoAdminRequired]
    public function update(int $id, array $fields = [], string $rev = ''): JSONResponse {
        return $this->guard(function (string $uid) use ($id, $fields, $rev): JSONResponse {
            $clean = $this->fields->read($fields);
            [$library, $bookId] = $this->locate($uid, $id, $rev);
            $this->calibre->edit($uid, $library, $bookId, $clean);
            return $this->saved($uid, $library, $bookId);
        });
    }

    #[NoAdminRequired]
    public function setCover(int $id): JSONResponse {
        return $this->guard(function (string $uid) use ($id): JSONResponse {
            $upload = $this->request->getUploadedFile('cover');
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) {
                throw new CalibreException('The image did not arrive. It may be larger than this server allows');
            }
            if ((int)filesize($upload['tmp_name']) > CoverImage::MAX_BYTES) {
                throw new CalibreException('The image is too large (at most 20 MB)');
            }
            [$library, $bookId] = $this->locate($uid, $id);
            $this->calibre->setCover($uid, $library, $bookId, (string)file_get_contents($upload['tmp_name']));
            return $this->saved($uid, $library, $bookId);
        });
    }

    /** Uses the cover inside the book file. */
    #[NoAdminRequired]
    public function coverFromFile(int $id): JSONResponse {
        return $this->guard(function (string $uid) use ($id): JSONResponse {
            [$library, $bookId, $file] = $this->locate($uid, $id);
            $data = $this->extractor->read($file, 'title')->coverData;
            if ($data === null) {
                throw new CalibreException('There is no cover inside this file');
            }
            $this->calibre->setCover($uid, $library, $bookId, $data);
            return $this->saved($uid, $library, $bookId);
        });
    }

    #[NoAdminRequired]
    public function removeCover(int $id): JSONResponse {
        return $this->guard(function (string $uid) use ($id): JSONResponse {
            [$library, $bookId] = $this->locate($uid, $id);
            $this->calibre->setCover($uid, $library, $bookId, null);
            return $this->saved($uid, $library, $bookId);
        });
    }

    /** Names already used in the library, offered while typing. */
    #[NoAdminRequired]
    public function names(int $id): JSONResponse {
        return $this->guard(function (string $uid) use ($id): JSONResponse {
            [$library] = $this->locate($uid, $id);
            return new JSONResponse($this->calibre->read($uid, $library, static fn (CalibreDb $db): array => [
                'authors' => $db->names('authors'),
                'series' => $db->names('series'),
                'tags' => $db->names('tags'),
                'publishers' => $db->names('publishers'),
                'columns' => $db->customNames(),
            ]));
        });
    }

    /**
     * Renames an author, series, genre, publisher or value of a custom column in all Calibre libraries.
     *
     * @param string $kind as in the lists of the book browser: author, series, subject, publisher, column-<key>
     */
    #[NoAdminRequired]
    public function rename(string $kind = '', string $from = '', string $to = ''): JSONResponse {
        return $this->guard(function (string $uid) use ($kind, $from, $to): JSONResponse {
            $from = trim($from);
            $final = null;
            $books = 0;
            $libraries = 0;
            $name = null;
            $skipped = [];
            foreach ($this->calibre->findAll($uid) as $library) {
                $path = $this->calibre->relative($uid, $library);
                try {
                    [$category, $column, $reason] = $this->calibre->read($uid, $library, function (CalibreDb $db) use ($library, $kind, $from): array {
                        $category = $this->category($db, $kind);
                        return $category !== null && $db->has($category, $from)
                            ? [$category, str_starts_with($category, '#') ? $db->column(substr($category, 1)) : null, $this->calibre->readOnlyReason($library, $db)]
                            : [null, null, ''];
                    });
                } catch (CalibreException $e) {
                    $skipped[] = ['library' => $path, 'reason' => $e->getMessage()];
                    continue;
                }
                if ($category === null) {
                    continue;
                }
                // A name that cannot be used stops everything
                $name = $this->fields->newName($category, $to, $column);
                try {
                    if ($reason !== '') {
                        throw new CalibreException($reason);
                    }
                    $result = $this->calibre->rename($uid, $library, $category, $from, $name);
                } catch (CalibreException $e) {
                    $skipped[] = ['library' => $path, 'reason' => $e->getMessage()];
                    continue;
                }
                $final = $result['name'];
                $libraries++;
                $books += count($result['books']);
                try {
                    $this->scanner->refreshCalibreBooks($uid, $library, $result['books']);
                } catch (\Throwable $e) {
                    $this->config->setLastScan($uid, 0);
                    $this->logger->warning('OPDS: could not refresh the index: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
                }
            }
            if ($libraries === 0) {
                throw new CalibreException($name !== null && $skipped !== [] ? end($skipped)['reason']
                    : 'No book in a Calibre library has this name. Only books in Calibre libraries can be renamed here');
            }
            return new JSONResponse(['name' => $final ?? $name, 'books' => $books, 'libraries' => $libraries, 'skipped' => $skipped]);
        });
    }

    /**
     * What the form for changing several books needs: how many of them can be changed, the custom
     * columns of their libraries and the names already used.
     *
     * @param list<int> $ids file ids from the book browser
     */
    #[NoAdminRequired]
    public function bulkForm(array $ids = []): JSONResponse {
        return $this->guard(function (string $uid) use ($ids): JSONResponse {
            [$groups, $outside] = $this->group($uid, $ids);
            $books = 0;
            $columns = [];
            $names = ['authors' => [], 'series' => [], 'tags' => [], 'publishers' => [], 'columns' => []];
            $problems = [];
            foreach ($groups as ['library' => $library, 'paths' => $paths]) {
                $path = $this->calibre->relative($uid, $library);
                try {
                    $this->calibre->read($uid, $library, function (CalibreDb $db) use ($library, $paths, $path, &$books, &$columns, &$names, &$problems): void {
                        $known = $db->filePaths();
                        $ids = [];
                        foreach ($paths as $file) {
                            $id = $known[CalibreDb::pathKey($file)] ?? 0;
                            if ($id > 0) {
                                $ids[$id] = true;
                            }
                        }
                        $reason = $this->calibre->readOnlyReason($library, $db);
                        if ($reason !== '') {
                            $problems[] = ['library' => $path, 'reason' => $reason];
                            return;
                        }
                        $books += count($ids);
                        foreach ($db->columnFields() as $column) {
                            $columns[$column['label']] ??= $column;
                        }
                        foreach (['authors', 'series', 'tags', 'publishers'] as $kind) {
                            $names[$kind] = [...$names[$kind], ...$db->names($kind)];
                        }
                        foreach ($db->customNames() as $label => $values) {
                            $names['columns'][$label] = [...($names['columns'][$label] ?? []), ...$values];
                        }
                    });
                } catch (CalibreException $e) {
                    $problems[] = ['library' => $path, 'reason' => $e->getMessage()];
                }
            }
            $unique = static fn (array $list): array => array_slice(array_values(array_unique($list)), 0, 5000);
            $names = array_map($unique, array_diff_key($names, ['columns' => 0])) + ['columns' => array_map($unique, $names['columns'])];
            return new JSONResponse([
                'books' => $books,
                'outside' => $outside,
                'libraries' => array_map(fn (array $g): string => $this->calibre->relative($uid, $g['library']), array_values($groups)),
                'problems' => $problems,
                'columns' => array_values($columns),
                'names' => $names,
            ]);
        });
    }

    /**
     * Changes several books at once, in all the Calibre libraries they are in.
     *
     * @param list<int> $ids file ids from the book browser, in the order of numbering in a series
     * @param array<string,mixed> $changes see FieldReader::bulk()
     */
    #[NoAdminRequired]
    public function bulk(array $ids = [], array $changes = []): JSONResponse {
        return $this->guard(function (string $uid) use ($ids, $changes): JSONResponse {
            $spec = $this->fields->bulk($changes);
            [$groups, $outside] = $this->group($uid, $ids);
            $books = 0;
            $unknown = 0;
            $skipped = [];
            foreach ($groups as ['library' => $library, 'paths' => $paths]) {
                $path = $this->calibre->relative($uid, $library);
                try {
                    $result = $this->calibre->bulk($uid, $library, $paths, $spec);
                } catch (CalibreException $e) {
                    $skipped[] = ['library' => $path, 'reason' => $e->getMessage()];
                    continue;
                }
                $books += count($result['books']);
                $unknown += $result['unknown'];
                try {
                    $this->scanner->refreshCalibreBooks($uid, $library, $result['books']);
                } catch (\Throwable $e) {
                    $this->config->setLastScan($uid, 0);
                    $this->logger->warning('OPDS: could not refresh the index: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
                }
            }
            if ($groups === []) {
                throw new CalibreException('None of these books is in a Calibre library. Only books in Calibre libraries can be changed here');
            }
            if (count($skipped) === count($groups)) {
                throw new CalibreException(end($skipped)['reason']);
            }
            return new JSONResponse(['books' => $books, 'unknown' => $unknown, 'outside' => $outside, 'skipped' => $skipped]);
        });
    }

    /**
     * The book files of the book browser, by Calibre library.
     *
     * @param list<mixed> $ids file ids
     * @return array{0: array<int, array{library: Folder, paths: list<string>}>, 1: int} by library id, and how many books are not in a library
     */
    private function group(string $uid, array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            throw new CalibreException('Choose at least one book');
        }
        if (count($ids) > 5000) {
            throw new CalibreException('At most 5000 books can be changed at once');
        }
        $userFolder = $this->rootFolder->getUserFolder($uid);
        $groups = [];
        $outside = 0;
        foreach ($ids as $id) {
            $file = null;
            foreach ($this->repository->findByFileId($uid, $id) !== null ? $userFolder->getById($id) : [] as $node) {
                if ($node instanceof File) {
                    $file = $node;
                    break;
                }
            }
            $library = $file !== null ? $this->calibre->findFor($uid, $file) : null;
            if ($file === null || $library === null) {
                $outside++;
                continue;
            }
            $groups[$library->getId()] ??= ['library' => $library, 'paths' => []];
            $groups[$library->getId()]['paths'][] = $this->calibre->inLibrary($library, $file);
        }
        return [$groups, $outside];
    }

    /** The kind of name in Calibre for a list of the book browser. */
    private function category(CalibreDb $db, string $kind): ?string {
        if (str_starts_with($kind, 'column-')) {
            foreach ($db->columns() as $column) {
                if (\OCA\Opds\Db\Book::columnKey($column['label']) === substr($kind, 7) && in_array($column['type'], ['text', 'series'], true)) {
                    return '#' . $column['label'];
                }
            }
            return null;
        }
        return ['author' => 'authors', 'series' => 'series', 'subject' => 'tags', 'publisher' => 'publishers'][$kind] ?? null;
    }

    /** Overview for the settings page. */
    #[NoAdminRequired]
    public function libraries(): JSONResponse {
        return $this->guard(function (string $uid): JSONResponse {
            $out = [];
            foreach ($this->calibre->findAll($uid) as $library) {
                $row = ['path' => $this->calibre->relative($uid, $library), 'books' => 0, 'problem' => '', 'backups' => $this->calibre->backups($uid, $library)];
                try {
                    [$row['books'], $row['problem']] = $this->calibre->read($uid, $library, fn (CalibreDb $db): array => [$db->count(), $this->calibre->readOnlyReason($library, $db)]);
                } catch (CalibreException $e) {
                    $row['problem'] = $e->getMessage();
                }
                $out[] = $row;
            }
            return new JSONResponse(['root' => $this->config->getRoot($uid), 'libraries' => $out]);
        });
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function backup(string $library = '', string $name = ''): Response {
        return $this->guard(function (string $uid) use ($library, $name): Response {
            $folder = $this->folder($uid, $library);
            $file = $this->calibre->backup($uid, $folder, $name);
            if ($file === null) {
                return new JSONResponse(['error' => 'This backup does not exist any more'], Http::STATUS_NOT_FOUND);
            }
            return new DataDownloadResponse($file->getContent(), 'metadata.db', 'application/x-sqlite3');
        });
    }

    /** Creates an empty library and later, batch by batch, copies or moves the books into it. */
    #[NoAdminRequired]
    public function create(string $path = '', bool $inPlace = false): JSONResponse {
        return $this->guard(function (string $uid) use ($path, $inPlace): JSONResponse {
            $folder = $this->calibre->create($uid, $path, $inPlace);
            return new JSONResponse(['path' => $this->calibre->relative($uid, $folder), 'total' => $this->repository->countWorks($uid)]);
        });
    }

    #[NoAdminRequired]
    public function import(string $path = '', string $after = '', bool $inPlace = false): JSONResponse {
        return $this->guard(function (string $uid) use ($path, $after, $inPlace): JSONResponse {
            $library = $this->folder($uid, $path);
            if ($inPlace !== $this->calibre->isCatalog($uid, $library)) {
                throw new CalibreException('The library folder was changed in the settings in the meantime. Reload this page');
            }
            return new JSONResponse($this->importer->batch($uid, $library, $after, 15, $inPlace));
        });
    }

    /** Makes a library the folder that is browsed and served to readers. */
    #[NoAdminRequired]
    public function useLibrary(string $path = ''): JSONResponse {
        return $this->guard(function (string $uid) use ($path): JSONResponse {
            $folder = $this->folder($uid, $path);
            $this->config->setRoot($uid, $this->calibre->relative($uid, $folder));
            $this->scanner->reset($uid);
            return new JSONResponse(['root' => $this->config->getRoot($uid)]);
        });
    }

    // -------------------------------------------------------------- internals

    /**
     * @template T of Response
     * @param callable(string): T $fn
     */
    private function guard(callable $fn): Response {
        $uid = $this->uid();
        if (!$this->config->isCalibreEnabled($uid)) {
            return new JSONResponse(['error' => 'Calibre editing is turned off in the settings'], Http::STATUS_FORBIDDEN);
        }
        try {
            return $fn($uid);
        } catch (CalibreException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\OCP\Files\NotFoundException $e) {
            return new JSONResponse(['error' => 'A file or folder of this book is missing'], Http::STATUS_NOT_FOUND);
        } catch (\OCP\Files\NotPermittedException $e) {
            return new JSONResponse(['error' => 'You may not change these files, or there is no space left'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\Lock\LockedException $e) {
            return new JSONResponse(['error' => 'One of the files is in use right now. Nothing was saved; please try again'], Http::STATUS_CONFLICT);
        } catch (\Throwable $e) {
            $this->logger->error('OPDS: Calibre library: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
            return new JSONResponse(['error' => 'Something went wrong; the change was not saved. Details are in the server log'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /** @return array{0: Folder, 1: int, 2: File} library, id in Calibre, book file */
    private function locate(string $uid, int $fileId, string $rev = ''): array {
        $book = $this->repository->findByFileId($uid, $fileId);
        $file = null;
        foreach ($book !== null ? $this->rootFolder->getUserFolder($uid)->getById($fileId) : [] as $node) {
            if ($node instanceof File) {
                $file = $node;
                break;
            }
        }
        $library = $file !== null ? $this->calibre->findFor($uid, $file) : null;
        if ($file === null || $library === null) {
            throw new CalibreException('This book is not in a Calibre library');
        }
        [$id, $current] = $this->calibre->read($uid, $library, function (CalibreDb $db) use ($library, $file): array {
            $id = $db->filePaths()[CalibreDb::pathKey($this->calibre->inLibrary($library, $file))] ?? 0;
            return [$id, $id > 0 ? ($db->get($id)['rev'] ?? '') : ''];
        });
        if ($id === 0) {
            throw new CalibreException('Calibre does not know this file. Add it in Calibre first');
        }
        if ($rev !== '' && $rev !== $current) {
            throw new CalibreException('This book was changed elsewhere in the meantime. Close and open it again to see the changes');
        }
        return [$library, $id, $file];
    }

    private function saved(string $uid, Folder $library, int $id): JSONResponse {
        try {
            $this->scanner->refreshCalibreBook($uid, $library, $id);
        } catch (\Throwable $e) {
            // Saved all the same; the next scan brings the index up to date
            $this->config->setLastScan($uid, 0);
            $this->logger->warning('OPDS: could not refresh the index: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
        }
        return new JSONResponse(['ok' => true]);
    }

    private function folder(string $uid, string $path): Folder {
        $node = $this->rootFolder->getUserFolder($uid)->get('/' . trim($path, '/'));
        if (!$node instanceof Folder || !$node->nodeExists(CalibreLibrary::DB)) {
            throw new CalibreException('There is no Calibre library in this folder');
        }
        return $node;
    }

    private function uid(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \RuntimeException('Not logged in');
        }
        return $user->getUID();
    }
}
