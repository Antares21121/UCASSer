<?php
namespace UCASSer\Campus;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Http\RequestUtil;
use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
class SearchController implements RequestHandlerInterface
{
    public function handle(Request $r): ResponseInterface {
        $a=RequestUtil::getActor($r); $p=$r->getQueryParams(); $q=Discussion::query()->whereVisibleTo($a)->with(['user','tags'])->leftJoin('campus_records as c','c.discussion_id','=','discussions.id');
        $type=(string)($p['type'] ?? ''); if ($type && !isset(Records::CATEGORIES[$type]) && $type!=='discussion') throw new ValidationException(['type'=>'检索类型无效']);
        if ($type==='discussion') $q->whereNull('c.id'); elseif ($type) $q->where('c.type',$type);
        foreach (['category','course_id','semester','section_id'] as $key) if (!empty($p[$key])) $q->where('c.'.$key,$p[$key]);
        if (!empty($p['tag'])) $q->whereHas('tags',fn($b)=>$b->where('slug',mb_substr((string)$p['tag'],0,100)));
        if (!empty($p['status'])) {
            Records::statusFilter($q,mb_substr((string)$p['status'],0,30),'c.');
        }
        foreach (['from','to','deadline_from','deadline_to','starts_from','starts_to'] as $key) if (!empty($p[$key])) {
            $v=(string)$p[$key]; $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$v); if (!$date || $date->format('Y-m-d')!==$v) throw new ValidationException([$key=>'请填写有效日期']);
            $column=str_starts_with($key,'deadline')?'c.deadline':(str_starts_with($key,'starts')?'c.starts_at':'discussions.created_at');
            $q->where($column,str_ends_with($key,'to')?'<=':'>=',$v.(str_ends_with($key,'to')?' 23:59:59':' 00:00:00'));
        }
        $term=mb_substr(trim((string)($p['q'] ?? '')),0,100); $pattern='%'.addcslashes($term,'\\%_').'%';
        if ($term) $q->where(fn($b)=>$b->where('discussions.title','like',$pattern)->orWhere('c.data','like',$pattern)->orWhereIn('discussions.id',Post::query()->whereVisibleTo($a)->where('type','comment')->where('content','like',$pattern)->select('discussion_id')));
        $sort=$p['sort'] ?? 'latest';
        if ($sort==='relevance' && $term) $q->orderByRaw('CASE WHEN discussions.title LIKE ? THEN 0 ELSE 1 END',[$pattern]);
        elseif ($sort==='deadline') $q->whereNotNull('c.deadline')->orderBy('c.deadline');
        elseif ($sort==='activity') $q->whereNotNull('c.starts_at')->orderBy('c.starts_at');
        elseif (!in_array($sort,['latest','updated','relevance'],true)) throw new ValidationException(['sort'=>'排序方式无效']);
        $total=(clone $q)->count(); $page=max(1,min(10000,(int)($p['page'] ?? 1)));
        $rows=$q->orderByRaw($sort==='latest'?'discussions.created_at DESC':'COALESCE(c.updated_at,discussions.last_posted_at,discussions.created_at) DESC')->orderByDesc('discussions.id')->offset(($page-1)*20)->limit(20)
            ->get(['discussions.*','c.id as record_id','c.type as record_type','c.category as record_category','c.status as record_status','c.updated_at as record_updated','c.deadline as record_deadline','c.data as record_payload'])->map(function ($d) use ($a) {
                $post=Post::query()->whereVisibleTo($a)->where('discussion_id',$d->id)->where('number',1)->first();
                $payload=json_decode($d->record_payload ?? '{}',true,512,JSON_THROW_ON_ERROR);
                $expiry=$d->record_deadline ?: (!empty($payload['ends_at'])?str_replace('T',' ',$payload['ends_at']).':00':null);
                if ($d->record_type==='information' && $d->record_status==='active' && $expiry && $expiry<gmdate('Y-m-d H:i:s')) $d->record_status='expired';
                return ['id'=>$d->id,'record_id'=>$d->record_id,'type'=>$d->record_type ?? 'discussion','title'=>$d->title,'summary'=>mb_substr($post?->content ?? '',0,160),'category'=>$d->record_category,'status'=>$d->record_status,'updated_at'=>$d->record_updated ?? $d->last_posted_at?->toIso8601String() ?? $d->created_at->toIso8601String(),'author'=>$d->user?->display_name ?? '已删除账号','tags'=>$d->tags->pluck('name')->all()];
            })->all();
        return new JsonResponse(['data'=>$rows,'meta'=>['total'=>$total,'page'=>$page,'page_size'=>20,'relevance'=>'标题匹配优先，其次按最近更新时间；无个性化推荐']]);
    }
}
