<?php

declare(strict_types=1);

namespace OCA\Opds\Command;

use OCA\Opds\Service\Config;
use OCA\Opds\Service\LibraryScanner;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Scan extends Command {
    public function __construct(
        private IUserManager $userManager,
        private Config $config,
        private LibraryScanner $scanner,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('opds:scan')
            ->setDescription('Index the OPDS library folder of one or all users')
            ->addArgument('user', InputArgument::OPTIONAL, 'User id (all users with a configured library when omitted)')
            ->addOption('full', 'f', InputOption::VALUE_NONE, 'Re-read the metadata of every file, not only new or changed ones')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Drop the index and covers of the user(s) before scanning');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $uid = $input->getArgument('user');
        $full = (bool)$input->getOption('full');
        $reset = (bool)$input->getOption('reset');

        if (is_string($uid) && $uid !== '') {
            $user = $this->userManager->get($uid);
            if ($user === null) {
                $output->writeln('<error>User ' . $uid . ' does not exist</error>');
                return 1;
            }
            return $this->scanUser($user->getUID(), $full, $reset, $output);
        }

        $status = 0;
        $this->userManager->callForSeenUsers(function (IUser $user) use ($full, $reset, $output, &$status): void {
            if ($this->config->isConfigured($user->getUID())) {
                $status |= $this->scanUser($user->getUID(), $full, $reset, $output);
            }
        });
        return $status;
    }

    private function scanUser(string $uid, bool $full, bool $reset, OutputInterface $output): int {
        if ($reset) {
            $this->scanner->reset($uid);
        }
        $output->write($uid . ' (' . $this->config->getRoot($uid) . '): ');
        $result = $this->scanner->scan($uid, null, $full);
        if ($result->error !== '') {
            $output->writeln('<error>' . $result->summary() . '</error>');
            return 1;
        }
        $output->writeln($result->summary());
        return 0;
    }
}
