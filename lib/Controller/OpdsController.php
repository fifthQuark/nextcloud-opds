<?php

declare(strict_types=1);

namespace OCA\Opds\Controller;

use OCA\Opds\AppInfo\Application;
use OCA\Opds\Db\Book;
use OCA\Opds\Db\BookFilter;
use OCA\Opds\Db\BookRepository;
use OCA\Opds\Db\Sorting;
use OCA\Opds\News\NewsService;
use OCA\Opds\Service\Config;
use OCA\Opds\Service\CoverService;
use OCA\Opds\Service\FeedBuilder;
use OCA\Opds\Service\LibraryScanner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IPreview;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The OPDS catalog. Every action is a public page that authenticates the
 * request itself: Nextcloud logs the user in from the HTTP Basic credentials
 * (user name + app password) before the controller runs, and if that did not
 * happen we answer 401 with a WWW-Authenticate challenge so that reader apps
 * prompt for credentials.
 */
class OpdsController extends Controller {
    private const GROUP_KINDS = [
        'author' => ['title' => 'Authors', 'one' => 'author', 'many' => 'authors'],
        'title' => ['title' => 'Titles', 'one' => 'title', 'many' => 'titles'],
        'series' => ['title' => 'Series', 'one' => 'series', 'many' => 'series'],
        'subject' => ['title' => 'Genres & subjects', 'one' => 'subject', 'many' => 'subjects'],
        'publisher' => ['title' => 'Publishers', 'one' => 'publisher', 'many' => 'publishers'],
        'language' => ['title' => 'Languages', 'one' => 'language', 'many' => 'languages'],
        'year' => ['title' => 'Publication years', 'one' => 'year', 'many' => 'years'],
        'format' => ['title' => 'Formats', 'one' => 'format', 'many' => 'formats'],
    ];

    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private IRootFolder $rootFolder,
        private IPreview $preview,
        private ITimeFactory $time,
        private Config $config,
        private BookRepository $repository,
        private LibraryScanner $scanner,
        private CoverService $covers,
        private FeedBuilder $feeds,
        private NewsService $news,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    // ----------------------------------------------------------------- feeds

