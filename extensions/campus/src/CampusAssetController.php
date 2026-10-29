<?php
namespace UCASSer\Campus;

use Laminas\Diactoros\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the extension's own illustration assets (original SVGs, CC0-licensed
 * by the project) with strict content types and long caching. Only whitelisted
 * file names are served; nothing is read from user input paths.
 */
class CampusAssetController implements RequestHandlerInterface
{
    private const ALLOWED = [
        'hero.svg', 'hero-tall.svg',
        'courses.svg', 'resources.svg', 'life.svg', 'secondhand.svg', 'travel.svg', 'questions.svg', 'careers.svg',
    ];

    public function handle(Request $request): ResponseInterface
    {
        $name = (string) ($request->getQueryParams()['file'] ?? '');
        if (!in_array($name, self::ALLOWED, true)) throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        $path = dirname(__DIR__).'/assets/'.$name;
        if (!is_file($path)) throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        $bytes = file_get_contents($path);
        $response = new Response();
        $response->getBody()->write($bytes);
        return $response->withHeader('Content-Type', 'image/svg+xml')
            ->withHeader('Cache-Control', 'public, max-age=604800')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'");
    }
}
