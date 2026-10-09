<?php
namespace UCASSer\Campus;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
class IdentityController implements RequestHandlerInterface
{
    public function __construct(private Identity $identity, private Sections $sections, private \Illuminate\Contracts\Events\Dispatcher $events,private \Flarum\Notification\NotificationSyncer $notices) {}
    private function reason(array $data): string
    {
        $reason = $data['reason'] ?? '';
        if (!is_string($reason) || mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 500) throw new ValidationException(['reason'=>'请填写 5–500 字的处理理由']);
        return trim($reason);
    }
    public function handle(Request $r): ResponseInterface
    {
        $a = RequestUtil::getActor($r); $a->assertRegistered();
        $route = $r->getAttribute('routeName'); $db = $this->identity->db; $data = $r->getParsedBody() ?? [];
        if ($route === 'campus.identity') {
            $rows = $db->table('campus_verifications')->where('user_id',$a->id)->orderByDesc('id')->get(['id','status','reason','created_at','reviewed_at'])->all();
            return new JsonResponse(['data'=>['verified'=>$a->hasPermission('campus.verified'),'requests'=>$rows,'moderationSections'=>$db->table('campus_moderators')->where('user_id',$a->id)->pluck('section_id')->all()]]);
        }
        if ($route === 'campus.identity.submit') {
            if (($data['consent'] ?? null) !== true || !is_string($data['evidence'] ?? null) || mb_strlen(trim($data['evidence'])) < 5 || mb_strlen($data['evidence']) > 1000) throw new ValidationException(['evidence'=>'请确认用途并提交 5–1000 字的最少必要验证说明，不要上传身份证件']);
            $id = $db->transaction(function () use ($db,$a,$data) {
                User::query()->where('id',$a->id)->lockForUpdate()->first();
                if ($db->table('campus_verifications')->where('user_id',$a->id)->where('status','pending')->exists()) throw new ValidationException(['evidence'=>'已有待审核申请，请等待处理']);
                if ($a->hasPermission('campus.verified')) throw new ValidationException(['evidence'=>'已经验证，无需重复申请']);
                return $db->table('campus_verifications')->insertGetId(['user_id'=>$a->id,'evidence'=>trim($data['evidence']),'created_at'=>date('Y-m-d H:i:s')]);
            });
            return new JsonResponse(['data'=>['id'=>$id,'status'=>'pending']],201);
        }
        if ($route === 'campus.identity.review') {
            $a->assertAdmin(); $id=(int)($r->getQueryParams()['id'] ?? 0); $reason=$this->reason($data);
            if (!in_array($data['status'] ?? null,['approved','rejected','revoked'],true)) throw new ValidationException(['status'=>'请选择批准、拒绝或撤销']);
            $db->transaction(function () use ($db,$a,$data,$id,$reason) {
                $row=$db->table('campus_verifications')->where('id',$id)->lockForUpdate()->first(); if (!$row) throw new ModelNotFoundException;
                if (($data['status']==='revoked' && $row->status!=='approved') || ($data['status']!=='revoked' && $row->status!=='pending')) throw new ValidationException(['status'=>'申请状态已变化，请重新加载']);
                $u=User::query()->findOrFail($row->user_id); $group=$this->identity->group('verified');
                if ($data['status']==='approved') $u->groups()->syncWithoutDetaching([$group]); else $u->groups()->detach($group);
                $db->table('campus_verifications')->where('id',$id)->update(['status'=>$data['status'],'evidence'=>null,'reason'=>$reason,'reviewer_id'=>$a->id,'reviewed_at'=>date('Y-m-d H:i:s')]);
                $this->identity->audit($a,'verification.'.$data['status'],(string)$id,$reason);
                $this->notices->sync(new AccountNotice($u,'identity',$id),[$u]);
            });
            return new JsonResponse(['data'=>['id'=>$id,'status'=>$data['status']]]);
        }
        if ($route === 'campus.identity.queue') {
            $a->assertAdmin(); return new JsonResponse(['data'=>$db->table('campus_verifications')->where('status','pending')->orderBy('id')->limit(50)->get()->all()]);
        }
        if ($route === 'campus.moderators') {
            $a->assertAdmin(); $reason=$this->reason($data); $user=User::query()->findOrFail((int)($data['user_id'] ?? 0)); $s=$this->sections->byId((int)($data['section_id'] ?? 0));
            if (!is_bool($data['enabled'] ?? null)) throw new ValidationException(['enabled'=>'请选择授权或撤销']);
            $db->transaction(function () use ($db,$data,$a,$user,$s,$reason) {
                if ($data['enabled']) {
                    $db->table('campus_moderators')->insertOrIgnore(['user_id'=>$user->id,'section_id'=>$s->id]); $user->groups()->syncWithoutDetaching([$this->identity->group('volunteer')]);
                } else {
                    $db->table('campus_moderators')->where('user_id',$user->id)->where('section_id',$s->id)->delete();
                    if (!$db->table('campus_moderators')->where('user_id',$user->id)->exists()) $user->groups()->detach($this->identity->group('volunteer'));
                }
                $this->identity->audit($a,'moderator.'.($data['enabled']?'grant':'revoke'),$user->id.':'.$s->id,$reason);
            });
            return new JsonResponse(['data'=>['enabled'=>$data['enabled']]]);
        }
        if ($route === 'campus.moderate') {
            $d=Discussion::query()->findOrFail((int)($data['discussion_id'] ?? 0)); $reason=$this->reason($data);
            $ids=$d->tags->pluck('id')->all(); $managed=array_filter($this->sections->rows(),fn($s)=>in_array($s->tag_id,$ids));
            $a->assertPermission($a->isAdmin() || (!$d->is_private && count($managed)>0 && array_reduce($managed,fn($allowed,$s)=>$allowed && $this->identity->moderator($a,$s->id),true)));
            if (!in_array($data['action'] ?? null,['hide','restore'],true)) throw new ValidationException(['action'=>'仅支持隐藏与恢复']);
            $db->transaction(function () use ($d,$a,$data,$reason) {
                if ($data['action']==='hide') $d->hide($a); else $d->restore(); $d->save();
                foreach ($d->releaseEvents() as $event) $this->events->dispatch($event);
                $this->identity->audit($a,'discussion.'.$data['action'],(string)$d->id,$reason);
            });
            return new JsonResponse(['data'=>['id'=>$d->id,'action'=>$data['action']]]);
        }
        $a->assertAdmin(); return new JsonResponse(['data'=>$db->table('campus_audit')->orderByDesc('id')->limit(100)->get()->all()]);
    }
}
