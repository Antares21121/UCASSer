<?php
namespace UCASSer\Campus;
use Flarum\Http\RequestUtil;
use Flarum\Gdpr\Models\Export;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
class ExportGuard implements MiddlewareInterface
{
    public function process(ServerRequestInterface $r, RequestHandlerInterface $handler): ResponseInterface
    {
        if (preg_match('~/gdpr/export/([^/]+)$~',$r->getUri()->getPath(),$match)) {
            $actor=RequestUtil::getActor($r); $actor->assertRegistered();
            $export=Export::byFile(rawurldecode($match[1])); if (!$export) throw new ModelNotFoundException;
            $actor->assertPermission($actor->isAdmin() || $actor->id===$export->actor_id);
        }
        return $handler->handle($r);
    }
}
