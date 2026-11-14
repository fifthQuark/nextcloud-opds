<?php

declare(strict_types=1);

namespace OCA\Opds\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261114000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('opds_books')) {
            $table = $schema->createTable('opds_books');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('path', Types::STRING, ['notnull' => true, 'length' => 4000]);
            $table->addColumn('parent', Types::STRING, ['notnull' => true, 'length' => 4000]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('etag', Types::STRING, ['notnull' => true, 'length' => 40, 'default' => '']);
            $table->addColumn('mtime', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('mimetype', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('format', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => '']);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 1000, 'default' => '']);
            $table->addColumn('title_sort', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('title_letter', Types::STRING, ['notnull' => true, 'length' => 1, 'default' => '#']);
            $table->addColumn('author', Types::STRING, ['notnull' => true, 'length' => 1000, 'default' => '']);
            $table->addColumn('author_sort', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('publisher', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('series', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('series_index', Types::FLOAT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('subjects', Types::STRING, ['notnull' => true, 'length' => 2000, 'default' => '']);
            $table->addColumn('language', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
            $table->addColumn('pubdate', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
            $table->addColumn('pubyear', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false]);
            $table->addColumn('isbn', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $table->addColumn('has_cover', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('indexed_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'file_id'], 'opds_books_user_file');
            $table->addIndex(['user_id', 'title_sort'], 'opds_books_user_title');
            $table->addIndex(['user_id', 'author_sort'], 'opds_books_user_author');
            $table->addIndex(['user_id', 'mtime'], 'opds_books_user_mtime');
        }

        if (!$schema->hasTable('opds_terms')) {
            $table = $schema->createTable('opds_terms');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('book_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            // author | subject | publisher | series | language
            $table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
            $table->addColumn('value', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('value_sort', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['book_id'], 'opds_terms_book');
            $table->addIndex(['user_id', 'kind', 'value_sort'], 'opds_terms_user_kind');
        }

        return $schema;
    }
}
