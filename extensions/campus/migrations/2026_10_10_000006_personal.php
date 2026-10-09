<?php
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;
return ['up'=>function (Builder $s) { $s->create('campus_discussion_bookmarks',function (Blueprint $t) { $t->unsignedInteger('user_id'); $t->unsignedInteger('discussion_id'); $t->primary(['user_id','discussion_id']); }); },'down'=>fn(Builder $s)=>$s->dropIfExists('campus_discussion_bookmarks')];
