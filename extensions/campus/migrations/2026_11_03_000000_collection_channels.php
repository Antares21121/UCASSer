<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    // Old jobs already ran WeChat inline. Do not refresh those accounts twice.
    'up' => fn (Builder $schema) => $schema->table('campus_collection_jobs', fn (Blueprint $table) => $table->boolean('include_wechat')->default(false)),
    'down' => fn (Builder $schema) => $schema->table('campus_collection_jobs', fn (Blueprint $table) => $table->dropColumn('include_wechat')),
];
