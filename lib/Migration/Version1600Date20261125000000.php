<?php

declare(strict_types=1);

namespace OCA\Opds\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Columns for what else Calibre stores about a book. They are filled by the next
 * scan, because the revisions of Calibre records have changed as well.
 */
class Version1600Date20261125000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table = $schema->getTable('opds_books');
        if (!$table->hasColumn('rating')) {
            $table->addColumn('rating', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
        }
        if (!$table->hasColumn('extra')) {
            $table->addColumn('extra', Types::TEXT, ['notnull' => false]);
        }
        return $schema;
    }
}
