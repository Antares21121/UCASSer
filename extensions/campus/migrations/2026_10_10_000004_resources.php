<?php
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;
return ['up'=>function (Builder $s) {
    $s->create('campus_bookmarks',function (Blueprint $t) { $t->unsignedInteger('user_id'); $t->unsignedInteger('record_id'); $t->boolean('following')->default(false); $t->primary(['user_id','record_id']); });
    $s->create('campus_categories',function (Blueprint $t) { $t->string('type',30); $t->string('key',50); $t->string('name',80); $t->boolean('enabled')->default(true); $t->primary(['type','key']); });
},'down'=>function (Builder $s) { $s->dropIfExists('campus_categories'); $s->dropIfExists('campus_bookmarks'); }];