    #[PublicPage]
    #[NoCSRFRequired]
    public function root(): Response {
        $uid = $this->authenticate();
        if ($uid instanceof Response) {
            return $uid;
        }
        $this->refreshIndex($uid);
        $total = $this->repository->count($uid);
        $defaultSort = $this->config->getDefaultSort($uid);
        $defaultOrder = $this->config->getDefaultOrder($uid);

        $entries = [
            $this->nav('all', 'All books', $this->feeds->route('books', ['sort' => $defaultSort, 'order' => $defaultOrder]),
                sprintf('%d books, sorted by %s', $total, strtolower(Config::SORTS[$defaultSort])), FeedBuilder::ACQUISITION),
            $this->nav('recent', 'Recently added', $this->feeds->route('books', ['sort' => 'added', 'order' => 'desc']),
                'Newest additions to the library first', FeedBuilder::ACQUISITION),
            $this->nav('modified', 'Recently modified', $this->feeds->route('books', ['sort' => 'modified', 'order' => 'desc']),
                'Most recently changed files first', FeedBuilder::ACQUISITION),
            $this->nav('newest', 'Newest publications', $this->feeds->route('books', ['sort' => 'date', 'order' => 'desc']),
                'Sorted by publication date, newest first', FeedBuilder::ACQUISITION),
        ];
        foreach (self::GROUP_KINDS as $kind => $labels) {
            $entries[] = $this->nav($kind, $labels['title'], $this->feeds->route('group', ['kind' => $kind]),
                'Browse by ' . $labels['one'], FeedBuilder::NAVIGATION);
        }
        $entries[] = $this->nav('folders', 'Folders', $this->feeds->route('folders'),
            'Browse the library folder structure', FeedBuilder::ACQUISITION);
        if ($this->newsActive($uid)) {
            $entries[] = $this->nav('news', 'News', $this->feeds->route('news'),
                'Today\'s articles from Nextcloud News as an e-book, one part per feed', FeedBuilder::ACQUISITION);
        }

        return $this->feedResponse([
            'id' => 'urn:nextcloud-opds:root',
            'title' => 'OPDS+',
            'subtitle' => $total . ' books in ' . $this->config->getRoot($uid),
            'kind' => FeedBuilder::NAVIGATION,
            'updated' => $this->config->getLastScan($uid) ?: $this->time->getTime(),
            'self' => $this->feeds->route('root'),
            'start' => $this->feeds->route('root'),
            'search' => $this->searchTemplate(),
            'opensearch' => $this->feeds->route('opensearch'),
            'entries' => $entries,
        ]);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function books(
        string $sort = '', string $order = '', int $page = 1,
        string $author = '', string $subject = '', string $publisher = '', string $series = '',
        string $language = '', string $format = '', int $year = 0, string $letter = '',
        string $folder = '', string $q = '', bool $standalone = false,
    ): Response {
        $uid = $this->authenticate();
        if ($uid instanceof Response) {
            return $uid;
        }
        $this->refreshIndex($uid);

        $filter = new BookFilter();
        $filter->author = $author !== '' ? $author : null;
        $filter->subject = $subject !== '' ? $subject : null;
        $filter->publisher = $publisher !== '' ? $publisher : null;
        $filter->series = $series !== '' ? $series : null;
        $filter->standalone = $standalone ? true : null;
        $filter->language = $language !== '' ? $language : null;
        $filter->format = $format !== '' ? $format : null;
        $filter->year = $year > 0 ? $year : null;
        $filter->letter = $letter !== '' ? $letter : null;
        $filter->folder = $folder !== '' ? $folder : null;
        $filter->query = trim($q) !== '' ? trim($q) : null;

        if (!array_key_exists($sort, Config::SORTS)) {
            $sort = ($filter->series !== null || $filter->author !== null) ? 'series' : $this->config->getDefaultSort($uid);
        }
        if ($order !== 'asc' && $order !== 'desc') {
            $order = in_array($sort, ['added', 'modified', 'date'], true) ? 'desc' : $this->config->getDefaultOrder($uid);
        }

        return $this->acquisitionFeed($uid, $filter, $sort, $order, max(1, $page), $this->titleFor($filter), $this->upFor($uid, $filter));
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function search(string $q = '', string $sort = '', string $order = '', int $page = 1): Response {
        return $this->books(sort: $sort, order: $order, page: $page, q: $q);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function group(string $kind, string $letter = '', string $sort = 'name'): Response {
        $uid = $this->authenticate();
        if ($uid instanceof Response) {
            return $uid;
        }
        if (!isset(self::GROUP_KINDS[$kind])) {
            return new DataDisplayResponse('Unknown group', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain']);
        }
        $this->refreshIndex($uid);
        $labels = self::GROUP_KINDS[$kind];
        $self = $this->feeds->route('group', array_filter(['kind' => $kind, 'letter' => $letter, 'sort' => $sort !== 'name' ? $sort : '']));
        $feed = [
            'id' => 'urn:nextcloud-opds:group:' . $kind . ($letter !== '' ? ':' . $letter : ''),
            'title' => $labels['title'] . ($letter !== '' ? ' – ' . $letter : ''),
            'kind' => FeedBuilder::NAVIGATION,
            'updated' => $this->config->getLastScan($uid) ?: $this->time->getTime(),
            'self' => $self,
            'start' => $this->feeds->route('root'),
            'up' => $letter !== '' ? $this->feeds->route('group', ['kind' => $kind]) : $this->feeds->route('root'),
            'search' => $this->searchTemplate(),
            'entries' => [],
        ];

        // Simple column groups
        if ($kind === 'title') {
            foreach ($this->repository->listColumn($uid, 'title_letter') as $row) {
                $feed['entries'][] = $this->nav('letter:' . $row['value'], $row['value'] === '#' ? '# (0-9, symbols)' : $row['value'],
                    $this->feeds->route('books', ['letter' => $row['value'], 'sort' => 'title']), $this->count($row['count'], 'book'), FeedBuilder::ACQUISITION);
            }
            return $this->feedResponse($feed);
        }
        if ($kind === 'year') {
            foreach ($this->repository->listColumn($uid, 'pubyear', null, 'DESC') as $row) {
                $feed['entries'][] = $this->nav('year:' . $row['value'], $row['value'],
                    $this->feeds->route('books', ['year' => $row['value'], 'sort' => 'date']), $this->count($row['count'], 'book'), FeedBuilder::ACQUISITION);
            }
            return $this->feedResponse($feed);
        }
        if ($kind === 'format') {
            foreach ($this->repository->listColumn($uid, 'format') as $row) {
                $feed['entries'][] = $this->nav('format:' . $row['value'], strtoupper($row['value']),
                    $this->feeds->route('books', ['format' => $row['value']]), $this->count($row['count'], 'book'), FeedBuilder::ACQUISITION);
            }
            return $this->feedResponse($feed);
        }
        if ($kind === 'language') {
            foreach ($this->repository->listColumn($uid, 'language') as $row) {
                $feed['entries'][] = $this->nav('language:' . $row['value'], $this->languageName($row['value']),
                    $this->feeds->route('books', ['language' => $row['value']]), $this->count($row['count'], 'book'), FeedBuilder::ACQUISITION);
            }
            return $this->feedResponse($feed);
        }

        // Possibly large term lists: author, subject, series, publisher
        $rows = in_array($kind, ['author', 'subject'], true)
            ? $this->repository->listTerms($uid, $kind, $letter !== '' ? $letter : null)
            : $this->repository->listColumn($uid, $kind, $letter !== '' ? $letter : null);

        if ($letter === '' && count($rows) > $this->config->getGroupThreshold($uid)) {
            $groups = [];
            foreach ($rows as $row) {
                $l = Sorting::letterOf($row['sort']);
                $groups[$l] = ($groups[$l] ?? 0) + 1;
            }
            ksort($groups);
            if (isset($groups['#'])) {
                $hash = $groups['#'];
                unset($groups['#']);
                $groups['#'] = $hash;
            }
            foreach ($groups as $l => $n) {
                $feed['entries'][] = $this->nav($kind . ':letter:' . $l, $l === '#' ? '# (0-9, symbols)' : (string)$l,
                    $this->feeds->route('group', ['kind' => $kind, 'letter' => $l]), $this->count($n, $labels['one'], $labels['many']), FeedBuilder::NAVIGATION);
            }
            return $this->feedResponse($feed);
        }

        if ($sort === 'count') {
            usort($rows, static fn (array $a, array $b): int => [$b['count'], $a['sort']] <=> [$a['count'], $b['sort']]);
        }
        $feed['facets'] = [
            ['group' => 'Sort by', 'title' => 'Name', 'active' => $sort !== 'count', 'href' => $this->feeds->route('group', array_filter(['kind' => $kind, 'letter' => $letter]))],
            ['group' => 'Sort by', 'title' => 'Number of books', 'active' => $sort === 'count', 'href' => $this->feeds->route('group', array_filter(['kind' => $kind, 'letter' => $letter, 'sort' => 'count']))],
        ];
        $bookSort = $kind === 'series' ? 'series' : ($kind === 'author' ? 'series' : 'title');
        $seriesCounts = $kind === 'author' ? $this->repository->countSeriesByAuthor($uid) : [];
        foreach ($rows as $row) {
            $seriesCount = $seriesCounts[$row['value']] ?? 0;
            if ($seriesCount > 0) {
                // The author's books are divided into series one level down
                $feed['entries'][] = $this->nav('author:' . $row['value'], $row['value'], $this->feeds->route('author', ['name' => $row['value']]),
                    $this->count($row['count'], 'book') . ', ' . $this->count($seriesCount, 'series', 'series'), FeedBuilder::NAVIGATION);
                continue;
            }
            $feed['entries'][] = $this->nav($kind . ':' . $row['value'], $row['value'],
                $this->feeds->route('books', [$kind => $row['value'], 'sort' => $bookSort]), $this->count($row['count'], 'book'), FeedBuilder::ACQUISITION);
        }
        return $this->feedResponse($feed);
    }

    /** One author: all books, each of the author's series, and the books outside of any series. */
    #[PublicPage]
    #[NoCSRFRequired]
    public function author(string $name = ''): Response {
        $uid = $this->authenticate();
        if ($uid instanceof Response) {
            return $uid;
        }
        $this->refreshIndex($uid);
        $books = $this->repository->listAuthorSeries($uid, $name);
        if ($name === '' || $books['total'] === 0) {
            return $this->notFound();
        }
        $entries = [
            $this->nav('author-all:' . $name, 'All books', $this->feeds->route('books', ['author' => $name, 'sort' => 'series']),
                $this->count($books['total'], 'book'), FeedBuilder::ACQUISITION),
        ];
        foreach ($books['series'] as $row) {
            $entries[] = $this->nav('author-series:' . $name . ':' . $row['value'], $row['value'],
                $this->feeds->route('books', ['author' => $name, 'series' => $row['value'], 'sort' => 'series']),
                $this->count($row['count'], 'book') . ' in this series', FeedBuilder::ACQUISITION);
        }
        if ($books['standalone'] > 0 && $books['series'] !== []) {
            $entries[] = $this->nav('author-standalone:' . $name, 'Not in a series',
                $this->feeds->route('books', ['author' => $name, 'standalone' => '1', 'sort' => 'title']),
                $this->count($books['standalone'], 'book'), FeedBuilder::ACQUISITION);
        }
        return $this->feedResponse([
            'id' => 'urn:nextcloud-opds:author:' . sha1($name),
            'title' => $name,
            'subtitle' => $this->count($books['total'], 'book') . ', ' . $this->count(count($books['series']), 'series', 'series'),
            'kind' => FeedBuilder::NAVIGATION,
            'updated' => $this->config->getLastScan($uid) ?: $this->time->getTime(),
            'self' => $this->feeds->route('author', ['name' => $name]),
            'start' => $this->feeds->route('root'),
            'up' => $this->feeds->route('group', ['kind' => 'author']),
            'search' => $this->searchTemplate(),
            'entries' => $entries,
        ]);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function folders(string $path = '', string $sort = '', string $order = '', int $page = 1): Response {
        $uid = $this->authenticate();
        if ($uid instanceof Response) {
            return $uid;
        }
        $this->refreshIndex($uid);
        $root = $this->config->getRoot($uid);
        $path = '/' . trim(str_replace('\\', '/', $path), '/');
        if ($path === '/' || $path === '' || !($path === $root || str_starts_with($path, rtrim($root, '/') . '/'))) {
            $path = $root;
        }
        if (str_contains($path, '/../')) {
            $path = $root;
        }

        $filter = new BookFilter();
        $filter->folder = $path;
        if (!array_key_exists($sort, Config::SORTS)) {
            $sort = $this->config->getDefaultSort($uid);
        }
        if ($order !== 'asc' && $order !== 'desc') {
            $order = $this->config->getDefaultOrder($uid);
        }
        $up = $path === $root ? $this->feeds->route('root') : $this->feeds->route('folders', ['path' => dirname($path)]);
        $title = $path === $root ? 'Folders' : basename($path);

        $subfolders = [];
        if ($page <= 1) {
            foreach ($this->repository->listSubfolders($uid, $path) as $sub) {
                $subfolders[] = $this->nav('folder:' . $sub['path'], $sub['name'], $this->feeds->route('folders', ['path' => $sub['path']]),
                    $this->count($sub['count'], 'book'), FeedBuilder::ACQUISITION);
            }
        }
        return $this->acquisitionFeed($uid, $filter, $sort, $order, max(1, $page), $title, $up, $subfolders, 'folders', ['path' => $path]);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function opensearch(): Response {
        $xml = $this->feeds->renderOpenSearch($this->searchTemplate(), $this->feeds->route('opensearch'));
        return new DataDisplayResponse($xml, Http::STATUS_OK, ['Content-Type' => 'application/opensearchdescription+xml; charset=utf-8']);
    }

    // ------------------------------------------------------------------ news

    #[PublicPage]
    #[NoCSRFRequired]
    public function news(): Response {
        $uid = $this->authenticate();
        if ($uid instanceof Response) {
            return $uid;
        }
        if (!$this->newsActive($uid)) {
            return $this->notFound();
        }
        $edition = $this->news->edition($uid);
        $feed = [
            'id' => 'urn:nextcloud-opds:news',
            'title' => 'News',
            'subtitle' => $edition['total'] > 0
                ? $edition['subtitle']
                : 'No articles in the selected feeds for ' . lcfirst($edition['subtitle']),
            'kind' => FeedBuilder::ACQUISITION,
            'updated' => $edition['updated'],
            'self' => $this->feeds->route('news'),
            'start' => $this->feeds->route('root'),
            'up' => $this->feeds->route('root'),
            'entries' => [],
        ];
        if ($edition['total'] > 0) {
            $contents = array_map(static fn (array $p): string => $p['feed']['title'] . ' (' . $p['count'] . ')', $edition['parts']);
            $feed['entries'][] = [
                'type' => 'publication',
                'id' => 'urn:nextcloud-opds:news:' . $edition['key'],
                'title' => $edition['title'],
                'updated' => $edition['updated'],
                'author' => 'Nextcloud News',
                'issued' => $edition['date'],
                'categories' => ['News'],
                'summary' => sprintf("%s\n%s from %s.\n\n%s",
                    $edition['subtitle'],
                    $this->count($edition['total'], 'article'),
                    $this->count(count($edition['parts']), 'feed'),
                    implode("\n", $contents)),
                'download' => $this->feeds->route('newsDownload', ['name' => $edition['fileName']]) . '?v=' . $edition['key'],
                'mime' => 'application/epub+zip',
                'format' => 'epub',
            ];
        }
        return $this->feedResponse($feed);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function newsDownload(string $name = ''): Response {
        $uid = $this->authenticate();
        if ($uid instanceof Response) {
            return $uid;
        }
        if (!$this->newsActive($uid)) {
            return $this->notFound();
        }
        try {
            $edition = $this->news->edition($uid);
            $file = $this->news->epub($uid, $edition);
        } catch (\Throwable $e) {
            $this->logger->error('OPDS: could not build the news edition: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
            return new DataDisplayResponse('The news edition could not be built.', Http::STATUS_INTERNAL_SERVER_ERROR, ['Content-Type' => 'text/plain']);
        }
        $this->config->setUserValueRaw($uid, 'news_last_download', (string)$this->time->getTime());
        if ($file === null) {
            return new DataDisplayResponse('No articles in the selected feeds right now.', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain']);
        }
        $response = new StreamResponse($file->read());
        $response->addHeader('Content-Type', 'application/epub+zip');
        $response->addHeader('Content-Length', (string)$file->getSize());
        $response->addHeader('Content-Disposition', $this->contentDisposition($edition['fileName']));
        $response->addHeader('X-Content-Type-Options', 'nosniff');
        $response->setETag($edition['key']);
        $response->cacheFor(0);
        return $response;
    }

    private function newsActive(string $uid): bool {
        try {
            return $this->news->isActive($uid);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ----------------------------------------------------------------- files

    #[PublicPage]
    #[NoCSRFRequired]
    public function download(int $id, string $name = ''): Response {
        $uid = $this->authenticate();
        if ($uid instanceof Response) {
            return $uid;
        }
        $book = $this->repository->findByFileId($uid, $id);
        $file = $book !== null ? $this->fileOf($uid, $book) : null;
        if ($book === null || $file === null) {
            return $this->notFound();
        }
        $handle = $file->fopen('r');
        if ($handle === false) {
            return $this->notFound();
        }
        $response = new StreamResponse($handle);
        $response->addHeader('Content-Type', FeedBuilder::mimeForFormat($book->format, $file->getMimeType()));
        $response->addHeader('Content-Length', (string)$file->getSize());
        $response->addHeader('Content-Disposition', $this->contentDisposition($file->getName()));
        $response->addHeader('X-Content-Type-Options', 'nosniff');
        $response->setETag($file->getEtag());
        $response->cacheFor(3600);
        return $response;
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function cover(int $id): Response {
        return $this->image($id, false);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function thumbnail(int $id): Response {
        return $this->image($id, true);
    }

    // -------------------------------------------------------------- internals

    /**
     * @return string|Response the user id, or a 401 challenge
     */
    private function authenticate(): string|Response {
        $user = $this->userSession->getUser();
        if ($user !== null) {
            return $user->getUID();
        }
        $response = new DataDisplayResponse(
            "Authentication required.\nSign in with your Nextcloud user name and an app password (Settings > Security > Devices & sessions).\n",
            Http::STATUS_UNAUTHORIZED,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'WWW-Authenticate' => 'Basic realm="Nextcloud OPDS catalog", charset="UTF-8"',
            ]
        );
        return $response;
    }

    private function refreshIndex(string $uid): void {
        try {
            $this->scanner->scanIfStale($uid, 20);
        } catch (\Throwable $e) {
            $this->logger->warning('OPDS: background refresh failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
        }
    }

    /**
     * @param list<array<string,mixed>> $prependEntries
     * @param array<string,string> $routeParams
     */
    private function acquisitionFeed(
        string $uid, BookFilter $filter, string $sort, string $order, int $page, string $title, string $up,
        array $prependEntries = [], string $routeName = 'books', array $routeParams = [],
    ): Response {
        $perPage = $this->config->getPageSize($uid);
        $result = $this->repository->listBooks($uid, $filter, $sort, $order, $perPage, ($page - 1) * $perPage);
        $total = $result['total'];
        $pages = max(1, (int)ceil($total / $perPage));

        $params = $routeName === 'books' ? $filter->toParams() : $routeParams;
        $link = fn (array $extra): string => $this->feeds->route($routeName, array_filter($params + $extra, static fn ($v): bool => $v !== '' && $v !== null));
        $base = ['sort' => $sort, 'order' => $order];

        $facets = [];
        foreach (Config::SORTS as $key => $label) {
            $facets[] = ['group' => 'Sort by', 'title' => $label, 'active' => $key === $sort, 'href' => $link(['sort' => $key, 'order' => $order])];
        }
        $facets[] = ['group' => 'Order', 'title' => 'Ascending', 'active' => $order === 'asc', 'href' => $link(['sort' => $sort, 'order' => 'asc'])];
        $facets[] = ['group' => 'Order', 'title' => 'Descending', 'active' => $order === 'desc', 'href' => $link(['sort' => $sort, 'order' => 'desc'])];

        $entries = $prependEntries;
        foreach ($result['books'] as $book) {
            $entries[] = $this->bookEntry($book);
        }

        $feed = [
            'id' => 'urn:nextcloud-opds:' . $routeName . ':' . sha1(json_encode($params + $base, JSON_THROW_ON_ERROR)),
            'title' => $title,
            'subtitle' => sprintf('%d books · sorted by %s, %s', $total, strtolower(Config::SORTS[$sort]), $order === 'asc' ? 'ascending' : 'descending'),
            'kind' => FeedBuilder::ACQUISITION,
            'updated' => $this->config->getLastScan($uid) ?: $this->time->getTime(),
            'self' => $link($base + ($page > 1 ? ['page' => (string)$page] : [])),
            'start' => $this->feeds->route('root'),
            'up' => $up,
            'search' => $this->searchTemplate(),
            'total' => $total,
            'perPage' => $perPage,
            'startIndex' => ($page - 1) * $perPage + 1,
            'facets' => $facets,
            'entries' => $entries,
        ];
        if ($page > 1) {
            $feed['first'] = $link($base);
            $feed['previous'] = $link($base + ($page > 2 ? ['page' => (string)($page - 1)] : []));
        }
        if ($page < $pages) {
            $feed['next'] = $link($base + ['page' => (string)($page + 1)]);
            $feed['last'] = $link($base + ['page' => (string)$pages]);
        }
        return $this->feedResponse($feed);
    }

    /** @return array<string,mixed> */
    private function bookEntry(Book $book): array {
        $name = $book->name !== '' ? $book->name : 'book.' . $book->format;
        $related = [];
        foreach ($book->authors as $author) {
            $related[] = ['href' => $this->feeds->route('books', ['author' => $author, 'sort' => 'series']), 'title' => 'More by ' . $author];
        }
        if ($book->series !== '') {
            $related[] = ['href' => $this->feeds->route('books', ['series' => $book->series, 'sort' => 'series']), 'title' => 'Series: ' . $book->series];
        }
        return [
            'type' => 'book',
            'book' => $book,
            'download' => $this->feeds->route('download', ['id' => $book->fileId, 'name' => $name]),
            'cover' => $this->feeds->route('cover', ['id' => $book->fileId]) . '?v=' . $book->coverVersion(),
            'thumbnail' => $this->feeds->route('thumbnail', ['id' => $book->fileId]) . '?v=' . $book->coverVersion(),
            'related' => $related,
        ];
    }

    /** @return array<string,mixed> */
    private function nav(string $id, string $title, string $href, string $summary, string $linkKind): array {
        return [
            'type' => 'nav',
            'id' => 'urn:nextcloud-opds:nav:' . $id,
            'title' => $title,
            'href' => $href,
            'summary' => $summary,
            'linkKind' => $linkKind,
            'updated' => $this->time->getTime(),
        ];
    }

    private function titleFor(BookFilter $f): string {
        if ($f->query !== null) {
            return 'Search: ' . $f->query;
        }
        $parts = [];
        if ($f->author !== null) {
            $parts[] = 'by ' . $f->author;
        }
        if ($f->series !== null) {
            $parts[] = 'in ' . $f->series;
        }
        if ($f->standalone === true) {
            $parts[] = 'not in a series';
        }
        if ($f->subject !== null) {
            $parts[] = 'about ' . $f->subject;
        }
        if ($f->publisher !== null) {
            $parts[] = 'published by ' . $f->publisher;
        }
        if ($f->language !== null) {
            $parts[] = 'in ' . $this->languageName($f->language);
        }
        if ($f->format !== null) {
            $parts[] = 'as ' . strtoupper($f->format);
        }
        if ($f->year !== null) {
            $parts[] = 'from ' . $f->year;
        }
        if ($f->letter !== null) {
            $parts[] = 'starting with ' . Sorting::letterOf($f->letter);
        }
        if ($f->folder !== null) {
            $parts[] = 'in folder ' . $f->folder;
        }
        return $parts === [] ? 'All books' : 'Books ' . implode(', ', $parts);
    }

    private function upFor(string $uid, BookFilter $f): string {
        if ($f->author !== null && $this->repository->listAuthorSeries($uid, $f->author)['series'] !== []) {
            return $this->feeds->route('author', ['name' => $f->author]);
        }
        foreach (['author', 'subject', 'publisher', 'series', 'language', 'format'] as $kind) {
            if ($f->{$kind} !== null) {
                return $this->feeds->route('group', ['kind' => $kind]);
            }
        }
        if ($f->year !== null) {
            return $this->feeds->route('group', ['kind' => 'year']);
        }
        if ($f->letter !== null) {
            return $this->feeds->route('group', ['kind' => 'title']);
        }
        if ($f->folder !== null) {
            return $this->feeds->route('folders', ['path' => $f->folder]);
        }
        return $this->feeds->route('root');
    }

    private function image(int $id, bool $thumbnail): Response {
        $uid = $this->authenticate();
        if ($uid instanceof Response) {
            return $uid;
        }
        $book = $this->repository->findByFileId($uid, $id);
        if ($book === null) {
            return $this->notFound();
        }
        $stored = $this->covers->get($uid, $id, $thumbnail);
        if ($stored !== null) {
            $data = $stored->getContent();
            $mime = \OCA\Opds\Metadata\Text::sniffImageMime($data) ?: 'image/jpeg';
            return $this->imageResponse($data, $mime, $book->coverVersion() . ($thumbnail ? 't' : 'c'));
        }
        // Fall back to Nextcloud's own preview generator (PDF, images, ...)
        $file = $this->fileOf($uid, $book);
        if ($file !== null) {
            try {
                if ($this->preview->isAvailable($file)) {
                    [$w, $h] = $thumbnail ? [320, 480] : [1000, 1500];
                    $preview = $this->preview->getPreview($file, $w, $h, false);
                    return $this->imageResponse($preview->getContent(), $preview->getMimeType() ?: 'image/jpeg', $book->coverVersion() . ($thumbnail ? 't' : 'c'));
                }
            } catch (\Throwable $e) {
                $this->logger->debug('OPDS: preview failed for ' . $book->path . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
            }
        }
        return $this->notFound();
    }

    private function imageResponse(string $data, string $mime, string $etag): Response {
        $response = new DataDisplayResponse($data, Http::STATUS_OK, ['Content-Type' => $mime]);
        $response->setETag($etag);
        $response->cacheFor(7 * 86400);
        return $response;
    }

    private function fileOf(string $uid, Book $book): ?File {
        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            foreach ($userFolder->getById($book->fileId) as $node) {
                if ($node instanceof File) {
                    return $node;
                }
            }
            $node = $userFolder->get($book->path);
            return $node instanceof File ? $node : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @param array<string,mixed> $feed */
    private function feedResponse(array $feed): Response {
        $xml = $this->feeds->render($feed);
        $type = $feed['kind'] === FeedBuilder::NAVIGATION ? FeedBuilder::TYPE_NAV : FeedBuilder::TYPE_ACQ;
        $response = new DataDisplayResponse($xml, Http::STATUS_OK, ['Content-Type' => $type . ';charset=utf-8']);
        $response->cacheFor(0);
        return $response;
    }

    private function notFound(): Response {
        return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain']);
    }

    private function searchTemplate(): string {
        // The literal {searchTerms} placeholder must survive URL generation
        return str_replace('__TERMS__', '{searchTerms}', $this->feeds->route('search', ['q' => '__TERMS__']));
    }

    private function count(int $n, string $singular, ?string $plural = null): string {
        return $n . ' ' . ($n === 1 ? $singular : ($plural ?? $singular . 's'));
    }

    private function languageName(string $code): string {
        $names = [
            'en' => 'English', 'de' => 'German', 'fr' => 'French', 'es' => 'Spanish', 'it' => 'Italian', 'pt' => 'Portuguese',
            'nl' => 'Dutch', 'ru' => 'Russian', 'pl' => 'Polish', 'sv' => 'Swedish', 'da' => 'Danish', 'no' => 'Norwegian',
            'fi' => 'Finnish', 'cs' => 'Czech', 'hu' => 'Hungarian', 'tr' => 'Turkish', 'el' => 'Greek', 'ja' => 'Japanese',
            'zh' => 'Chinese', 'ko' => 'Korean', 'ar' => 'Arabic', 'he' => 'Hebrew', 'uk' => 'Ukrainian', 'ro' => 'Romanian',
            'bg' => 'Bulgarian', 'hr' => 'Croatian', 'sk' => 'Slovak', 'sl' => 'Slovenian', 'ca' => 'Catalan', 'eu' => 'Basque',
            'gl' => 'Galician', 'la' => 'Latin', 'hi' => 'Hindi', 'id' => 'Indonesian', 'vi' => 'Vietnamese', 'th' => 'Thai',
            'fa' => 'Persian', 'eo' => 'Esperanto', 'lt' => 'Lithuanian', 'lv' => 'Latvian', 'et' => 'Estonian',
        ];
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayLanguage($code, 'en');
            if ($name !== '' && strtolower($name) !== strtolower($code)) {
                return $name . ' (' . $code . ')';
            }
        }
        $base = strtolower(explode('-', $code)[0]);
        return isset($names[$base]) ? $names[$base] . ' (' . $code . ')' : $code;
    }

    private function contentDisposition(string $fileName): string {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $fileName) ?? 'book';
        $ascii = str_replace(['"', '\\'], '_', $ascii);
        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($fileName);
    }
}
