<?php
namespace UCASSer\Campus;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Flarum\Http\RequestUtil;
class RateLimit implements MiddlewareInterface
{
    public function __construct(private ConnectionInterface $db) {}
    public function process(Request $r, RequestHandlerInterface $handler): Response
    {
        if (RequestUtil::isInternal($r)) return $handler->handle($r);
        $path = $r->getUri()->getPath();
        if (str_contains($path,'/campus/') && array_filter($r->getQueryParams(),fn($v)=>is_array($v) || is_object($v)))
            return new JsonResponse(['errors'=>[['status'=>'422','code'=>'validation_error','detail'=>'筛选条件必须为单个值']]],422);
        if (!in_array($r->getMethod(), ['POST','PATCH','DELETE'], true)) return $handler->handle($r);
        $actor = RequestUtil::getActor($r);
        // Suspended members retain access to appeals and their personal-data rights.
        if (str_contains($path, '/campus/') && !$actor->isAdmin() && $actor->suspended_until && $actor->suspended_until->isFuture()
            && !preg_match('~/cases/\d+/(appeal|supplement)$~', $path)) $actor->assertPermission(false);
        $auth = preg_match('~/(token|login|register|users|forgot|reset)(/|$)~', $path);
        if (!$auth && !preg_match('~/(campus|posts|discussions|flags)(/|$)~', $path)) return $handler->handle($r);
        $auth = $auth && $r->getMethod()==='POST';
        $window = 900; $limit = $auth ? 240 : ($actor->isAdmin() ? 1200 : 120);
        $ip = (string)($r->getAttribute('ipAddress') ?? $r->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        // Never persist raw IP, email, password, evidence or token.
        $route = preg_replace('~/\d+(?=/|$)~', '/:id', $path);
        $identity = $auth ? $ip : ($actor->id ? 'user:'.$actor->id : $ip);
        $keys = [hash('sha256', $r->getMethod().'|'.$route.'|'.$identity.'|'.intdiv(time(), $window))=>$limit];
        $data = $r->getParsedBody() ?? [];
        if ($auth && isset($data['identification']) && is_string($data['identification']))
            $keys[hash('sha256', 'login|'.mb_strtolower(trim($data['identification'])).'|'.intdiv(time(), $window))] = 20;
        $accepted = $this->db->transaction(function () use ($keys, $window) {
            foreach ($keys as $key=>$limit) {
            $this->db->table('campus_rate_limits')->insertOrIgnore(['bucket'=>$key, 'hits'=>0, 'expires'=>time()+$window]);
            $row = $this->db->table('campus_rate_limits')->where('bucket',$key)->lockForUpdate()->first();
            if ($row->hits >= $limit) return false;
            $this->db->table('campus_rate_limits')->where('bucket',$key)->increment('hits');
            }
            $this->db->table('campus_rate_limits')->where('expires','<',time()-86400)->delete();
            return true;
        });
        if (!$accepted) return new JsonResponse(['errors'=>[['status'=>'429','code'=>'rate_limit','detail'=>'请求过于频繁，请稍后重试']]],429,['Retry-After'=>(string)$window]);
        return $handler->handle($r);
    }
}
