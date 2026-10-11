<?php
namespace UCASSer\Campus;

use Flarum\Api\Context;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;

// Batch public previews once per native list request. Permission filtering
// happens before fetching metadata, and private history/feedback are excluded.
class NativeListFields
{
    public static function hydrate(Context $context, mixed $models): mixed
    {
        $records = resolve(Records::class);
        $actor = $context->getActor();
        $discussions = collect($models)->keyBy('id');
        $rows = $records->query($actor)->whereIn('discussion_id', $discussions->keys())->get()->keyBy('discussion_id');
        if ($rows->isEmpty()) {
            foreach ($discussions as $discussion) $discussion->setRelation('campusPreview', []);
            return $models;
        }
        $sections = $records->db->table('campus_sections')->whereIn('id', $rows->pluck('section_id'))->pluck('key', 'id');
        $courses = $records->db->table('campus_courses')->whereIn('id', $rows->pluck('course_id')->filter())->pluck('name', 'id');
        $moderated = $actor->isGuest() || $actor->isAdmin() ? [] : $records->db->table('campus_moderators')->where('user_id', $actor->id)->pluck('section_id')->all();
        $votes = $records->db->table('campus_votes')->whereIn('record_id', $rows->pluck('id'))->selectRaw('record_id, COUNT(*) as total')->groupBy('record_id')->pluck('total', 'record_id');
        $voted = $actor->isGuest() ? [] : $records->db->table('campus_votes')->where('user_id', $actor->id)->whereIn('record_id', $rows->pluck('id'))->pluck('record_id')->all();
        $accepted = Post::query()->whereVisibleTo($actor)->whereNull('hidden_at')->whereIn('id', $rows->pluck('accepted_post_id')->filter())->pluck('id')->all();
        $payloads = $rows->map(fn($row) => json_decode($row->data, true, 512, JSON_THROW_ON_ERROR));
        $relatedIds = $payloads->flatMap(fn($payload) => $payload['related'] ?? [])->unique();
        $related = $relatedIds->isEmpty() ? collect() : $records->query($actor)->whereIn('id', $relatedIds)->get()->keyBy('id');
        $relatedTitles = Discussion::query()->whereIn('id', $related->pluck('discussion_id'))->pluck('title', 'id');
        foreach ($discussions as $discussion) {
            $row = $rows->get($discussion->id);
            if (!$row) { $discussion->setRelation('campusPreview', []); continue; }
            $payload = $payloads->get($discussion->id);
            $payload['related'] = collect($payload['related'] ?? [])->filter(fn($id) => $related->has($id))->map(fn($id) => ['id' => $id, 'title' => $relatedTitles->get($related->get($id)->discussion_id)])->values()->all();
            $expiry = $row->deadline ?: (!empty($payload['ends_at']) ? str_replace('T', ' ', $payload['ends_at']).':00' : null);
            $status = $row->type === 'information' && $row->status === 'active' && $expiry && $expiry < gmdate('Y-m-d H:i:s') ? 'expired' : $row->status;
            $moderator = $actor->isAdmin() || in_array($row->section_id, $moderated);
            $canEdit = !$actor->isGuest() && ((int)$row->user_id === $actor->id || $moderator);
            if ($discussion->hidden_at && $discussion->hidden_user_id !== $actor->id && !$actor->isAdmin()) $canEdit = false;
            $discussion->setRelation('campusPreview', [
                'id' => $row->id, 'type' => $row->type, 'category' => $row->category, 'status' => $status,
                'section_key' => $sections->get($row->section_id), 'course_name' => $courses->get($row->course_id),
                'course_id' => $row->course_id, 'semester' => $row->semester, 'fields' => $payload,
                'version' => $row->version, 'featured' => (bool)$row->featured, 'author_id' => $row->user_id,
                'can_edit' => $canEdit, 'can_moderate' => $moderator,
                'accepted_post_id' => in_array($row->accepted_post_id, $accepted) ? $row->accepted_post_id : null,
                'useful' => (int)$votes->get($row->id, 0), 'voted' => in_array($row->id, $voted)
            ]);
        }
        return $models;
    }
}
