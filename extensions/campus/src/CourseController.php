<?php
namespace UCASSer\Campus;
use Flarum\Http\RequestUtil;
use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
/**
 * Course directory and review stats are decoupled from the retired
 * campus-courses discussion board: browsing is open to anyone who may read the
 * site, maintenance requires the independent operator role, and reviews keep
 * working through the internal archive categorization.
 */
class CourseController implements RequestHandlerInterface
{
    public function __construct(private Records $records, private OperatorAccess $operators) {}
    public function handle(Request $r): ResponseInterface {
        $a=RequestUtil::getActor($r); $q=$r->getQueryParams(); $db=$this->records->db;
        $canManage=$a->isAdmin() || $this->operators->allowed($a);
        if ($r->getMethod()!=='GET') {
            // Course maintenance keeps its original administrator path and adds
            // the independent operator role; ordinary accounts stay locked out.
            if (!$a->isAdmin()) $this->operators->assert($a); $d=$r->getParsedBody() ?? [];
            $v=[]; foreach (['name','type','grade','semester','nature','assessment','resources'] as $key) $v[$key]=$this->records->text($d,$key,in_array($key,['name','type','semester'])?2:0,in_array($key,['assessment','resources'])?2000:80); $reason=$this->records->text($d,'reason',5,500);
            $collegeId=null;
            if (!empty($d['college_id'])) {
                $collegeId=(int)$d['college_id'];
                if (!$db->table('campus_colleges')->where('id',$collegeId)->exists()) throw new ValidationException(['college_id'=>'请选择已维护的学院']);
                $v['college_id']=$collegeId;
            }
            $id=$db->transaction(function () use ($db,$q,$v,$d,$a,$reason) {
                if (isset($q['id'])) {
                    $row=$db->table('campus_courses')->where('id',(int)$q['id'])->lockForUpdate()->first(); if (!$row || ($d['version'] ?? null)!==$row->version) throw new ValidationException(['version'=>'课程已更新或不存在，请重新加载']);
                    $db->table('campus_courses')->where('id',$row->id)->update($v+['version'=>$row->version+1]); $id=$row->id;
                } else $id=$db->table('campus_courses')->insertGetId($v);
                $this->records->identity->audit($a,'course.save',(string)$id,$reason); return $id;
            }); return new JsonResponse(['data'=>$db->table('campus_courses')->where('id',$id)->first()],$r->getMethod()==='POST'?201:200);
        }
        $hasOfferings=$db->getSchemaBuilder()->hasTable('campus_course_offerings');
        $query=$db->table('campus_courses');
        if (!empty($q['search'])) {
            $term='%'.addcslashes(mb_substr((string)$q['search'],0,100),'\\%_').'%';
            $query->where(function ($where) use ($db,$hasOfferings,$term) {
                $where->where('name','like',$term);
                if ($hasOfferings) $where->orWhereIn('id',$db->table('campus_course_offerings')->select('course_id')
                    ->where('code','like',$term)->orWhere('college','like',$term));
            });
        }
        foreach (['id','type','semester','grade'] as $key) if (!empty($q[$key])) $query->where($key,$q[$key]);
        if ($hasOfferings && !empty($q['college'])) $query->whereIn('id',$db->table('campus_course_offerings')->select('course_id')->where('college',$q['college']));
        $total=(clone $query)->count(); $page=max(1,min(10000,(int)($q['page'] ?? 1)));
        $pageRows=$query->orderBy('name')->offset(($page-1)*20)->limit(20)->get();
        $offers=$hasOfferings ? $db->table('campus_course_offerings')->whereIn('course_id',$pageRows->pluck('id'))->get(['course_id','code','college','semester','resource_category_id','nature','description','college_id'])->keyBy('course_id') : collect();
        $colleges=$db->getSchemaBuilder()->hasTable('campus_colleges') ? $db->table('campus_colleges')->where('status','active')->orderBy('position')->get(['id','code','name'])->all() : [];
        $types=$db->table('campus_courses')->whereNotNull('type')->where('type','<>','')->distinct()->orderBy('type')->pluck('type')->all();
        $semesters=$db->table('campus_courses')->whereNotNull('semester')->where('semester','<>','')->distinct()->orderByDesc('semester')->pluck('semester')->all();
        $courses=$pageRows->map(function ($course) use ($a,$q,$offers) {
            $course->offer=$offers->get($course->id);
            $reviews=$this->records->query($a)->where('course_id',$course->id)->where('type','review')->where('status','active')->when(!empty($q['review_semester']),fn($b)=>$b->where('semester',$q['review_semester']));
            $metrics=['COUNT(*) AS samples']; foreach (['overall','difficulty','workload'] as $key) $metrics[]="AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.$key')) AS DECIMAL(8,2))) AS $key";
            $stats=$reviews->selectRaw(implode(',',$metrics))->first(); $course->samples=(int)$stats->samples; $course->scores=[];
            foreach (['overall','difficulty','workload'] as $key) $course->scores[$key]=$course->samples?round((float)$stats->$key,2):null; return $course;
        })->all();
        return new JsonResponse(['data'=>$courses,'meta'=>['total'=>$total,'page'=>$page,'page_size'=>20,'can_manage'=>$canManage,'colleges'=>$colleges,'types'=>$types,'semesters'=>$semesters],'definitions'=>['overall'=>'1 不满意，5 满意','difficulty'=>'1 易，5 难','workload'=>'1 少，5 多','sample'=>'只统计可见且未撤回的评价；少量样本不能代表课程全貌。主观评分不等于客观教学质量。']]);
    }
}
