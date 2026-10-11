<?php
namespace UCASSer\Campus;

use Flarum\Locale\LocaleManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ForumLocale implements MiddlewareInterface
{
    public function __construct(private LocaleManager $locales) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // A previous language cookie or account preference must not leave the
        // public forum in English after removing its language selector.
        $this->locales->setLocale('zh-Hans');

        return $handler->handle($request->withAttribute('locale', 'zh-Hans'));
    }
}
