<?php

declare(strict_types=1);

namespace OCA\Opds\Migration;

use Closure;
use OCA\Opds\Db\BookRepository;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Books indexed before 1.2.0 may list several people as one author. Separate them
 * from the stored values, so that the library does not have to be read again.
 */
class Version1200Date20261124000000 extends SimpleMigrationStep {
    public function __construct(
        private BookRepository $repository,
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $changed = $this->repository->splitStoredAuthors();
        if ($changed > 0) {
            $output->info('Separated the authors of ' . $changed . ' books');
        }
    }
}
