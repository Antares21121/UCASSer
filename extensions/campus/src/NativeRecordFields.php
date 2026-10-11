<?php
namespace UCASSer\Campus;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
class NativeRecordFields
{
    public function __construct(private Records $records) {}
    public function __invoke(): array {
        return [Schema\Arr::make('campusRecord')->get(function (Discussion $discussion,Context $context) {
            $actor=$context->getActor();
            $row=$this->records->query($actor)->where('discussion_id',$discussion->id)->first();
            if (!$row) return [];
            $data=$this->records->serialize($actor,$row);
            return array_intersect_key($data,array_flip(['section_key','course_name','featured','id','type','category','status','fields','course_id','semester','version','can_edit','can_moderate','accepted_post_id','author_id','useful','voted']));
        })];
    }
}
