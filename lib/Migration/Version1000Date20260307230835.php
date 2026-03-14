<?php

declare(strict_types=1);

namespace OCA\Reel\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial schema: creates reel_events and reel_event_media tables.
 *
 * The version string matches the entry already inserted into oc_migrations for
 * existing installations, so this step is skipped there and only runs on fresh
 * installs.
 */
class Version1000Date20260307230835 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('reel_events')) {
            $table = $schema->createTable('reel_events');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $table->addColumn('title', Types::STRING, [
                'notnull' => false,
                'length'  => 255,
                'default' => null,
            ]);
            $table->addColumn('date_start', Types::INTEGER, [
                'notnull'  => true,
                'unsigned' => true,
            ]);
            $table->addColumn('date_end', Types::INTEGER, [
                'notnull'  => true,
                'unsigned' => true,
            ]);
            $table->addColumn('location', Types::STRING, [
                'notnull' => false,
                'length'  => 255,
                'default' => null,
            ]);
            $table->addColumn('theme', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]);
            $table->addColumn('video_file_id', Types::BIGINT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('created_at', Types::INTEGER, [
                'notnull'  => true,
                'unsigned' => true,
            ]);
            $table->addColumn('updated_at', Types::INTEGER, [
                'notnull'  => true,
                'unsigned' => true,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'reel_events_user_idx');
        }

        if (!$schema->hasTable('reel_event_media')) {
            $table = $schema->createTable('reel_event_media');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            $table->addColumn('event_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $table->addColumn('file_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('included', Types::SMALLINT, [
                'notnull'  => true,
                'unsigned' => true,
                'default'  => 1,
            ]);
            $table->addColumn('sort_order', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('edit_settings', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['event_id'], 'reel_media_event_idx');
            $table->addIndex(['user_id'], 'reel_media_user_idx');
        }

        return $schema;
    }
}
