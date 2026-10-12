<?php
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;

return ['up' => function (Builder $schema) {
    $schema->create('campus_aggregation_sources', function (Blueprint $table) {
        $table->increments('id');
        $table->string('name', 120);
        $table->string('listing_url', 1024);
        $table->string('adapter', 60);
        $table->string('trust', 20)->default('unofficial');
        $table->string('visibility', 20)->default('public');
        $table->boolean('enabled')->default(true);
        $table->dateTime('last_checked_at')->nullable();
        $table->string('last_error', 500)->nullable();
        $table->dateTime('created_at');
        $table->dateTime('updated_at');
    });
    $schema->create('campus_aggregation_items', function (Blueprint $table) {
        $table->increments('id');
        $table->string('title', 180);
        $table->text('summary');
        $table->string('category', 40)->default('notice');
        $table->string('verification', 20)->default('unverified');
        $table->string('visibility', 20)->default('public')->index();
        $table->unsignedInteger('submitter_id')->nullable();
        $table->boolean('show_submitter')->default(false);
        $table->dateTime('source_published_at')->nullable();
        $table->dateTime('created_at');
        $table->dateTime('updated_at');
        $table->index(['visibility', 'id']);
    });
    $schema->create('campus_aggregation_candidates', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('source_id')->nullable()->index();
        $table->unsignedInteger('submitter_id')->nullable()->index();
        $table->unsignedInteger('item_id')->nullable()->index();
        $table->string('origin', 20);
        $table->string('visibility', 20);
        $table->string('trust', 20);
        $table->string('status', 20)->default('pending')->index();
        $table->string('fingerprint', 64)->unique();
        $table->string('url_hash', 64)->nullable()->index();
        $table->string('content_hash', 64);
        $table->string('source_name', 120);
        $table->string('source_url', 1024)->nullable();
        $table->string('title', 180);
        $table->text('summary');
        $table->string('category', 40)->default('notice');
        $table->boolean('show_submitter')->default(false);
        $table->dateTime('source_published_at')->nullable();
        $table->unsignedInteger('reviewer_id')->nullable();
        $table->string('review_reason', 500)->nullable();
        $table->dateTime('reviewed_at')->nullable();
        $table->dateTime('created_at');
        $table->index(['status', 'id']);
    });
    $schema->create('campus_aggregation_events', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('candidate_id')->index();
        $table->unsignedInteger('item_id')->nullable()->index();
        $table->unsignedInteger('actor_id')->nullable();
        $table->string('action', 30);
        $table->string('reason', 500)->nullable();
        $table->dateTime('created_at');
    });
    $now = gmdate('Y-m-d H:i:s');
    $schema->getConnection()->table('campus_aggregation_sources')->insert([
        'name' => '中国社会科学院大学教务处通知公告',
        'listing_url' => 'https://jw.ucass.edu.cn/tzgg.htm',
        'adapter' => 'ucass-notices',
        'trust' => 'official',
        'visibility' => 'public',
        'enabled' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}, 'down' => function (Builder $schema) {
    foreach (['campus_aggregation_events', 'campus_aggregation_candidates', 'campus_aggregation_items', 'campus_aggregation_sources'] as $table) $schema->dropIfExists($table);
}];
