<?php
// Exercise the real pivot schema; all fixtures are rolled back.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'development') throw new RuntimeException('Development CLI only');
require __DIR__.'/../vendor/autoload.php';
$container = (require __DIR__.'/../site.php')->bootApp()->getContainer();
$db = $container->make(Illuminate\Database\ConnectionInterface::class);
$migrator = $container->make(UCASSer\Campus\BoardMigrator::class);
$check = function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$db->beginTransaction();
try {
    $actor = Flarum\User\User::query()->where('username', 'phase0_admin')->firstOrFail();
    $tags = [];
    foreach (['campus-chat', 'campus-market', 'upgrade-secondary-'.bin2hex(random_bytes(4))] as $slug) {
        $tag = Flarum\Tags\Tag::query()->where('slug', $slug)->first();
        if (!$tag) {
            $tag = Flarum\Tags\Tag::build('【示例】迁移标签', $slug, 'Migration acceptance', '#A71937', 'fas fa-tag', true);
            $tag->save();
        }
        $tags[] = $tag->id;
    }
    if (!$db->table('campus_sections')->where('key', 'chat')->exists()) {
        $db->table('campus_sections')->insert(['key' => 'chat', 'tag_id' => $tags[0], 'is_open' => false, 'visibility' => 'public']);
    }
    $visibleKeys = array_map(fn ($section) => $section->key, $container->make(UCASSer\Campus\Sections::class)->visible($actor));
    $check(!array_intersect($visibleKeys, UCASSer\Campus\Sections::RETIRED), 'Retired sections must stay out of the admin publishing catalogue');
    $discussion = Flarum\Discussion\Discussion::start('【示例】二手迁移验收', $actor);
    $discussion->save();
    $postId = $db->table('posts')->insertGetId([
        'discussion_id' => $discussion->id, 'user_id' => $actor->id, 'number' => 1,
        'type' => 'comment', 'content' => '<t>Legacy content</t>', 'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
    foreach ($tags as $tagId) $db->table('discussion_tag')->insert(['discussion_id' => $discussion->id, 'tag_id' => $tagId]);
    $beforePost = $db->table('posts')->where('id', $postId)->first();
    $beforeDiscussion = $db->table('discussions')->where('id', $discussion->id)->first();
    $planned = array_filter($migrator->plan(), fn ($row) => (int) $row['discussion_id'] === (int) $discussion->id);
    $check(count($planned) === 1, 'A multi-tagged legacy discussion must be planned once');
    $migrator->apply();
    $target = $db->table('campus_sections')->where('key', 'secondhand')->value('tag_id');
    $actualTags = $db->table('discussion_tag')->where('discussion_id', $discussion->id)->pluck('tag_id')->all();
    $check(count($actualTags) === 2 && in_array($target, $actualTags) && in_array($tags[2], $actualTags), 'Replace retired tags and retain the secondary tag');
    $check($beforePost == $db->table('posts')->where('id', $postId)->first(), 'Post content and author must survive migration');
    $check($beforeDiscussion == $db->table('discussions')->where('id', $discussion->id)->first(), 'Discussion identity and timestamps must survive migration');
    $migrator->apply();
    $check($db->table('campus_board_migrations')->where('discussion_id', $discussion->id)->count() === 1, 'Migration must remain idempotent');
    echo "Legacy discussion migration, secondary tags and idempotence OK\n";
} finally {
    $db->rollBack();
}
