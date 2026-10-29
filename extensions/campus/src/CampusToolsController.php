<?php
namespace UCASSer\Campus;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Read-only reference data for the campus toolbox (timetable, semester
 * calendars, shuttle timetable). The JSON files ship with the extension and
 * are replaced when the school publishes an update, so the payload is cached
 * briefly but never written through the API.
 */
class CampusToolsController implements RequestHandlerInterface
{
    private const FILES = [
        'timetable' => 'timetable.json',
    ];

    public function handle(Request $request): ResponseInterface
    {
        $dir = __DIR__.'/../resources/data/';
        $data = [];
        foreach (self::FILES as $key => $file) {
            $path = $dir.$file;
            $data[$key] = is_file($path) ? json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR) : null;
        }
        return new JsonResponse(['data' => $data], 200, [
            'Cache-Control' => 'public, max-age=600',
        ]);
    }
}
