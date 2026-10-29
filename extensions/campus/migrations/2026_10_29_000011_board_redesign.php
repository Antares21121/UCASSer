<?php

use Flarum\Tags\Tag;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use UCASSer\Campus\BoardMigrator;
use UCASSer\Campus\UserTags;

// Board redesign: new discussion boards (secondhand/travel/archive), retired
// board closure, content migration, permanent user suffixes, experience
// baselines, the controlled college catalog and batch import ledger.
// Everything is idempotent so a partially applied run can be retried.
return [
    'up' => function (Builder $schema): void {
        $db = $schema->getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $create = function (string $table, callable $columns) use ($db, $schema): void {
            if ($db->getSchemaBuilder()->hasTable($table)) return;
            $schema->create($table, $columns);
        };

        // --- Board migration ledger -------------------------------------
        $create('campus_board_migrations', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('discussion_id')->unique();
            $table->unsignedInteger('source_tag_id');
            $table->unsignedInteger('target_section_id');
            $table->unsignedInteger('target_tag_id');
            $table->string('status', 16)->default('auto');
            $table->string('note', 500)->nullable();
            $table->timestamp('applied_at');
        });

        // --- Public reply images (separate storage from private chat) ----
        $create('campus_post_images', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->unsignedInteger('owner_id')->index();
            $table->unsignedInteger('discussion_id')->nullable()->index();
            $table->unsignedInteger('post_id')->nullable()->index();
            $table->unsignedInteger('bytes');
            $table->timestamp('created_at')->index();
        });

        // --- Permanent unique numeric display suffixes -------------------
        // user_id is nullable so erased accounts keep their suffix reserved
        // without violating the primary key (suffixes are never recycled).
        $create('campus_user_tags', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable()->unique();
            $table->unsignedInteger('suffix')->unique();
            $table->boolean('reserved')->default(false);
            $table->timestamp('created_at');
        });

        // --- Experience / level baseline ---------------------------------
        $create('campus_user_stats', function (Blueprint $table): void {
            $table->unsignedInteger('user_id')->primary();
            $table->unsignedInteger('total_xp')->default(0);
            $table->unsignedInteger('level')->default(1);
            $table->timestamp('updated_at');
        });

        // --- Controlled college catalog ----------------------------------
        $create('campus_colleges', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code', 40)->unique();
            $table->string('name', 120)->unique();
            $table->string('aliases', 300)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->string('status', 16)->default('active');
            $table->string('external_key', 80)->nullable()->index();
            $table->unsignedInteger('resource_category_id')->nullable()->index();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
        });

        // --- Batch import ledger with per-row rollback --------------------
        $create('campus_import_batches', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kind', 20);
            $table->unsignedInteger('actor_id');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('applied_rows')->default(0);
            $table->string('status', 16)->default('applied');
            $table->timestamp('created_at');
        });
        $create('campus_import_batch_rows', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('batch_id')->index();
            $table->string('external_key', 80)->nullable();
            $table->string('action', 16);
            $table->unsignedInteger('entity_id')->nullable();
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->string('error', 500)->nullable();
        });

        // --- Course / offering catalog extensions ------------------------
        if (!$db->getSchemaBuilder()->hasColumn('campus_courses', 'college_id')) {
            $schema->table('campus_courses', function (Blueprint $table): void {
                $table->unsignedInteger('college_id')->nullable()->index();
            });
        }
        foreach ([['college_id', 'unsignedInteger'], ['nature', 'string:80'], ['description', 'text'], ['external_key', 'string:80']] as [$column, $type]) {
            if (!$db->getSchemaBuilder()->hasColumn('campus_course_offerings', $column)) {
                $schema->table('campus_course_offerings', function (Blueprint $table) use ($column, $type): void {
                    if ($type === 'unsignedInteger') $table->unsignedInteger($column)->nullable()->index();
                    elseif ($type === 'text') $table->text($column)->nullable();
                    else {
                        $length = (int) explode(':', $type)[1];
                        $table->string($column, $length)->nullable()->index();
                    }
                });
            }
        }

        // --- Cloud drive link metadata -----------------------------------
        // ucasser-resources may enable after this extension on fresh installs;
        // its own migration adds the same columns, guarded identically.
        if ($db->getSchemaBuilder()->hasTable('ucasser_resources')) {
            foreach ([['resource_type', 40], ['access_code', 80], ['external_key', 80]] as [$column, $length]) {
                if (!$db->getSchemaBuilder()->hasColumn('ucasser_resources', $column)) {
                    $schema->table('ucasser_resources', function (Blueprint $table) use ($column, $length): void {
                        $table->string($column, $length)->nullable();
                    });
                }
            }
            if (!$db->getSchemaBuilder()->hasColumn('ucasser_resources', 'share_permission')) {
                $schema->table('ucasser_resources', function (Blueprint $table): void {
                    $table->text('share_permission')->nullable();
                });
            }
            if ($schema->hasColumn('ucasser_resources', 'external_key')
                && !$schema->hasIndex('ucasser_resources', ['external_key'])) {
                $schema->table('ucasser_resources', function (Blueprint $table): void {
                    $table->index('external_key');
                });
            }
        }

        // --- Backfill new boards, retire old ones and move discussions ----
        (new BoardMigrator($db))->ensureBoards();
        (new BoardMigrator($db))->apply();

        // --- Backfill permanent suffixes and level baselines --------------
        (new UserTags($db))->backfill();
        foreach ($db->table('users')->orderBy('id')->pluck('id')->all() as $userId) {
            $db->table('campus_user_stats')->insertOrIgnore([
                'user_id' => $userId, 'total_xp' => 0, 'level' => 1, 'updated_at' => $now,
            ]);
        }
        $settings = $db->table('settings');
        if (!$settings->where('key', 'ucasser-campus.level_thresholds')->exists()) {
            $settings->insert(['key' => 'ucasser-campus.level_thresholds', 'value' => json_encode([0, 100, 300, 600, 1000, 1500, 2100, 2800, 3600, 4500])]);
        }
        if (!$settings->where('key', 'ucasser-campus.level_scheme_version')->exists()) {
            $settings->insert(['key' => 'ucasser-campus.level_scheme_version', 'value' => '1']);
        }

        // --- Seed the college catalog from already-reviewed data ----------
        // Colleges exist either as resource-directory category nodes or as
        // reviewed course-offering rows. Merge them by unique name.
        $existing = $db->table('campus_colleges')->get(['name'])->pluck('name')->all();
        $names = [];
        if ($db->getSchemaBuilder()->hasTable('ucasser_resource_categories')) {
            $names = array_merge($names, $db->table('ucasser_resource_categories')->where('kind', 'college')->pluck('name')->all());
        }
        if ($db->getSchemaBuilder()->hasTable('campus_course_offerings')) {
            $names = array_merge($names, $db->table('campus_course_offerings')->whereNotNull('college')->where('college', '<>', '')->pluck('college')->all());
        }
        foreach (array_unique(array_filter($names)) as $name) {
            if (in_array($name, $existing, true)) continue;
            $resourceCategoryId = $db->getSchemaBuilder()->hasTable('ucasser_resource_categories')
                ? $db->table('ucasser_resource_categories')->where('kind', 'college')->where('name', $name)->value('id') : null;
            $next = (int) $db->table('campus_colleges')->max('id') + 1;
            $id = $db->table('campus_colleges')->insertGetId([
                'code' => 'C'.str_pad((string) $next, 3, '0', STR_PAD_LEFT),
                'name' => $name, 'aliases' => null, 'position' => 100,
                'status' => 'active', 'resource_category_id' => $resourceCategoryId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($resourceCategoryId) {
                $db->table('campus_course_offerings')->where('college', $name)->update(['college_id' => $id]);
            }
        }

        // Hide retired tags that the campus extension did not own.
        Tag::query()->where('slug', 'general')->update(['is_hidden' => true]);
    },
    'down' => function (Builder $schema): void {
        $db = $schema->getConnection();
        foreach (['campus_import_batch_rows', 'campus_import_batches', 'campus_board_migrations', 'campus_post_images', 'campus_user_tags', 'campus_user_stats', 'campus_colleges'] as $table) {
            if ($db->getSchemaBuilder()->hasTable($table)) $schema->dropIfExists($table);
        }
        if ($db->getSchemaBuilder()->hasColumn('campus_courses', 'college_id')) {
            $schema->table('campus_courses', fn (Blueprint $t) => $t->dropColumn('college_id'));
        }
        foreach (['college_id', 'nature', 'description', 'external_key'] as $column) {
            if ($db->getSchemaBuilder()->hasColumn('campus_course_offerings', $column)) {
                $schema->table('campus_course_offerings', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
        if ($db->getSchemaBuilder()->hasTable('ucasser_resources')) {
            foreach (['resource_type', 'access_code', 'share_permission', 'external_key'] as $column) {
                if ($db->getSchemaBuilder()->hasColumn('ucasser_resources', $column)) {
                    $db->getSchemaBuilder()->table('ucasser_resources', fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
        $db->table('settings')->whereIn('key', ['ucasser-campus.level_thresholds', 'ucasser-campus.level_scheme_version'])->delete();
    },
];
