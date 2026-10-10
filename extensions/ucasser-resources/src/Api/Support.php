<?php

namespace UCASSer\Resources\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;

abstract class Support
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    protected function admin(ServerRequestInterface $request): ?JsonResponse
    {
        return RequestUtil::getActor($request)->isAdmin()
            ? null
            : new JsonResponse(['error' => '管理员权限不足'], 403);
    }

    protected function input(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    protected function error(string $message, int $status = 422): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }

    protected function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && mb_strlen($value) <= $max ? $value : null;
    }

    protected function url(mixed $value): ?string
    {
        $url = $this->text($value, 2048);
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $url
            : null;
    }
}
