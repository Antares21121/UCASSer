<?php
namespace UCASSer\Campus;

use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Restored;
use Flarum\Post\Event\Revised;
use Illuminate\Database\ConnectionInterface;

/**
 * Binds uploaded reply images to the post that references them. Only the
 * owner's own unattached uploads can be claimed, so nobody can hijack a
 * draft image by linking its URL.
 */
class PostImages
{
    public function __construct(private ConnectionInterface $db) {}

    public function handle(Posted|Revised|Restored $event): void
    {
        $post = $event->post;
        if (!$post || !$post->discussion_id || !$post->user_id) return;
        preg_match_all('~/api/campus/post-images/([a-f0-9]{32})~', (string) $post->content, $matches);
        foreach (array_unique($matches[1] ?? []) as $id) {
            $this->db->table('campus_post_images')->where('id', $id)
                ->where('owner_id', $post->user_id)->whereNull('post_id')
                ->update(['post_id' => $post->id, 'discussion_id' => $post->discussion_id]);
        }
    }
}
