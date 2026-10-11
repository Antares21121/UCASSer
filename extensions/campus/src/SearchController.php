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
        $a=RequestUtil::getActor($r); $p=$r->getQueryParams(); $q=SearchQuery::build($a,$p);
        $term=mb_substr(trim((string)($p['q'] ?? '')),0,100); $pattern='%'.addcslashes($term,'\\%_').'%';
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
