<?php

declare(strict_types=1);

namespace OCA\Opds\BackgroundJob;

use OCA\Opds\Service\Config;
use OCA\Opds\Service\LibraryScanner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Keeps every configured library indexed even when no reader has asked for
 * the catalog in a while, so that first requests stay fast.
 */
class ScanJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private IUserManager $userManager,
        private Config $config,
        private LibraryScanner $scanner,
    ) {
        parent::__construct($time);
        $this->setInterval(6 * 3600);
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $this->userManager->callForSeenUsers(function (IUser $user): void {
            $uid = $user->getUID();
            if ($this->config->isConfigured($uid)) {
                // At most ten minutes per user per run; the rest follows next time.
                $this->scanner->scan($uid, 600);
            }
        });
    }
}
