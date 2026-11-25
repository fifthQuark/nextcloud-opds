<?php

declare(strict_types=1);

namespace OCA\Opds\Settings;

use OCA\Opds\AppInfo\Application;
use OCA\Opds\Calibre\Sqlite;
use OCA\Opds\Db\BookRepository;
use OCA\Opds\News\NewsService;
use OCA\Opds\Service\Columns;
use OCA\Opds\Service\Config;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

class Personal implements ISettings {
    public function __construct(
        private IUserSession $userSession,
        private IURLGenerator $urlGenerator,
        private Config $config,
        private BookRepository $repository,
        private Columns $columns,
        private NewsService $news,
    ) {
    }

    public function getForm(): TemplateResponse {
        $uid = $this->userSession->getUser()?->getUID() ?? '';
        Util::addScript(Application::APP_ID, 'settings');
        Util::addStyle(Application::APP_ID, 'settings');

        $report = null;
        $raw = $this->config->getLastScanReport($uid);
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            $report = is_array($decoded) ? $decoded : null;
        }

        $newsInstalled = false;
        $newsTree = ['folders' => [], 'feeds' => []];
        try {
            $newsInstalled = $this->news->isNewsInstalled($uid);
            if ($newsInstalled) {
                $newsTree = $this->news->tree($uid);
            }
        } catch (\Throwable $e) {
            $newsInstalled = false;
        }

        return new TemplateResponse(Application::APP_ID, 'personal', [
            'settings' => $this->config->getAll($uid),
            'sorts' => $this->columns->sorts($uid) + array_intersect_key(Config::SORTS, [$this->config->getDefaultSort($uid) => true]),
            'feedUrl' => $this->urlGenerator->linkToRouteAbsolute('opds.opds.root'),
            'appPasswordUrl' => $this->urlGenerator->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => 'security']),
            'libraryUrl' => $this->urlGenerator->linkToRoute('opds.library.index'),
            'calibre' => $this->config->isCalibreEnabled($uid) && Sqlite::available(),
            'calibreAvailable' => Sqlite::available(),
            'bookCount' => $this->repository->countWorks($uid),
            'lastScan' => $this->config->getLastScan($uid),
            'report' => $report,
            'userId' => $uid,
            'newsInstalled' => $newsInstalled,
            'newsTree' => $newsTree,
            'news' => $this->config->getNews($uid),
            'newsPeriods' => Config::NEWS_PERIODS,
        ], '');
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 50;
    }
}
