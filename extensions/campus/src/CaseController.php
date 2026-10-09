<?php
namespace UCASSer\Campus;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;
use Flarum\Api\Client;
use Flarum\Http\RequestUtil;
use Flarum\Foundation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
class CaseController implements RequestHandlerInterface
{
    public function __construct(private Cases $cases,private Client $client,private \Illuminate\Contracts\Events\Dispatcher $events,private \Flarum\Notification\NotificationSyncer $notices) {}
    private function event(int $id,User $a,string $action,string $message): void { $this->cases->records->db->table('campus_case_events')->insert(['case_id'=>$id,'actor_id'=>$a->id,'action'=>$action,'message'=>$message,'created_at'=>gmdate('Y-m-d H:i:s')]); }
    private function notify(object $row,string $action): void { foreach (array_unique([$row->reporter_id,$row->subject_user_id]) as $id) if ($id && ($u=User::query()->find($id))) $this->notices->sync(new AccountNotice($u,$action,$row->id),[$u]); }
    public function handle(Request $r): ResponseInterface {
        $a=RequestUtil::getActor($r); $a->assertRegistered(); $q=$r->getQueryParams(); $d=$r->getParsedBody() ?? []; $route=$r->getAttribute('routeName'); $records=$this->cases->records; $db=$records->db;
        if ($route==='campus.cases') {
            $page=max(1,min(10000,(int)($q['page'] ?? 1))); $query=$db->table('campus_cases');
            if (!$a->isAdmin()) $query->where(fn($b)=>$b->where('reporter_id',$a->id)->orWhere('subject_user_id',$a->id)->orWhereIn('section_id',$db->table('campus_moderators')->where('user_id',$a->id)->select('section_id')));
            $rows=$query->orderByDesc('id')->offset(($page-1)*20)->limit(20)->get()->filter(fn($row)=>$this->cases->visible($a,$row))->map(fn($row)=>$this->cases->serialize($a,$row))->values()->all();
            return new JsonResponse(['data'=>$rows,'meta'=>['page'=>$page,'page_size'=>20]]);
        }
        if ($route==='campus.cases.create') {
            $reason=$records->text($d,'reason',5,2000); $discussion=Discussion::query()->whereVisibleTo($a)->with('tags')->findOrFail((int)($d['discussion_id'] ?? 0));
            $type=$d['target_type'] ?? 'discussion'; $target=(int)($d['target_id'] ?? $discussion->id); $subject=$discussion->user_id;
            if ($type==='post') { $post=Post::query()->whereVisibleTo($a)->where('discussion_id',$discussion->id)->findOrFail($target); $subject=$post->user_id; }
            elseif ($type==='user') { User::query()->findOrFail($target); if ($target!==$discussion->user_id && !Post::query()->whereVisibleTo($a)->where('discussion_id',$discussion->id)->where('user_id',$target)->exists()) throw new ValidationException(['target_id'=>'用户行为举报需要可见的讨论上下文']); $subject=$target; }
            elseif ($type!=='discussion' || $target!==$discussion->id) throw new ValidationException(['target_type'=>'举报对象无效']);
            $tags=$discussion->tags->pluck('id')->all(); $section=array_values(array_filter($records->sections->visible($a),fn($s)=>in_array($s->tag_id,$tags)))[0] ?? null; if (!$section) throw new ValidationException(['discussion_id'=>'该讨论没有可举报的校园分区']);
            $id=$db->transaction(function () use ($db,$a,$type,$target,$subject,$discussion,$section,$reason) {
                User::query()->where('id',$a->id)->lockForUpdate()->first();
                if ($db->table('campus_cases')->where('reporter_id',$a->id)->where('target_type',$type)->where('target_id',$target)->whereIn('status',['pending','in_progress','needs_info','appealed'])->exists()) throw new ValidationException(['reason'=>'已有进行中的举报，请补充原记录']);
                $id=$db->table('campus_cases')->insertGetId(['reporter_id'=>$a->id,'subject_user_id'=>$subject,'discussion_id'=>$discussion->id,'section_id'=>$section->id,'target_type'=>$type,'target_id'=>$target,'reason'=>$reason,'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')]); $this->event($id,$a,'created',$reason); return $id;
            }); return new JsonResponse(['data'=>$this->cases->serialize($a,$db->table('campus_cases')->where('id',$id)->first())],201);
        }
        $row=$db->table('campus_cases')->where('id',(int)$q['id'])->first(); if (!$row || !$this->cases->visible($a,$row)) throw new ModelNotFoundException;
        if ($r->getMethod()==='GET') return new JsonResponse(['data'=>$this->cases->serialize($a,$row)]);
        $reason=$records->text($d,'reason',5,500);
        $db->transaction(function () use ($db,$r,$a,$row,$d,$route,$reason) {
            $locked=$db->table('campus_cases')->where('id',$row->id)->lockForUpdate()->first(); if (($d['version'] ?? null)!==$locked->version) throw new ValidationException(['version'=>'举报状态已变化，请重新加载']);
            if ($route==='campus.case.appeal') {
                $a->assertPermission($locked->reporter_id===$a->id || $locked->subject_user_id===$a->id); if (!in_array($locked->status,['resolved','rejected'],true)) throw new ValidationException(['status'=>'当前状态不可申诉']);
                $status='appealed'; $extra=[]; $this->event($row->id,$a,'appealed',$reason);
            } elseif ($route==='campus.case.supplement') {
                $a->assertPermission($locked->reporter_id===$a->id); if (!in_array($locked->status,['pending','needs_info'],true)) throw new ValidationException(['status'=>'当前状态不可补充']);
                $status='pending'; $extra=[]; $this->event($row->id,$a,'supplement',$reason);
            } else {
                $a->assertPermission($this->cases->manages($a,$locked));
                $a->assertPermission($a->id!==$locked->reporter_id && $a->id!==$locked->subject_user_id);
                $status=$d['status'] ?? '';
                if ($locked->status==='appealed') { $a->assertPermission($a->id!==$locked->processor_id); if ($status!=='reviewed') throw new ValidationException(['status'=>'申诉应由独立人员复核']); $extra=['reviewer_id'=>$a->id]; }
                else { if (!in_array($locked->status,['pending','in_progress','needs_info'],true) || !in_array($status,['in_progress','needs_info','resolved','rejected'],true)) throw new ValidationException(['status'=>'处理状态变化无效']); $extra=['processor_id'=>$a->id]; }
                $action=$d['action'] ?? 'none';
                if ($action!=='none') {
                    if (!in_array($action,['hide','restore','delete','suspend','unban'],true)) throw new ValidationException(['action'=>'处置操作无效']);
                    if (in_array($action,['delete','suspend','unban'],true)) $a->assertAdmin();
                    if ($action==='delete' && ($d['confirmed'] ?? false)!==true) throw new ValidationException(['confirmed'=>'永久删除必须明确确认']);
                    if (in_array($action,['suspend','unban'],true)) {
                        $user=User::query()->findOrFail($locked->subject_user_id); $attributes=['suspendedUntil'=>$action==='unban'?null:($d['until'] ?? null),'suspendReason'=>$reason,'suspendMessage'=>$reason];
                        if ($action==='suspend' && !is_string($attributes['suspendedUntil'])) throw new ValidationException(['until'=>'封禁必须指定期限']);
                        $this->client->withParentRequest($r)->withoutErrorHandling()->withBody(['data'=>['type'=>'users','id'=>(string)$user->id,'attributes'=>$attributes]])->patch('/users/'.$user->id);
                    } else {
                        $model=$locked->target_type==='post'?Post::query()->findOrFail($locked->target_id):Discussion::query()->findOrFail($locked->discussion_id);
                        if ($action==='delete') $this->client->withParentRequest($r)->withoutErrorHandling()->delete(($locked->target_type==='post'?'/posts/':'/discussions/').$model->id);
                        else {if ($action==='hide') $model->hide($a); else $model->restore(); $model->save(); foreach ($model->releaseEvents() as $event) $this->events->dispatch($event);}
                    }
                    $this->cases->records->identity->audit($a,'case.'.$action,(string)$row->id,$reason);
                }
                $extra['resolution']=$reason; $this->event($row->id,$a,$status,$reason); $this->notify($locked,'case');
            }
            $db->table('campus_cases')->where('id',$row->id)->update($extra+['status'=>$status,'version'=>$locked->version+1,'updated_at'=>gmdate('Y-m-d H:i:s')]);
        }); return new JsonResponse(['data'=>$this->cases->serialize($a,$db->table('campus_cases')->where('id',$row->id)->first())]);
    }
}
