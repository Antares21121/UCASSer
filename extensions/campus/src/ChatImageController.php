<?php
namespace UCASSer\Campus;

use Flarum\Http\RequestUtil;
use Flarum\Foundation\ValidationException;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

class ChatImageController implements RequestHandlerInterface
{
    private const LIMIT = 2 * 1024 * 1024;

    public function __construct(private ConnectionInterface $db) {}

    private function directory(): string
    {
        return dirname(__DIR__, 3).'/storage/campus-chat-images';
    }

    public function handle(Request $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        if ($request->getAttribute('routeName') === 'campus.chat.image') {
            $id = (string) ($request->getQueryParams()['id'] ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            $row = $this->db->table('campus_chat_images')->where('id', $id)->first();
            if (!$row || ((int) $row->owner_id !== (int) $actor->id && (!$row->dialog_id ||
                !$this->db->table('dialog_user')->where('dialog_id', $row->dialog_id)->where('user_id', $actor->id)->exists()))) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            }
            $path = $this->directory().'/'.$id.'.image';
            if (!is_file($path)) throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            $bytes = file_get_contents($path);
            $info = $bytes === false ? false : @getimagesizefromstring($bytes);
            if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            }
            $response = new Response();
            $response->getBody()->write($bytes);
            return $response->withHeader('Content-Type', $info['mime'])
                ->withHeader('Cache-Control', 'private, max-age=300')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        }
        $input = $request->getParsedBody() ?? [];
        $encoded = $input['data'] ?? null;
        if (!is_string($encoded) || strlen($encoded) > 3 * 1024 * 1024 || !preg_match('~^data:image/(?:png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$~D', $encoded, $matches)) {
            throw new ValidationException(['image' => '请选择不超过 2 MB 的 PNG、JPEG 或 WebP 图片']);
        }
        $bytes = base64_decode($matches[1], true);
        if ($bytes === false || strlen($bytes) > self::LIMIT || strlen($bytes) < 16) {
            throw new ValidationException(['image' => '图片内容无效或超过 2 MB']);
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)
            || $info[0] > 5000 || $info[1] > 5000 || $info[0] * $info[1] > 16000000) {
            throw new ValidationException(['image' => '图片格式或尺寸不受支持']);
        }
        $today = gmdate('Y-m-d 00:00:00');
        if ($this->db->table('campus_chat_images')->where('owner_id', $actor->id)->where('created_at', '>=', $today)->count() >= 20) {
            throw new ValidationException(['image' => '今天的图片上传次数已达上限']);
        }
        $dir = $this->directory();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('图片存储目录不可写');
        }
        $id = bin2hex(random_bytes(16));
        $path = $dir.'/'.$id.'.image';
        if (@file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) throw new \RuntimeException('图片保存失败');
        @chmod($path, 0640);
        try {
            $this->db->table('campus_chat_images')->insert([
                'id' => $id, 'owner_id' => $actor->id, 'dialog_id' => null,
                'bytes' => filesize($path), 'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $error) { @unlink($path); throw $error; }
        // A small bounded sweep removes abandoned drafts without a separate timer.
        $stale = $this->db->table('campus_chat_images')->whereNull('dialog_id')
            ->where('created_at', '<', gmdate('Y-m-d H:i:s', time() - 7 * 86400))->limit(10)->get(['id']);
        foreach ($stale as $row) {
            @unlink($dir.'/'.$row->id.'.image');
            $this->db->table('campus_chat_images')->where('id', $row->id)->whereNull('dialog_id')->delete();
        }
        return new JsonResponse(['data' => ['url' => '/api/campus/chat-images/'.$id]], 201);
    }
}
