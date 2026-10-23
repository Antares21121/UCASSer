<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // Explicit campus identity for the seeded root row: controllers and the UI
        // must not infer campus/external identity from sibling row existence.
        $schema->table('ucasser_resource_categories', function (Blueprint $table) {
            $table->boolean('is_campus')->default(false);
        });

        // The campus root (校内资料) is the first seeded root row; mark it explicitly.
        // MariaDB cannot UPDATE from a subquery on the same table without a derived table.
        $schema->getConnection()->statement(
            'UPDATE ucasser_resource_categories SET is_campus = 1 '.
            'WHERE kind = \'root\' AND id = (SELECT MIN(id) FROM (SELECT id FROM ucasser_resource_categories WHERE kind = \'root\') AS roots)'
        );

        // Race-proof backstop for the application-level duplicate-name pre-check.
        // If an existing database already contains duplicate (parent_id, name) pairs
        // (possible only through a previously raced insert), this index creation
        // fails loudly and the duplicates must be merged before migrating.
        $schema->table('ucasser_resource_categories', function (Blueprint $table) {
            $table->unique(['parent_id', 'name'], 'ucasser_categories_parent_name_unique');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('ucasser_resource_categories', function (Blueprint $table) {
            $table->dropUnique('ucasser_categories_parent_name_unique');
        });
        $schema->table('ucasser_resource_categories', function (Blueprint $table) {
            $table->dropColumn('is_campus');
        });
    },
];
