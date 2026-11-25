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
            ]));
        });
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
