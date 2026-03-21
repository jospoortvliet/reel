<?php

declare(strict_types=1);

namespace OCA\Reel\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1004Date20260321120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('reel_events')) {
            return $schema;
        }

        $table = $schema->getTable('reel_events');

        if (!$table->hasColumn('parent_event_id')) {
            $table->addColumn('parent_event_id', Types::BIGINT, [
                'notnull' => false,
                'default' => null,
                'unsigned' => true,
            ]);
        }

        if (!$table->hasIndex('reel_events_user_parent_idx')) {
            $table->addIndex(['user_id', 'parent_event_id'], 'reel_events_user_parent_idx');
        }

        return $schema;
    }
}
