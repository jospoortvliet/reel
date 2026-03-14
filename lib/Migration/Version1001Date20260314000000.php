<?php

declare(strict_types=1);

namespace OCA\Reel\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1001Date20260314000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('reel_events');
        if (!$table->hasColumn('motion_style')) {
            $table->addColumn('motion_style', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]);
        }

        return $schema;
    }
}
