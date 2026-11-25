<?php

declare(strict_types=1);

namespace OCA\Opds\Migration;

use Closure;
use OCA\Opds\Db\BookRepository;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Columns for the book browser: "work" ties the formats of one book together,
 * "meta_rev" tells which revision of a Calibre record a book was indexed from.
 */
class Version1300Date20261125000000 extends SimpleMigrationStep {
    public function __construct(
        private BookRepository $repository,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table = $schema->getTable('opds_books');
        if (!$table->hasColumn('work')) {
            $table->addColumn('work', Types::STRING, ['notnull' => false, 'length' => 40]);
            $table->addIndex(['user_id', 'work'], 'opds_books_user_work');
        }
        if (!$table->hasColumn('meta_rev')) {
            $table->addColumn('meta_rev', Types::STRING, ['notnull' => false, 'length' => 64]);
        }
        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $count = $this->repository->fillWorks();
        if ($count > 0) {
            $output->info('OPDS: prepared ' . $count . ' books for the book browser');
        }
    }
}
