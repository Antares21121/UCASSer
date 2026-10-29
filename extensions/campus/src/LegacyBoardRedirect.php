<?php
namespace UCASSer\Campus;

use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Retired discussion boards keep their old URLs alive through permanent
 * redirects to their successor entry points, so old links never die
 * silently. Runs as forum middleware before the router, which also covers
 * paths the hidden tag route would otherwise shadow.
 */
class LegacyBoardRedirect implements MiddlewareInterface
{
    public const TARGETS = [
        '/t/campus-courses' => '/campus/courses',
        '/t/campus-general' => '/t/campus-life',
        '/t/general' => '/t/campus-life',
        '/t/campus-market' => '/t/campus-secondhand',
        '/t/campus-academic' => '/t/campus-careers',
        '/t/campus-news' => '/campus/aggregation',
        '/t/campus-chat' => '/t/campus-life',
        '/t/campus-governance' => '/campus/governance',
        '/t/campus-archive' => '/campus/courses',
    ];

    public function process(Request $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (isset(self::TARGETS[$path])) {
            return new RedirectResponse(self::TARGETS[$path], 301);
        }
        return $handler->handle($request);
    }
}
