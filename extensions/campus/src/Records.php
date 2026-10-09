<?php
namespace UCASSer\Campus;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;
use Flarum\Foundation\ValidationException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
class Records
{
    public const CATEGORIES=[
        'information'=>['activity'=>'校园活动','lecture'=>'讲座','internship'=>'实习','job'=>'就业','competition'=>'竞赛','recruit'=>'招募','volunteer'=>'志愿','market'=>'二手','travel'=>'出行','buddy'=>'找伙伴','help'=>'互助','other'=>'其他'],
        'resource'=>['lecture'=>'公开讲义','notes'=>'学习笔记','exam'=>'考试经验','revision'=>'复习指南','competition'=>'竞赛资料','academic'=>'学术阅读','guide'=>'校园指南','faq'=>'常见问题','other'=>'其他合法共享资源'],
        'question'=>['study'=>'学习与课程','life'=>'校园生活','procedure'=>'校园办事','activity'=>'活动与竞赛','other'=>'其他互助'],
        'review'=>['experience'=>'课程体验'],
    ];
    public const STATES=['information'=>['active','ended','resolved','invalid','sold'],'resource'=>['active','needs_verification','invalid'],'question'=>['waiting','discussing','resolved'],'review'=>['active','withdrawn']];
    public const FIELDS=[
        'information'=>['source','source_url','published_at','location','organizer','starts_at','ends_at','apply_url','conditions','deadline','contact','offer_type','tags'],
        'resource'=>['source','source_url','license','course','topic','grade','edition','updated_at','alternate_url','last_verified','tags'],
        'question'=>['background','tags'],
        'review'=>['assessment','participation','gains','prerequisites','study','experience','advice','tags'],
    ];
    public function __construct(public ConnectionInterface $db, public Sections $sections, public Identity $identity) {}
    public function setup(): void {foreach (self::CATEGORIES as $type=>$items) foreach ($items as $key=>$name) $this->db->table('campus_categories')->insertOrIgnore(['type'=>$type,'key'=>$key,'name'=>$name]);}
    public function categories(): array { $out=[]; foreach (self::CATEGORIES as $type=>$items) $out[$type]=[]; foreach ($this->db->table('campus_categories')->where('enabled',true)->get() as $c) $out[$c->type][$c->key]=$c->name; return $out; }
    public function query(User $actor) {return $this->db->table('campus_records')->whereIn('discussion_id',Discussion::query()->whereVisibleTo($actor)->select('discussions.id'));}
    public static function expiry(string $prefix=''): string {return "COALESCE({$prefix}deadline, STR_TO_DATE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$prefix}data, '$.ends_at')), ''), '%Y-%m-%dT%H:%i'))";}
    public static function statusFilter($query,string $status,string $prefix=''): void {
        $expiry=self::expiry($prefix); $now=gmdate('Y-m-d H:i:s');
        if ($status==='expired') $query->where($prefix.'type','information')->where($prefix.'status','active')->whereRaw("$expiry < ?",[$now]);
        elseif ($status==='active') $query->where($prefix.'status','active')->where(fn($q)=>$q->where($prefix.'type','!=','information')->orWhereRaw("$expiry IS NULL OR $expiry >= ?",[$now]));
        else $query->where($prefix.'status',$status);
    }
    public function find(User $actor,int $id): object { $row=$this->query($actor)->where('id',$id)->first(); if (!$row) throw new ModelNotFoundException; return $row; }
    public function canEdit(User $actor,object $row): bool {
        $d=Discussion::query()->findOrFail($row->discussion_id);
        if ($d->hidden_at && $d->hidden_user_id!==$actor->id && !$actor->isAdmin()) return false;
        return !$actor->isGuest() && ((int)$row->user_id===$actor->id || $this->identity->moderator($actor,$row->section_id));
    }
    public function text(array $d,string $key,int $min=0,int $max=2000): string {
        $v=$d[$key] ?? ''; if (!is_string($v) || mb_strlen(trim($v))<$min || mb_strlen($v)>$max) throw new ValidationException([$key=>"请填写 $min–$max 字"]); return trim($v);
    }
    public function validate(User $actor,array $d,?object $row=null): array {
        $type=$row?->type ?? ($d['type'] ?? ''); if (!isset(self::CATEGORIES[$type])) throw new ValidationException(['type'=>'请选择内容类型']);
        if (($d['anonymous'] ?? false)!==false) throw new ValidationException(['anonymous'=>'匿名发布未通过隐私验收，当前使用正常账号']);
        $title=$this->text($d,'title',3,80); $body=$this->text($d,'body',5,30000);
        $category=$d['category'] ?? ''; if (!isset(self::CATEGORIES[$type][$category]) || (!$row && !$this->db->table('campus_categories')->where('type',$type)->where('key',$category)->where('enabled',true)->exists())) throw new ValidationException(['category'=>'请选择已开放分类']);
        $section=$this->sections->byId((int)($d['section_id'] ?? $row?->section_id ?? 0));
        if ($section->key==='governance') $actor->assertAdmin();
        if ($type==='resource' && $section->key!=='resources') throw new ValidationException(['section_id'=>'资料必须发布到共享资料分区']);
        if ($type==='question' && $section->key!=='questions') throw new ValidationException(['section_id'=>'问题必须发布到问答分区']);
        if ($type==='review' && $section->key!=='courses') throw new ValidationException(['section_id'=>'课程评价必须发布到课程分区']);
        $actor->assertPermission($this->sections->allowed($actor,$section));
        $status=$d['status'] ?? $row?->status ?? self::STATES[$type][0]; if (!in_array($status,self::STATES[$type],true)) throw new ValidationException(['status'=>'请选择有效状态']);
        if ($type==='question' && (($status==='resolved' && !$row?->accepted_post_id) || ($row?->accepted_post_id && $status!=='resolved'))) throw new ValidationException(['status'=>'请通过采纳或取消采纳更改解决状态']);
        $payload=[];
        foreach (self::FIELDS[$type] as $key) {
            $v=$this->text($d,$key,0,$key==='tags'?200:2000);
            if (str_ends_with($key,'_url') && $v && (!filter_var($v,FILTER_VALIDATE_URL) || !in_array(parse_url($v,PHP_URL_SCHEME),['http','https'],true))) throw new ValidationException([$key=>'链接必须为 http 或 https']);
            if (in_array($key,['published_at','starts_at','ends_at','deadline','updated_at','last_verified'],true) && $v) {
                $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$v,new \DateTimeZone('UTC')); if (!$date || $date->format('Y-m-d\TH:i')!==$v) throw new ValidationException([$key=>'请填写有效 UTC 日期与时间']);
            }
            $payload[$key]=$v;
        }
        if ($type==='information' && $payload['starts_at'] && $payload['ends_at'] && $payload['ends_at']<$payload['starts_at']) throw new ValidationException(['ends_at'=>'结束时间不得早于开始时间']);
        if (in_array($type,['information','resource'],true) && !$payload['source']) throw new ValidationException(['source'=>'请说明信息来源']);
        if ($type==='resource' && (!$payload['source_url'] || !$payload['license'])) throw new ValidationException(['license'=>'资料必须提供访问链接与版权或使用说明']);
        $related=$d['related'] ?? []; if (!is_array($related) || count($related)>10) throw new ValidationException(['related'=>'最多关联十条内容']);
        $payload['related']=[]; foreach ($related as $id) { if (!is_int($id) || ($row && $id===$row->id)) throw new ValidationException(['related'=>'关联编号无效']); $this->find($actor,$id); $payload['related'][]=$id; }
        $course=null; $semester=null;
        if ($type==='review') {
            $course=(int)($d['course_id'] ?? 0); if (!$this->db->table('campus_courses')->where('id',$course)->exists()) throw new ValidationException(['course_id'=>'请选择已维护的课程']);
            $semester=$this->text($d,'semester',2,80);
            foreach (['overall','difficulty','workload'] as $key) { if (!is_int($d[$key] ?? null) || $d[$key]<1 || $d[$key]>5) throw new ValidationException([$key=>'请选择 1–5 分']); $payload[$key]=$d[$key]; }
            $reviewer=$row?$row->user_id:$actor->id;
            if ($reviewer!==null && $this->db->table('campus_records')->where('type','review')->where('user_id',$reviewer)->where('course_id',$course)->where('semester',$semester)->when($row,fn($q)=>$q->where('id','!=',$row->id))->exists()) throw new ValidationException(['semester'=>'该学期已有评价，请编辑或恢复原评价']);
        }
        $featured=$d['featured'] ?? (bool)($row?->featured ?? false); if (!is_bool($featured)) throw new ValidationException(['featured'=>'精选状态无效']);
        if ($featured!==(bool)($row?->featured ?? false)) $actor->assertPermission($this->identity->moderator($actor,$section->id));
        $date=fn($key)=>!empty($payload[$key])?str_replace('T',' ',$payload[$key]).':00':null;
        return ['title'=>$title,'body'=>$body,'type'=>$type,'category'=>$category,'section_id'=>$section->id,'status'=>$status,'featured'=>$featured,'course_id'=>$course,'semester'=>$semester,'deadline'=>$date('deadline'),'starts_at'=>$date('starts_at'),'data'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
    }
    public function serialize(User $actor,object $row,bool $detail=false): array {
        $d=Discussion::query()->whereVisibleTo($actor)->with('user')->findOrFail($row->discussion_id); $payload=json_decode($row->data,true,512,JSON_THROW_ON_ERROR);
        $related=[]; foreach ($payload['related'] ?? [] as $id) { $rel=$this->query($actor)->where('id',$id)->first(); if ($rel) $related[]=['id'=>$rel->id,'title'=>Discussion::query()->findOrFail($rel->discussion_id)->title]; } $payload['related']=$related;
        $expiry=$row->deadline ?: (!empty($payload['ends_at'])?str_replace('T',' ',$payload['ends_at']).':00':null);
        $expired=$row->type==='information' && $row->status==='active' && $expiry && $expiry<gmdate('Y-m-d H:i:s');
        $accepted=$row->accepted_post_id && Post::query()->whereVisibleTo($actor)->whereNull('hidden_at')->where('id',$row->accepted_post_id)->exists()?$row->accepted_post_id:null;
        $bookmark=$actor->isGuest()?null:$this->db->table('campus_bookmarks')->where('user_id',$actor->id)->where('record_id',$row->id)->first();
        $out=['id'=>$row->id,'discussion_id'=>$d->id,'title'=>$d->title,'type'=>$row->type,'category'=>$row->category,'status'=>$expired?'expired':$row->status,'stored_status'=>$row->status,'section_id'=>$row->section_id,'course_id'=>$row->course_id,'semester'=>$row->semester,'version'=>$row->version,'featured'=>(bool)$row->featured,'author'=>$d->user?->display_name ?? '已删除账号','author_id'=>$row->user_id,'created_at'=>$row->created_at,'updated_at'=>$row->updated_at,'comments'=>$d->comment_count,'fields'=>$payload,'can_edit'=>$this->canEdit($actor,$row),'can_moderate'=>$this->identity->moderator($actor,$row->section_id),'accepted_post_id'=>$row->accepted_post_id,'bookmarked'=>(bool)$bookmark,'following'=>(bool)($bookmark?->following ?? false)];
        if ($detail) {
            $out['body']=Post::query()->whereVisibleTo($actor)->where('discussion_id',$d->id)->where('number',1)->first()?->content;
            $out['answers']=Post::query()->whereVisibleTo($actor)->where('discussion_id',$d->id)->where('type','comment')->where('number','>',1)->with('user')->orderBy('number')->limit(200)->get()->map(fn($p)=>['id'=>$p->id,'body'=>$p->content,'author'=>$p->user?->display_name ?? '已删除账号','author_id'=>$p->user_id,'created_at'=>$p->created_at->toIso8601String()])->all();
            $out['history']=$this->db->table('campus_revisions')->where('record_id',$row->id)->orderByDesc('version')->get(['version','reason','created_at'])->all();
            $out['feedback']=$this->db->table('campus_feedback')->where('record_id',$row->id)->when(!$this->identity->moderator($actor,$row->section_id),fn($q)=>$q->where('user_id',$actor->id ?: -1))->get(['id','kind','message','status','resolution','created_at'])->all();
        }
        $out['useful']=$this->db->table('campus_votes')->where('record_id',$row->id)->count();
        $out['accepted_post_id']=$accepted;
        $out['voted']=!$actor->isGuest() && $this->db->table('campus_votes')->where('record_id',$row->id)->where('user_id',$actor->id)->exists();
        $out['following']=!$actor->isGuest() && $d->stateFor($actor)->subscription==='follow';
        return $out;
    }
    public function revision(object $row,User $actor,string $reason): void {
        $snapshot=(array)$row; $d=Discussion::query()->findOrFail($row->discussion_id); $snapshot['title']=$d->title; $snapshot['body']=Post::query()->where('discussion_id',$d->id)->where('number',1)->first()?->content;
        $this->db->table('campus_revisions')->insert(['record_id'=>$row->id,'actor_id'=>$actor->id,'version'=>$row->version,'snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'reason'=>$reason,'created_at'=>gmdate('Y-m-d H:i:s')]);
    }
}
