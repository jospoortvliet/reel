<?php

declare(strict_types=1);

namespace OCA\Reel\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * - Creates reel_jobs if it does not exist yet (for installs that pre-date this
 *   migration and had the table created out-of-band).
 * - Adds composite indexes for the hot query paths.
 */
class Version1002Date20260315000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ------------------------------------------------------------------ //
        // reel_jobs — create on fresh installs                                //
        // ------------------------------------------------------------------ //
        if (!$schema->hasTable('reel_jobs')) {
            $table = $schema->createTable('reel_jobs');
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
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
            ]);
            $table->addColumn('progress', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('error', Types::TEXT, [
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
            // Composite index covering the two key queries:
            //   getLatestForEvent:  WHERE event_id=? AND user_id=? ORDER BY created_at DESC LIMIT 1
            //   getLatestForEvents: WHERE event_id IN(?) AND user_id=? ORDER BY created_at DESC
            $table->addIndex(['event_id', 'user_id', 'created_at'], 'reel_jobs_event_user_ts_idx');
        } else {
            // Table already exists — add the index if missing
            $table = $schema->getTable('reel_jobs');
            if (!$table->hasIndex('reel_jobs_event_user_ts_idx')) {
                $table->addIndex(['event_id', 'user_id', 'created_at'], 'reel_jobs_event_user_ts_idx');
            }
        }

        // ------------------------------------------------------------------ //
        // reel_events — composite (user_id, date_start) covering listEvents   //
        //   WHERE user_id=? GROUP BY id ORDER BY date_start DESC              //
        // ------------------------------------------------------------------ //
        $eventsTable = $schema->getTable('reel_events');
        if (!$eventsTable->hasIndex('reel_events_user_start_idx')) {
            $eventsTable->addIndex(['user_id', 'date_start'], 'reel_events_user_start_idx');
        }

        // ------------------------------------------------------------------ //
        // reel_event_media — composite indexes for the two main access patterns//
        //   (event_id, user_id)          — fetchMedia / syncEventMedia        //
        //   (event_id, included, sort_order) — cover photo batch in listEvents //
        // ------------------------------------------------------------------ //
        $mediaTable = $schema->getTable('reel_event_media');
        if (!$mediaTable->hasIndex('reel_media_event_user_idx')) {
            $mediaTable->addIndex(['event_id', 'user_id'], 'reel_media_event_user_idx');
        }
        if (!$mediaTable->hasIndex('reel_media_event_incl_idx')) {
            $mediaTable->addIndex(['event_id', 'included', 'sort_order'], 'reel_media_event_incl_idx');
        }

        return $schema;
    }
}
