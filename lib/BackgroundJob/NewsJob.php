<?php

declare(strict_types=1);

namespace OCA\Opds\BackgroundJob;

use OCA\Opds\AppInfo\Application;
use OCA\Opds\News\NewsService;
use OCA\Opds\Service\Config;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Builds the news edition ahead of time for people who actually download it,
 * so that a reader app gets the cached book immediately instead of waiting
 * for articles and images to be assembled (some readers give up after
 * 15 seconds without data).
 */
class NewsJob extends TimedJob {
    /** Only prepare editions for users who downloaded one within this many days. */
    private const ACTIVE_DAYS = 14;

    public function __construct(
        ITimeFactory $time,
        private IUserManager $userManager,
        private Config $config,
        private NewsService $news,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(15 * 60);
        $this->setTimeSensitivity(IJob::TIME_SENSITIVE);
    }

    protected function run($argument): void {
        $now = $this->time->getTime();
        $this->userManager->callForSeenUsers(function (IUser $user) use ($now): void {
            $uid = $user->getUID();
            $last = (int)$this->config->getUserValueRaw($uid, 'news_last_download');
            if ($last === 0 || $now - $last > self::ACTIVE_DAYS * 86400) {
                return;
            }
            try {
                if ($this->news->isActive($uid)) {
                    $this->news->epub($uid, null, NewsService::BACKGROUND_IMAGE_SECONDS);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('OPDS: could not prepare the news edition for ' . $uid . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
            }
        });
    }
}
