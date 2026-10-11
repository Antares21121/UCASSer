<?php
namespace UCASSer\Campus;
// Preserve structured history when an author uses Flarum's native edit controls.
class NativeRevision
{
    public function __construct(private Records $records) {}
    public function handle(\Flarum\Post\Event\Saving|\Flarum\Discussion\Event\Saving $event): void {
        if ($event instanceof \Flarum\Post\Event\Saving) {
            $model=$event->post; if (!$model->exists || $model->number!==1 || !$model->isDirty('content')) return;
            $discussion=$model->discussion_id;
        } else {
            $model=$event->discussion; if (!$model->exists || !$model->isDirty('title')) return;
            $discussion=$model->id;
        }
        $db=$this->records->db;
        $db->transaction(function () use ($db,$discussion,$event) {
            $row=$db->table('campus_records')->where('discussion_id',$discussion)->lockForUpdate()->first(); if (!$row) return;
            // The structured editor already saved this version in its enclosing transaction.
            if ($db->table('campus_revisions')->where('record_id',$row->id)->where('version',$row->version)->exists()) return;
            $this->records->revision($row,$event->actor,'通过原生讨论编辑更新');
            $db->table('campus_records')->where('id',$row->id)->update(['version'=>$row->version+1,'updated_at'=>gmdate('Y-m-d H:i:s')]);
            $this->records->identity->audit($event->actor,'record.native_edit',(string)$row->id,'通过原生讨论编辑更新');
        });
    }
}
