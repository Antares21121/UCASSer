<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// Adds the shared-drive link metadata columns to the resource directory.
// The campus extension mirrors these columns when its migration runs first on
// an already-installed site; both sides guard on column existence so the
// order of extension enablement never matters.
return [
    'up' => function (Builder $schema): void {
        $db = $schema->getConnection();
        if (!$db->getSchemaBuilder()->hasTable('ucasser_resources')) return;
        foreach ([['resource_type', 40], ['access_code', 80], ['external_key', 80]] as [$column, $length]) {
            if (!$db->getSchemaBuilder()->hasColumn('ucasser_resources', $column)) {
                $schema->table('ucasser_resources', function (Blueprint $table) use ($column, $length): void {
                    $table->string($column, $length)->nullable()->index();
                });
            }
        }
        if (!$db->getSchemaBuilder()->hasColumn('ucasser_resources', 'share_permission')) {
            $schema->table('ucasser_resources', function (Blueprint $table): void {
                $table->text('share_permission')->nullable();
            });
        }
    },
    'down' => function (Builder $schema): void {
        $db = $schema->getConnection();
        if (!$db->getSchemaBuilder()->hasTable('ucasser_resources')) return;
        foreach (['resource_type', 'access_code', 'share_permission', 'external_key'] as $column) {
            if ($db->getSchemaBuilder()->hasColumn('ucasser_resources', $column)) {
                $schema->table('ucasser_resources', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    },
];
