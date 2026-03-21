<?php

declare(strict_types=1);

namespace OCA\Reel\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds stable identity columns for derived events so rerunning detection updates
 * the same special events instead of duplicating them.
 */
class Version1003Date20260321000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table = $schema->getTable('reel_events');

        if (!$table->hasColumn('event_kind')) {
            $table->addColumn('event_kind', Types::STRING, [
                'notnull' => true,
                'length' => 32,
                'default' => 'timeline',
            ]);
        }

        if (!$table->hasColumn('event_key')) {
            $table->addColumn('event_key', Types::STRING, [
                'notnull' => false,
                'length' => 255,
                'default' => null,
            ]);
        }

        if (!$table->hasIndex('reel_events_user_kind_key_idx')) {
            $table->addIndex(['user_id', 'event_kind', 'event_key'], 'reel_events_user_kind_key_idx');
        }

        return $schema;
    }
}
