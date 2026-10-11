<?php
namespace UCASSer\Campus;
use Flarum\Http\RequestUtil;
use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
class GovernanceController implements RequestHandlerInterface
{
    public function __construct(private Records $records) {}
    public function handle(Request $r): ResponseInterface {
        $a=RequestUtil::getActor($r); $db=$this->records->db; $q=$r->getQueryParams();
        if ($r->getMethod()==='POST') {
            $a->assertAdmin(); $d=$r->getParsedBody() ?? []; $type=$d['type'] ?? '';
            if (!in_array($type,['rule','team','decision','maintenance','recruit'],true)) throw new ValidationException(['type'=>'治理记录类型无效']);
            $title=$this->records->text($d,'title',3,80); $body=$this->records->text($d,'body',5,30000); $time=$this->records->text($d,'effective_at',16,16);
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$time,new \DateTimeZone('UTC')); if (!$date || $date->format('Y-m-d\TH:i')!==$time) throw new ValidationException(['effective_at'=>'请填写有效 UTC 生效时间']);
            $id=$db->transaction(function () use ($db,$a,$type,$title,$body,$date) {
                $id=$db->table('campus_governance')->insertGetId(['type'=>$type,'title'=>$title,'body'=>$body,'actor_id'=>$a->id,'effective_at'=>$date->format('Y-m-d H:i:s'),'created_at'=>gmdate('Y-m-d H:i:s')]);
                $this->records->identity->audit($a,'governance.publish',(string)$id,'发布公开治理版本'); return $id;
            }); return new JsonResponse(['data'=>['id'=>$id]],201);
        }
        $page=max(1,min(10000,(int)($q['page'] ?? 1))); $visible=$this->records->query($a); $stats=[];
        foreach (Records::CATEGORIES as $type=>$items) $stats[$type]=(clone $visible)->where('type',$type)->count();
        $stats['resolved_questions']=(clone $visible)->where('type','question')->where('status','resolved')->count();
        $reports=$db->table('campus_cases')->whereIn('status',['resolved','rejected','reviewed'])->count();
        $sections=$this->records->sections->visible($a); $team=[];
        foreach ($sections as $s) $team[]=['section'=>$s->name,'volunteers'=>$db->table('campus_moderators')->where('section_id',$s->id)->distinct()->count('user_id')];
        return new JsonResponse(['data'=>['entries'=>$db->table('campus_governance')->where('effective_at','<=',gmdate('Y-m-d H:i:s'))->orderByDesc('id')->offset(($page-1)*20)->limit(20)->get(['id','type','title','body','effective_at','created_at'])->all(),'statistics'=>$stats,'reports'=>['closed'=>$reports>=5?$reports:null,'note'=>'全站仅展示至少 5 条的汇总，不公开举报人、对象或小分组统计。'],'team'=>$team,'can_publish'=>$a->isAdmin(),'page'=>$page,'development'=>getenv('APP_ENV')==='development']]);
    }
}
