<?php
namespace UCASSer\Campus;
use Flarum\Http\RequestUtil;
use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
class CourseController implements RequestHandlerInterface
{
    public function __construct(private Records $records) {}
    public function handle(Request $r): ResponseInterface {
        $a=RequestUtil::getActor($r); $q=$r->getQueryParams(); $db=$this->records->db;
        $visible=array_values(array_filter($this->records->sections->visible($a),fn($s)=>$s->key==='courses')); $a->assertPermission(count($visible)>0); $section=$visible[0];
        if ($r->getMethod()!=='GET') {
            $a->assertPermission($this->records->identity->moderator($a,$section->id)); $d=$r->getParsedBody() ?? [];
            $v=[]; foreach (['name','type','grade','semester','nature','assessment','resources'] as $key) $v[$key]=$this->records->text($d,$key,in_array($key,['name','type','semester'])?2:0,in_array($key,['assessment','resources'])?2000:80); $reason=$this->records->text($d,'reason',5,500);
            $id=$db->transaction(function () use ($db,$q,$v,$d,$a,$reason) {
                if (isset($q['id'])) {
                    $row=$db->table('campus_courses')->where('id',(int)$q['id'])->lockForUpdate()->first(); if (!$row || ($d['version'] ?? null)!==$row->version) throw new ValidationException(['version'=>'课程已更新或不存在，请重新加载']);
                    $db->table('campus_courses')->where('id',$row->id)->update($v+['version'=>$row->version+1]); $id=$row->id;
                } else $id=$db->table('campus_courses')->insertGetId($v);
                $this->records->identity->audit($a,'course.save',(string)$id,$reason); return $id;
            }); return new JsonResponse(['data'=>$db->table('campus_courses')->where('id',$id)->first()],$r->getMethod()==='POST'?201:200);
        }
        $query=$db->table('campus_courses'); if (!empty($q['search'])) $query->where('name','like','%'.addcslashes(mb_substr((string)$q['search'],0,100),'\\%_').'%');
        foreach (['id','type','semester','grade'] as $key) if (!empty($q[$key])) $query->where($key,$q[$key]);
        $total=(clone $query)->count(); $page=max(1,min(10000,(int)($q['page'] ?? 1)));
        $courses=$query->orderBy('name')->offset(($page-1)*20)->limit(20)->get()->map(function ($course) use ($a,$q) {
            $reviews=$this->records->query($a)->where('course_id',$course->id)->where('type','review')->where('status','active')->when(!empty($q['review_semester']),fn($b)=>$b->where('semester',$q['review_semester']));
            $metrics=['COUNT(*) AS samples']; foreach (['overall','difficulty','workload'] as $key) $metrics[]="AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.$key')) AS DECIMAL(8,2))) AS $key";
            $stats=$reviews->selectRaw(implode(',',$metrics))->first(); $course->samples=(int)$stats->samples; $course->scores=[];
            foreach (['overall','difficulty','workload'] as $key) $course->scores[$key]=$course->samples?round((float)$stats->$key,2):null; return $course;
        })->all();
        $canManage=$this->records->identity->moderator($a,$section->id);
        return new JsonResponse(['data'=>$courses,'meta'=>['total'=>$total,'page'=>$page,'page_size'=>20,'can_manage'=>$canManage],'definitions'=>['overall'=>'1 不满意，5 满意','difficulty'=>'1 易，5 难','workload'=>'1 少，5 多','sample'=>'只统计可见且未撤回的评价；少量样本不能代表课程全貌。主观评分不等于客观教学质量。']]);
    }
}
