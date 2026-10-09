<?php
namespace UCASSer\Campus;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Http\RequestUtil;
use Flarum\Notification\Notification;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
class NotificationGuard implements MiddlewareInterface
{
    public function __construct(private \Illuminate\Contracts\Container\Container $container) {}
    public function __invoke(BlueprintInterface $blueprint,array $recipients): array {
        $subject=$blueprint->getSubject();
        if ($subject instanceof Discussion || $subject instanceof Post) return array_values(array_filter($recipients,fn($u)=>$subject::query()->whereVisibleTo($u)->where('id',$subject->id)->exists()));
        return $recipients;
    }
    public function process(ServerRequestInterface $r,RequestHandlerInterface $handler): ResponseInterface {
        if (preg_match('~/notifications/(\d+)$~',$r->getUri()->getPath(),$match)) {
            $a=RequestUtil::getActor($r); $a->assertRegistered(); $notice=Notification::query()->where('user_id',$a->id)->findOrFail((int)$match[1]); $subject=null;
            foreach ($this->container->make('flarum.notification.blueprints') as $class=>$drivers) if ($class::getType()===$notice->type) { $model=$class::getSubjectModel(); $subject=$model::query()->find($notice->subject_id); break; }
            if (($subject instanceof Discussion || $subject instanceof Post) && !$subject::query()->whereVisibleTo($a)->where('id',$subject->id)->exists()) throw new ModelNotFoundException;
        }
        return $handler->handle($r);
    }
}
