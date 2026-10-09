<?php
namespace UCASSer\Campus;
use Flarum\Http\RequestUtil;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
class PersonalController implements RequestHandlerInterface
{
    public function __construct(private Records $records) {}
    public function handle(Request $r): ResponseInterface {
        $a=RequestUtil::getActor($r); $a->assertRegistered(); $db=$this->records->db; $q=$r->getQueryParams();
        if (str_starts_with($r->getAttribute('routeName'),'campus.discussion.bookmark')) {
            $d=Discussion::query()->whereVisibleTo($a)->findOrFail((int)$q['id']);
            if ($r->getMethod()==='POST') { $enabled=$r->getParsedBody()['enabled'] ?? null; if (!is_bool($enabled)) throw new ValidationException(['enabled'=>'收藏状态无效']);
                if ($enabled) $db->table('campus_discussion_bookmarks')->insertOrIgnore(['user_id'=>$a->id,'discussion_id'=>$d->id]); else $db->table('campus_discussion_bookmarks')->where('user_id',$a->id)->where('discussion_id',$d->id)->delete();
            }
            return new JsonResponse(['data'=>['bookmarked'=>$db->table('campus_discussion_bookmarks')->where('user_id',$a->id)->where('discussion_id',$d->id)->exists()]]);
        }
        $page=max(1,min(10000,(int)($q['page'] ?? 1))); $offset=($page-1)*20;
        $records=fn($query)=>$query->orderByDesc('id')->offset($offset)->limit(20)->get()->map(fn($row)=>$this->records->serialize($a,$row))->all();
        $bookmarks=$db->table('campus_bookmarks')->where('user_id',$a->id)->select('record_id');
        return new JsonResponse(['data'=>[
            'published'=>$records($this->records->query($a)->where('user_id',$a->id)),
            'bookmarks'=>$records($this->records->query($a)->whereIn('id',$bookmarks)),
            'discussions'=>Discussion::query()->whereVisibleTo($a)->whereIn('id',$db->table('campus_discussion_bookmarks')->where('user_id',$a->id)->select('discussion_id'))->orderByDesc('id')->offset($offset)->limit(20)->get(['id','title'])->all(),
            'answers'=>Post::query()->whereVisibleTo($a)->where('user_id',$a->id)->where('type','comment')->where('number','>',1)->orderByDesc('id')->offset($offset)->limit(20)->get()->map(fn($p)=>['id'=>$p->id,'number'=>$p->number,'discussion_id'=>$p->discussion_id,'body'=>$p->content])->all(),
            'page'=>$page,'page_size'=>20,
        ]]);
    }
}
