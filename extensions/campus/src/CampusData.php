<?php
namespace UCASSer\Campus;
use Flarum\Gdpr\Data\Type;
class CampusData extends Type
{
    public static function exportDescription(): string { return '校园身份申请、收藏、本人反馈与内容记录'; }
    public static function anonymizeDescription(): string { return '删除验证材料与授权，解除个人记录关联，保留公共讨论'; }
    public static function deleteDescription(): string { return self::anonymizeDescription(); }
    public static function piiFields(): array { return ['evidence','user_id','actor_id','reviewer_id']; }
    public function export(): ?array
    {
        $db=$this->user->getConnection(); $out=[];
        $visible=\Flarum\Discussion\Discussion::query()->whereVisibleTo($this->user)->select('discussions.id');
        $records=$db->table('campus_records')->whereIn('discussion_id',$visible)->select('id');
        foreach (['campus_verifications','campus_moderators','campus_bookmarks','campus_discussion_bookmarks','campus_feedback','campus_votes','campus_records'] as $table) {
            if (!$db->getSchemaBuilder()->hasTable($table)) continue;
            $query=$db->table($table)->where('user_id',$this->user->id);
            if (in_array($table,['campus_bookmarks','campus_feedback','campus_votes'],true)) $query->whereIn('record_id',$records);
            elseif (in_array($table,['campus_records','campus_discussion_bookmarks'],true)) $query->whereIn('discussion_id',$visible);
            $out[$table]=$query->get()->map(function ($row) { unset($row->reviewer_id); return $row; })->all();
        }
        $out['campus_cases']=$db->table('campus_cases')->where('reporter_id',$this->user->id)->get(['id','target_type','target_id','reason','status','resolution','created_at','updated_at'])->all();
        return ['campus.json'=>$this->encodeForExport($out)];
    }
    public function anonymize(): void
    {
        $db=$this->user->getConnection(); $id=$this->user->id;
        $db->transaction(function () use ($db,$id) {
            foreach (['campus_verifications','campus_moderators','campus_bookmarks','campus_discussion_bookmarks','campus_votes','campus_feedback'] as $table) if ($db->getSchemaBuilder()->hasTable($table)) $db->table($table)->where('user_id',$id)->delete();
            foreach ($db->table('campus_revisions')->whereIn('record_id',$db->table('campus_records')->where('user_id',$id)->select('id'))->get() as $revision) {
                $snapshot=json_decode($revision->snapshot,true,512,JSON_THROW_ON_ERROR); $snapshot['user_id']=null;
                // Historical copies are personal data too; the current public contribution follows core GDPR retention.
                $snapshot['body']='[个人数据清除后移除历史正文]';
                $snapshot['title']='[个人数据清除后移除历史标题]'; $snapshot['data']='{}';
                $db->table('campus_revisions')->where('id',$revision->id)->update(['snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            }
            $db->table('campus_records')->where('user_id',$id)->update(['user_id'=>null]);
            foreach ($db->table('campus_cases')->where('reporter_id',$id)->get(['id']) as $case) $db->table('campus_case_events')->where('case_id',$case->id)->whereIn('action',['created','supplement','appealed'])->update(['message'=>'[个人举报材料已清除]']);
            $db->table('campus_cases')->where('reporter_id',$id)->update(['reporter_id'=>null,'reason'=>'[个人举报材料已清除]']);
            $db->table('campus_cases')->where('subject_user_id',$id)->update(['subject_user_id'=>null]);
            $db->table('campus_cases')->where('target_type','user')->where('target_id',$id)->update(['target_id'=>0]);
            $db->table('campus_case_events')->where('actor_id',$id)->whereIn('action',['created','supplement','appealed'])->update(['message'=>'[个人举报或申诉材料已清除]']);
            foreach (['processor_id','reviewer_id'] as $key) $db->table('campus_cases')->where($key,$id)->update([$key=>null]);
            foreach (['campus_case_events','campus_governance','campus_revisions'] as $table) $db->table($table)->where('actor_id',$id)->update(['actor_id'=>null]);
            $db->table('campus_feedback')->where('reviewer_id',$id)->update(['reviewer_id'=>null]);
            $db->table('campus_verifications')->where('reviewer_id',$id)->update(['reviewer_id'=>null]);
            $db->table('campus_audit')->where('actor_id',$id)->update(['actor_id'=>null]);
        });
    }
    public function delete(): void { $this->anonymize(); }
}
