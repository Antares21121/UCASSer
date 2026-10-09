<?php
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;
return ['up'=>function (Builder $s) {
    $s->create('campus_cases',function (Blueprint $t) { $t->increments('id'); $t->unsignedInteger('reporter_id')->nullable()->index(); $t->unsignedInteger('subject_user_id')->nullable()->index(); $t->unsignedInteger('discussion_id')->index(); $t->unsignedInteger('section_id')->index(); $t->string('target_type',30); $t->unsignedInteger('target_id'); $t->text('reason'); $t->string('status',30)->default('pending'); $t->string('resolution',500)->nullable(); $t->unsignedInteger('processor_id')->nullable(); $t->unsignedInteger('reviewer_id')->nullable(); $t->unsignedInteger('version')->default(1); $t->dateTime('created_at'); $t->dateTime('updated_at'); });
    $s->create('campus_case_events',function (Blueprint $t) { $t->increments('id'); $t->unsignedInteger('case_id')->index(); $t->unsignedInteger('actor_id')->nullable(); $t->string('action',30); $t->text('message'); $t->dateTime('created_at'); });
    $s->create('campus_governance',function (Blueprint $t) { $t->increments('id'); $t->string('type',30)->index(); $t->string('title',80); $t->text('body'); $t->unsignedInteger('actor_id')->nullable(); $t->dateTime('effective_at'); $t->dateTime('created_at'); });
},'down'=>function (Builder $s) {foreach (['campus_governance','campus_case_events','campus_cases'] as $t) $s->dropIfExists($t);}];
