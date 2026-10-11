<?php
namespace UCASSer\Campus;
use Flarum\Api\Client;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Http\RequestUtil;
use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
class RecordController implements RequestHandlerInterface
{
    public function __construct(private Records $records,private Client $client,private \Illuminate\Contracts\Events\Dispatcher $events,private \Flarum\Notification\NotificationSyncer $notices,private \Flarum\Http\UrlGenerator $urls) {}
    private function notify(object $row,\Flarum\User\User $actor,string $action,?int $recipient): void {
        if (!$recipient || $recipient===$actor->id) return; $user=\Flarum\User\User::query()->find($recipient);
        if ($user) $this->notices->sync(new Notice(Discussion::query()->findOrFail($row->discussion_id),$actor,$action,$row->id),[$user]);
    }
    private function native(Request $r,string $method,string $path,array $data): array {
        $response=$this->client->withParentRequest($r)->withoutErrorHandling()->withBody(['data'=>$data])->send($method,$path);
        if ($response->getStatusCode()>=400) throw new \RuntimeException('Native content operation failed'); return json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);
    }
    public function handle(Request $r): ResponseInterface {
        $a=RequestUtil::getActor($r); $q=$r->getQueryParams(); $data=$r->getParsedBody() ?? []; $route=$r->getAttribute('routeName'); $db=$this->records->db;
        if ($route==='campus.catalog') return new JsonResponse(['data'=>['categories'=>$this->records->categories(),'states'=>Records::STATES,'fields'=>Records::FIELDS,'sections'=>$this->records->sections->visible($a),'anonymousReviews'=>false]]);
        if (str_starts_with($route,'campus.categories')) {
            $a->assertAdmin(); if ($r->getMethod()==='PATCH') { $name=$this->records->text($data,'name',2,80); $type=$data['type'] ?? ''; $key=$data['key'] ?? ''; if (!isset(Records::CATEGORIES[$type][$key]) || !is_bool($data['enabled'] ?? null)) throw new ValidationException(['category'=>'分类或开放状态无效']); $db->table('campus_categories')->where('type',$type)->where('key',$key)->update(['name'=>$name,'enabled'=>$data['enabled']]); }
            return new JsonResponse(['data'=>$db->table('campus_categories')->get()->all()]);
        }
        if ($route==='campus.records' && $r->getMethod()==='GET') {
            $query=$this->records->query($a);
            foreach (['type','category','course_id','semester','section_id'] as $key) if (!empty($q[$key])) $query->where($key,$q[$key]);
            if (!empty($q['status'])) Records::statusFilter($query,(string)$q['status']);
            foreach (['course','topic','grade','tags'] as $key) if (!empty($q[$key])) $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.$key')) LIKE ?",['%'.addcslashes(mb_substr((string)$q[$key],0,100),'\\%_').'%']);
            if (($q['bookmarked'] ?? '')==='1') { $a->assertRegistered(); $query->whereIn('id',$db->table('campus_bookmarks')->where('user_id',$a->id)->select('record_id')); }
            if (($q['mine'] ?? '')==='1') { $a->assertRegistered(); $query->where('user_id',$a->id); }
            if (!empty($q['search'])) {
                $pattern='%'.addcslashes(mb_substr((string)$q['search'],0,100),'\\%_').'%';
                $query->where(fn($b)=>$b->where('data','like',$pattern)->orWhereIn('discussion_id',Discussion::query()->whereVisibleTo($a)->where('title','like',$pattern)->select('id'))->orWhereIn('discussion_id',Post::query()->whereVisibleTo($a)->where('number',1)->where('content','like',$pattern)->select('discussion_id')));
            }
            $total=(clone $query)->count(); $page=max(1,min(10000,(int)($q['page'] ?? 1)));
            $rows=$query->orderByDesc('featured')->orderByDesc('id')->offset(($page-1)*20)->limit(20)->get();
            return new JsonResponse(['data'=>$rows->map(fn($row)=>$this->records->serialize($a,$row))->all(),'meta'=>['total'=>$total,'page'=>$page,'page_size'=>20]]);
        }
        if ($route==='campus.record' && $r->getMethod()==='GET') return new JsonResponse(['data'=>$this->records->serialize($a,$this->records->find($a,(int)$q['id']),true)]);
        if ($route==='campus.revision') {
            $row=$this->records->find($a,(int)$q['id']); $a->assertPermission($this->records->canEdit($a,$row)); $history=$db->table('campus_revisions')->where('record_id',$row->id)->where('version',(int)$q['version'])->first(); if (!$history) throw new \Illuminate\Database\Eloquent\ModelNotFoundException;
            $snapshot=json_decode($history->snapshot,true,512,JSON_THROW_ON_ERROR); return new JsonResponse(['data'=>['version'=>$history->version,'title'=>$snapshot['title'],'body'=>$snapshot['body'],'fields'=>json_decode($snapshot['data'],true,512,JSON_THROW_ON_ERROR),'reason'=>$history->reason]]);
        }
        $a->assertRegistered();
        if ($route==='campus.records.create') {
            $v=$this->records->validate($a,$data);
            $id=$db->transaction(function () use ($r,$a,$v,$db,$data) {
                \Flarum\User\User::query()->where('id',$a->id)->lockForUpdate()->firstOrFail();
                $this->records->validate($a,$data);
                if ($db->table('campus_records')->where('user_id',$a->id)->where('type',$v['type'])->where('data',$v['data'])
                    ->whereIn('discussion_id',Discussion::query()->where('title',$v['title'])->select('id'))->exists()) throw new ValidationException(['title'=>'相同内容已经发布，请查看或编辑原记录']);
                $s=$this->records->sections->byId($v['section_id']);
                $native=$this->native($r,'POST','/discussions',['type'=>'discussions','attributes'=>['title'=>$v['title'],'content'=>$v['body']],'relationships'=>['tags'=>['data'=>[['type'=>'tags','id'=>(string)$s->tag_id]]]]]);
                $values=$v; unset($values['title'],$values['body']); $values+=['discussion_id'=>(int)$native['data']['id'],'user_id'=>$a->id,'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')];
                $id=$db->table('campus_records')->insertGetId($values);
                $this->native($r,'PATCH','/discussions/'.$values['discussion_id'],['type'=>'discussions','id'=>(string)$values['discussion_id'],'attributes'=>['subscription'=>'follow']]);
                return $id;
            }); return new JsonResponse(['data'=>$this->records->serialize($a,$this->records->find($a,$id),true)],201);
        }
        $row=$this->records->find($a,(int)($q['id'] ?? 0));
        if ($route==='campus.record.update') {
            $a->assertPermission($this->records->canEdit($a,$row)); $a->assertCan('reply',Discussion::query()->findOrFail($row->discussion_id)); $reason=$this->records->text($data,'reason',5,500); $v=$this->records->validate($a,$data,$row);
            $db->transaction(function () use ($r,$a,$row,$v,$db,$data,$reason) {
                $locked=$db->table('campus_records')->where('id',$row->id)->lockForUpdate()->first(); if (($data['version'] ?? null)!==$locked->version) throw new ValidationException(['version'=>'内容已经更新，请重新加载再编辑']);
                $this->records->revision($locked,$a,$reason);
                if ($a->isAdmin() || (int)$row->user_id===$a->id) {
                    $s=$this->records->sections->byId($v['section_id']);
                    $this->native($r,'PATCH','/discussions/'.$row->discussion_id,['type'=>'discussions','id'=>(string)$row->discussion_id,'attributes'=>['title'=>$v['title']],'relationships'=>['tags'=>['data'=>[['type'=>'tags','id'=>(string)$s->tag_id]]]]]);
                    $post=Post::query()->where('discussion_id',$row->discussion_id)->where('number',1)->firstOrFail(); $this->native($r,'PATCH','/posts/'.$post->id,['type'=>'posts','id'=>(string)$post->id,'attributes'=>['content'=>$v['body']]]);
                } else {
                    if ($v['title']!==Discussion::query()->findOrFail($row->discussion_id)->title || $v['body']!==Post::query()->where('discussion_id',$row->discussion_id)->where('number',1)->first()?->content || $v['section_id']!==$row->section_id) throw new ValidationException(['body'=>'分区志愿者维护结构化字段与状态，正文由作者或管理员编辑']);
                }
                unset($v['title'],$v['body']); $v['version']=$locked->version+1; $v['updated_at']=gmdate('Y-m-d H:i:s'); $db->table('campus_records')->where('id',$row->id)->update($v); $this->records->identity->audit($a,'record.edit',(string)$row->id,$reason);
                if ($row->type==='review' && $row->status!==$v['status']) {
                    $a->assertPermission($a->isAdmin() || (int)$row->user_id===$a->id); $d=Discussion::query()->findOrFail($row->discussion_id);
                    if ($v['status']==='withdrawn') $d->hide($a); else { $a->assertPermission($a->isAdmin() || $d->hidden_user_id===$a->id); $d->restore(); }
                    $d->save(); foreach ($d->releaseEvents() as $event) $this->events->dispatch($event);
                }
            });
        } elseif ($route==='campus.useful') {
            if ($row->type!=='review' || $row->status!=='active') throw new ValidationException(['type'=>'只能对有效课程评价提交有用反馈']);
            $a->assertPermission((int)$row->user_id!==$a->id); if (!is_bool($data['enabled'] ?? null)) throw new ValidationException(['enabled'=>'反馈状态无效']);
            if ($data['enabled']) $db->table('campus_votes')->insertOrIgnore(['record_id'=>$row->id,'user_id'=>$a->id]); else $db->table('campus_votes')->where('record_id',$row->id)->where('user_id',$a->id)->delete();
        } elseif ($route==='campus.bookmark') {
            if (!is_bool($data['enabled'] ?? null) || !is_bool($data['following'] ?? false)) throw new ValidationException(['enabled'=>'收藏状态无效']);
            $db->transaction(function () use ($r,$a,$row,$data,$db) {
                if ($data['enabled']) $db->table('campus_bookmarks')->updateOrInsert(['user_id'=>$a->id,'record_id'=>$row->id],['following'=>$data['following'] ?? false]); else $db->table('campus_bookmarks')->where('user_id',$a->id)->where('record_id',$row->id)->delete();
                if (array_key_exists('following',$data)) $this->native($r,'PATCH','/discussions/'.$row->discussion_id,['type'=>'discussions','id'=>(string)$row->discussion_id,'attributes'=>['subscription'=>$data['following']?'follow':null]]);
            });
        } elseif ($route==='campus.comment') {
            $body=$this->records->text($data,'body',5,30000);
            $db->transaction(function () use ($r,$row,$body,$db) {
                $this->native($r,'POST','/posts',['type'=>'posts','attributes'=>['content'=>$body],'relationships'=>['discussion'=>['data'=>['type'=>'discussions','id'=>(string)$row->discussion_id]]]]);
                if ($row->type==='question') $db->table('campus_records')->where('id',$row->id)->where('status','waiting')->update(['status'=>'discussing']);
            });
        } elseif ($route==='campus.accept') {
            $a->assertPermission((int)$row->user_id===$a->id); if ($row->type!=='question') throw new ValidationException(['type'=>'只有问题可以采纳答案']);
            $post=null;
            if (($data['post_id'] ?? null)!==null) $post=Post::query()->whereVisibleTo($a)->whereNull('hidden_at')->where('discussion_id',$row->discussion_id)->where('type','comment')->where('number','>',1)->findOrFail((int)$data['post_id']);
            $db->transaction(function () use ($db,$row,$a,$post) {
                $locked=$db->table('campus_records')->where('id',$row->id)->lockForUpdate()->first(); $this->records->revision($locked,$a,$post?'提问者采纳答案':'提问者取消采纳答案');
                $db->table('campus_records')->where('id',$row->id)->update(['accepted_post_id'=>$post?->id,'status'=>$post?'resolved':'discussing','version'=>$locked->version+1,'updated_at'=>gmdate('Y-m-d H:i:s')]);
                $this->records->identity->audit($a,$post?'question.accept':'question.reopen',(string)$row->id);
                if ($post) $this->notify($row,$a,'accepted',$post->user_id);
            });
        } elseif ($route==='campus.convert') {
            $a->assertPermission($this->records->identity->moderator($a,$row->section_id)); if ($row->type!=='question') throw new ValidationException(['type'=>'只能将问答整理为资料']);
            $post=Post::query()->whereVisibleTo($a)->whereNull('hidden_at')->where('discussion_id',$row->discussion_id)->where('type','comment')->where('number','>',1)->findOrFail((int)($data['post_id'] ?? 0));
            $target=$this->records->sections->byId((int)($data['section_id'] ?? 0)); $source=$this->records->sections->byId($row->section_id);
            $a->assertPermission($this->records->identity->moderator($a,$target->id));
            if ($source->visibility!==$target->visibility) throw new ValidationException(['section_id'=>'转换必须保留原访问范围，请先协调分区设置']);
            $copy=$data; $copy['type']='resource'; $copy['body']=$post->content; $copy['related']=[$row->id]; $copy['source']='问答整理：'.Discussion::query()->findOrFail($row->discussion_id)->title;
            $copy['source_url']=$this->urls->to('forum')->path('d/'.$row->discussion_id.'/'.$post->number);
            $v=$this->records->validate($a,$copy);
            $new=$db->transaction(function () use ($db,$r,$a,$v,$target,$row) {
                $native=$this->native($r,'POST','/discussions',['type'=>'discussions','attributes'=>['title'=>$v['title'],'content'=>$v['body']],'relationships'=>['tags'=>['data'=>[['type'=>'tags','id'=>(string)$target->tag_id]]]]]); unset($v['title'],$v['body']);
                $id=$db->table('campus_records')->insertGetId($v+['discussion_id'=>(int)$native['data']['id'],'user_id'=>$a->id,'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')]); $this->records->identity->audit($a,'question.convert',$row->id.':'.$id,'保留原答案出处与访问范围'); return $id;
            }); return new JsonResponse(['data'=>$this->records->serialize($a,$this->records->find($a,$new),true)],201);
        } elseif ($route==='campus.feedback') {
            $message=$this->records->text($data,'message',5,2000); if (!in_array($data['kind'] ?? '',['correction','invalid_link','source','report'],true)) throw new ValidationException(['kind'=>'反馈类型无效']);
            $db->table('campus_feedback')->insert(['record_id'=>$row->id,'user_id'=>$a->id,'kind'=>$data['kind'],'message'=>$message,'created_at'=>gmdate('Y-m-d H:i:s')]);
        } elseif ($route==='campus.resolveFeedback') {
            $a->assertPermission($this->records->identity->moderator($a,$row->section_id)); $reason=$this->records->text($data,'reason',5,500); if (!in_array($data['status'] ?? '',['resolved','rejected'],true)) throw new ValidationException(['status'=>'处理状态无效']);
            $db->transaction(function () use ($db,$data,$row,$a,$reason) {
                $changed=$db->table('campus_feedback')->where('id',(int)($data['feedback_id'] ?? 0))->where('record_id',$row->id)->where('status','pending')->update(['status'=>$data['status'],'resolution'=>$reason,'reviewer_id'=>$a->id]);
                if (!$changed) throw new ValidationException(['feedback_id'=>'反馈不存在或已处理']); $this->records->identity->audit($a,'feedback.'.$data['status'],(string)$data['feedback_id'],$reason);
                $this->notify($row,$a,'feedback',$db->table('campus_feedback')->where('id',(int)$data['feedback_id'])->value('user_id'));
            });
        } else throw new \LogicException('Unknown campus route');
        return new JsonResponse(['data'=>$this->records->serialize($a,$this->records->find($a,$row->id),true)]);
    }
}
