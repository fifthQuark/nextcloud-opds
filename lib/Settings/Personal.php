<?php

declare(strict_types=1);

namespace OCA\Opds\Settings;

use OCA\Opds\AppInfo\Application;
use OCA\Opds\Db\BookRepository;
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

        return new TemplateResponse(Application::APP_ID, 'personal', [
            'settings' => $this->config->getAll($uid),
            'sorts' => Config::SORTS,
            'feedUrl' => $this->urlGenerator->linkToRouteAbsolute('opds.opds.root'),
            'appPasswordUrl' => $this->urlGenerator->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => 'security']),
            'bookCount' => $this->repository->count($uid),
            'lastScan' => $this->config->getLastScan($uid),
            'report' => $report,
            'userId' => $uid,
        ], '');
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 50;
    }
}
