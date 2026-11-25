<?php

declare(strict_types=1);

namespace OCA\Opds\Controller;

use OCA\Opds\AppInfo\Application;
use OCA\Opds\Calibre\CalibreDb;
use OCA\Opds\Calibre\CalibreLibrary;
use OCA\Opds\Calibre\Languages;
use OCA\Opds\Calibre\Sqlite;
use OCA\Opds\Db\Book;
use OCA\Opds\Db\BookFilter;
use OCA\Opds\Db\BookRepository;
use OCA\Opds\Service\Columns;
use OCA\Opds\Service\Config;
use OCA\Opds\Service\LibraryScanner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * The book browser inside Nextcloud (regular session + CSRF protected).
 */
class LibraryController extends Controller {
    private const PAGE_SIZE = 60;
    private const KINDS = ['author', 'series', 'subject', 'publisher', 'language', 'year', 'format', 'rating'];
    /** Kinds of lists for custom columns start with this */
    private const COLUMN = 'column-';

    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private IRootFolder $rootFolder,
        private IURLGenerator $urls,
        private IInitialState $initialState,
        private Config $config,
        private BookRepository $repository,
        private Columns $columns,
        private LibraryScanner $scanner,
        private CalibreLibrary $calibre,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        $uid = $this->uid();
        Util::addScript(Application::APP_ID, 'library');
        Util::addStyle(Application::APP_ID, 'library');
        $this->initialState->provideInitialState('library', [
            'root' => $this->config->getRoot($uid),
            'total' => $this->repository->countWorks($uid),
            'newestFirst' => Config::NEWEST_FIRST,
            'sort' => $this->config->getDefaultSort($uid),
            'order' => $this->config->getDefaultOrder($uid),
            'pageSize' => self::PAGE_SIZE,
            'settingsUrl' => $this->urls->linkToRoute('settings.PersonalSettings.index', ['section' => Application::APP_ID]),
            'calibre' => $this->config->isCalibreEnabled($uid) && Sqlite::available(),
            'languages' => Languages::names(),
        ] + $this->lists($uid));
        return new TemplateResponse(Application::APP_ID, 'library');
    }

    #[NoAdminRequired]
    public function books(
        string $sort = '', string $order = '', int $page = 1,
        string $author = '', string $subject = '', string $publisher = '', string $series = '',
        string $language = '', string $format = '', int $year = 0, string $q = '', bool $standalone = false,
        int $rating = 0, string $column = '', string $value = '',
    ): JSONResponse {
        $uid = $this->uid();
        $filter = new BookFilter();
        $filter->author = $author !== '' ? $author : null;
        $filter->subject = $subject !== '' ? $subject : null;
        $filter->publisher = $publisher !== '' ? $publisher : null;
        $filter->series = $series !== '' ? $series : null;
        $filter->standalone = $standalone ? true : null;
        $filter->language = $language !== '' ? $language : null;
        $filter->format = $format !== '' ? $format : null;
        $filter->year = $year > 0 ? $year : null;
        $filter->query = trim($q) !== '' ? trim($q) : null;
        $filter->rating = $rating > 0 ? $rating : null;
        if ($column !== '' && $value !== '') {
            $filter->column = $column;
            $filter->value = $value;
        }
        if (!array_key_exists($sort, Config::SORTS)) {
            $sort = ($filter->series !== null || $filter->author !== null) ? 'series' : $this->config->getDefaultSort($uid);
        }
        if ($order !== 'asc' && $order !== 'desc') {
            $order = in_array($sort, Config::NEWEST_FIRST, true) ? 'desc' : $this->config->getDefaultOrder($uid);
        }
        $page = max(1, $page);
        $result = $this->repository->listWorks($uid, $filter, $sort, $order, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);
        return new JSONResponse([
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int)ceil($result['total'] / self::PAGE_SIZE)),
            'sort' => $sort,
            'order' => $order,
            'books' => array_map(fn (array $books): array => $this->card($books), $result['works']),
        ]);
    }

    #[NoAdminRequired]
    public function browse(string $kind): JSONResponse {
        $uid = $this->uid();
        $column = str_starts_with($kind, self::COLUMN) ? $this->columns->find($uid, substr($kind, strlen(self::COLUMN))) : null;
        if ($column === null && !in_array($kind, self::KINDS, true)) {
            return new JSONResponse(['error' => 'Unknown list'], Http::STATUS_NOT_FOUND);
        }
        $rows = $this->repository->browse($uid, match (true) {
            $column !== null => '#' . $column['key'],
            $kind === 'year' => 'pubyear',
            default => $kind,
        });
        return new JSONResponse([
            'entries' => array_map(fn (array $row): array => $this->entry($row, $column['type'] ?? $kind), $rows),
            'nested' => $column !== null ? $column['hierarchy'] : $kind === 'subject' && $this->columns->nestedSubjects($uid),
        ]);
    }

    /** Series of one author and the number of books outside of any series. */
    #[NoAdminRequired]
    public function author(string $name = ''): JSONResponse {
        $uid = $this->uid();
        $filter = new BookFilter();
        $filter->author = $name;
        if ($name === '') {
            return new JSONResponse(['error' => 'Unknown author'], Http::STATUS_NOT_FOUND);
        }
        $series = $this->repository->browse($uid, 'series', $filter);
        $total = $this->repository->listWorks($uid, $filter, 'title', 'asc', 1, 0)['total'];
        $filter->standalone = true;
        $standalone = $this->repository->listWorks($uid, $filter, 'title', 'asc', 1, 0)['total'];
        return new JSONResponse([
            'total' => $total,
            'standalone' => $standalone,
            'series' => array_map(fn (array $row): array => $this->entry($row, 'series'), $series),
        ]);
    }

    #[NoAdminRequired]
    public function book(int $id): JSONResponse {
        $uid = $this->uid();
        $book = $this->repository->findByFileId($uid, $id);
        if ($book === null) {
            return new JSONResponse(['error' => 'This book is not in the library any more'], Http::STATUS_NOT_FOUND);
        }
        $books = $this->repository->findWorks($uid, [$book->work()])[$book->work()] ?? [$book];
        $main = $books[0];
        return new JSONResponse($this->card($books) + [
            'publisher' => $main->publisher,
            'subjects' => $main->subjects,
            'languages' => array_map(fn (string $code): array => ['code' => $code, 'name' => $this->languageName($code)], $main->allLanguages()),
            'pubdate' => $main->pubdate,
            'description' => $main->description,
            'isbn' => $main->isbn,
            'rating' => $main->rating,
            'identifiers' => array_map(static fn ($type, string $value): string => $type . ':' . $value,
                array_keys(array_diff_key($main->identifiers, ['isbn' => 1])), array_values(array_diff_key($main->identifiers, ['isbn' => 1]))),
            'columns' => array_map(static fn (array $c): array => [
                'key' => $c['key'],
                'name' => $c['name'],
                'type' => $c['type'],
                'text' => Columns::label($c['type'], $c['text']),
                'values' => in_array($c['type'], Book::BROWSABLE, true)
                    ? array_map(static fn (string $v): array => ['value' => $v, 'label' => Columns::label($c['type'], $v)], $c['values']) : [],
            ], $main->custom),
            'folder' => $main->parent,
            'added' => $main->indexedAt,
            'calibre' => $this->calibreInfo($uid, $main),
        ]);
    }

    /** Looks for new and changed files unless that happened recently. */
    #[NoAdminRequired]
    public function refresh(bool $force = false): JSONResponse {
        $uid = $this->uid();
        $result = $force ? $this->scanner->scan($uid, 25) : $this->scanner->scanIfStale($uid, 25);
        if ($result === null) {
            return new JSONResponse(['changed' => false, 'complete' => true, 'error' => '']);
        }
        return new JSONResponse([
            'changed' => $result->added + $result->updated + $result->removed > 0,
            'complete' => $result->complete,
            'error' => $result->error,
            'summary' => $result->summary(),
            'total' => $this->repository->countWorks($uid),
        ] + $this->lists($uid));
    }

    // -------------------------------------------------------------- internals

    /**
     * @param non-empty-list<Book> $books the formats of one book
     * @return array<string,mixed>
     */
    private function card(array $books): array {
        $main = $books[0];
        $cover = null;
        foreach ($books as $book) {
            if ($book->hasCover) {
                $cover = $book;
                break;
            }
        }
        return [
            'id' => $main->fileId,
            'title' => $main->title,
            'authors' => $main->authors,
            'series' => $main->series,
            'seriesIndex' => $main->seriesIndex,
            'year' => $main->pubyear,
            'thumb' => $cover !== null ? $this->coverUrl($cover, 'thumbnail') : '',
            'cover' => $cover !== null ? $this->coverUrl($cover, 'cover') : '',
            'formats' => array_map(fn (Book $b): array => [
                'id' => $b->fileId,
                'format' => str_ends_with(strtolower($b->name), '.kepub.epub') ? 'kepub' : $b->format,
                'size' => $b->size,
                'name' => $b->name,
                'url' => $this->urls->linkToRoute('opds.opds.download', ['id' => $b->fileId, 'name' => $b->name]),
            ], $books),
        ];
    }

    private function coverUrl(Book $book, string $route): string {
        return $this->urls->linkToRoute('opds.opds.' . $route, ['id' => $book->fileId]) . '?v=' . $book->coverVersion();
    }

    /**
     * @param array{value: string, sort: string, count: int, covers: list<Book>} $row
     * @return array<string,mixed>
     */
    private function entry(array $row, string $kind): array {
        return [
            'value' => $row['value'],
            'label' => match ($kind) {
                'language' => $this->languageName($row['value']),
                'format' => strtoupper($row['value']),
                'rating' => Columns::label('rating', $row['value']),
                default => $row['value'],
            },
            'sort' => $row['sort'],
            'count' => $row['count'],
            'covers' => array_map(fn (Book $b): string => $this->coverUrl($b, 'thumbnail'), $row['covers']),
        ];
    }

    /**
     * What depends on the books that are there.
     *
     * @return array<string,mixed>
     */
    private function lists(string $uid): array {
        return [
            'sorts' => $this->columns->sorts($uid),
            'rated' => $this->repository->hasRatings($uid),
            'columns' => array_map(static fn (array $c): array => ['kind' => self::COLUMN . $c['key'], 'name' => $c['name']], $this->columns->browsable($uid)),
        ];
    }

    private function languageName(string $code): string {
        if ($code === '') {
            return '';
        }
        $calibre = Languages::toCalibre($code);
        if ($calibre === null) {
            return $code;
        }
        // "en" and "en-GB" are separate entries and must not look the same
        return Languages::name($calibre) . (str_contains($code, '-') ? ' (' . $code . ')' : '');
    }

    /** @return array<string,mixed>|null null when the book is not part of a Calibre library */
    private function calibreInfo(string $uid, Book $book): ?array {
        if (!$this->config->isCalibreEnabled($uid) || !Sqlite::available()) {
            return null;
        }
        try {
            $file = $this->rootFolder->getUserFolder($uid)->get($book->path);
            $library = $file instanceof File ? $this->calibre->findFor($uid, $file) : null;
            if ($library === null) {
                return null;
            }
            return $this->calibre->read($uid, $library, function (CalibreDb $db) use ($uid, $library, $file): ?array {
                $id = $db->filePaths()[CalibreDb::pathKey($this->calibre->inLibrary($library, $file))] ?? 0;
                $record = $id > 0 ? $db->get($id) : null;
                if ($record === null) {
                    return null;
                }
                return [
                    'id' => $id,
                    'library' => $this->calibre->relative($uid, $library),
                    'readOnly' => $this->calibre->readOnlyReason($library, $db),
                    'formatted' => $record['comments'] !== '' && preg_match('/<(?!\/?(p|br|div)\b)[a-z]/i', $record['comments']) === 1,
                    'comments' => \OCA\Opds\Metadata\Text::plain($record['comments'], 100000),
                    'languageNames' => array_map(static fn (string $c): string => Languages::name($c), $record['languages']),
                ] + array_intersect_key($record, array_flip(['rev', 'title', 'sort', 'authors', 'author_sort', 'series', 'series_index', 'tags', 'publisher', 'pubdate', 'rating', 'identifiers', 'has_cover', 'path']));
            });
        } catch (\Throwable $e) {
            $this->logger->warning('OPDS: cannot read the Calibre record of ' . $book->path . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
            return null;
        }
    }

    private function uid(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \RuntimeException('Not logged in');
        }
        return $user->getUID();
    }
}
