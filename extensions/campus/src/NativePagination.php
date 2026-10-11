<?php
namespace UCASSer\Campus;

use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

// RC.8's home document sends page[number], while its discussion endpoint
// accepts offsets. Normalize internal preload requests before the native query.
class NativePagination implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $query = $request->getQueryParams();
        if (RequestUtil::isInternal($request) && $request->getMethod() === 'GET'
            && $request->getUri()->getPath() === '/discussions' && isset($query['page']['number'])
            && !isset($query['page']['offset'])) {
            $page = filter_var($query['page']['number'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]) ?: 1;
            $query['page'] = ['offset' => ($page - 1) * 20, 'limit' => 20];
            $request = $request->withQueryParams($query);
        }
        return $handler->handle($request);
    }
}
