<?php
namespace UCASSer\Campus;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
class Cases
{
    public function __construct(public Records $records) {}
    public function manages(User $a,object $case): bool {
        if ($a->id===$case->reporter_id || $a->id===$case->subject_user_id) return false;
        if ($a->isAdmin()) return true;
        $d=Discussion::query()->with('tags')->find($case->discussion_id); if (!$d || $d->is_private) return false;
        $tagIds=$d->tags->pluck('id')->all(); $sections=array_filter($this->records->sections->rows(),fn($s)=>in_array($s->tag_id,$tagIds));
        return count($sections)>0 && array_reduce($sections,fn($ok,$s)=>$ok && $this->records->identity->moderator($a,$s->id),true);
    }
    public function visible(User $a,object $case): bool { return $case->reporter_id===$a->id || $case->subject_user_id===$a->id || $this->manages($a,$case); }
    public function query(User $a) {
        $db=$this->records->db; $query=$db->table('campus_cases');
        if ($a->isAdmin()) return $query;
        $assigned=$db->table('campus_sections')->whereIn('id',$db->table('campus_moderators')->where('user_id',$a->id)->select('section_id'))->pluck('tag_id')->all();
        $other=$db->table('campus_sections')->whereNotIn('tag_id',$assigned)->pluck('tag_id')->all();
        $managed=Discussion::query()->where('is_private',false)->whereHas('tags',fn($q)=>$q->whereIn('tags.id',$assigned))->whereDoesntHave('tags',fn($q)=>$q->whereIn('tags.id',$other))->select('discussions.id');
        return $query->where(fn($q)=>$q->where('reporter_id',$a->id)->orWhere('subject_user_id',$a->id)->orWhereIn('discussion_id',$managed));
    }
    public function serialize(User $a,object $row): array {
        $manager=$this->manages($a,$row); $reporter=$row->reporter_id===$a->id;
        $out=['id'=>$row->id,'target_type'=>$row->target_type,'target_id'=>$row->target_id,'discussion_id'=>$row->discussion_id,'section_id'=>$row->section_id,'status'=>$row->status,'resolution'=>$row->resolution,'version'=>$row->version,'created_at'=>$row->created_at,'updated_at'=>$row->updated_at,'can_manage'=>$manager,'can_review'=>$manager && $a->id!==$row->processor_id,'can_appeal'=>in_array($row->status,['resolved','rejected'],true) && ($reporter || $row->subject_user_id===$a->id),'reason'=>$manager || $reporter?$row->reason:null];
        $out['events']=$this->records->db->table('campus_case_events')->where('case_id',$row->id)->when(!$manager,fn($q)=>$q->where(fn($b)=>$b->where('actor_id',$a->id)->orWhereIn('action',['resolved','rejected','reviewed','needs_info'])))->orderBy('id')->get(['action','message','created_at'])->all();
        $out['can_supplement']=$reporter && in_array($row->status,['pending','needs_info'],true);
        $out['allowed_actions']=$manager?array_merge(['none','hide','restore'],$a->isAdmin()?['delete','suspend','unban']:[]):[];
        return $out;
    }
}
