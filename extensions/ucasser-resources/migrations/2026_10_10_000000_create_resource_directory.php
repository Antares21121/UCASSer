<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('ucasser_resource_categories', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->nullable()->index();
            $table->string('kind', 24);
            $table->string('name', 120);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        $schema->create('ucasser_resources', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('category_id')->index();
            $table->string('title', 180);
            $table->string('url', 2048);
            $table->text('description')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->string('status', 24)->default('active');
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();
        });

        $schema->create('ucasser_resource_revisions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('resource_id')->index();
            $table->unsignedInteger('editor_id');
            $table->text('previous_data')->nullable();
            $table->text('new_data');
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at');
        });

        $schema->create('ucasser_resource_reports', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('resource_id')->index();
            $table->unsignedInteger('reporter_id');
            $table->string('reason', 255);
            $table->string('status', 24)->default('pending');
            $table->timestamp('created_at');
        });

        $now = date('Y-m-d H:i:s');
        $categories = $schema->getConnection()->table('ucasser_resource_categories');
        $categories->insertGetId(['kind' => 'root', 'name' => '校内资料', 'position' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $outside = $categories->insertGetId(['kind' => 'root', 'name' => '校外资料', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $cet = $categories->insertGetId(['parent_id' => $outside, 'kind' => 'external_topic', 'name' => '四六级', 'position' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $ncre = $categories->insertGetId(['parent_id' => $outside, 'kind' => 'external_topic', 'name' => '计算机二级', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);

        // These are official public index pages, not copied examination materials.
        $schema->getConnection()->table('ucasser_resources')->insert([
            ['category_id' => $cet, 'title' => '全国大学英语四、六级考试官网', 'url' => 'https://cet.neea.edu.cn/', 'source_url' => 'https://cet.neea.edu.cn/', 'description' => '教育部教育考试院的考试介绍、动态与服务入口。', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['category_id' => $ncre, 'title' => '全国计算机等级考试官网', 'url' => 'https://ncre.neea.edu.cn/', 'source_url' => 'https://ncre.neea.edu.cn/', 'description' => '教育部教育考试院的考试介绍、动态与服务入口。', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('ucasser_resource_reports');
        $schema->dropIfExists('ucasser_resource_revisions');
        $schema->dropIfExists('ucasser_resources');
        $schema->dropIfExists('ucasser_resource_categories');
    },
];
