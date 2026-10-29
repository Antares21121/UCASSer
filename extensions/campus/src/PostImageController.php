<?php
namespace UCASSer\Campus;

use Flarum\Http\RequestUtil;
use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Local image upload for public discussions and replies. Kept separate from
 * private-message images: once attached to a visible post the image is public
 * like any other post content; drafts and orphans stay private to the owner
 * and are swept.
 */
class PostImageController implements RequestHandlerInterface
{
    public const LIMIT = 5 * 1024 * 1024; // 5 MB per SPEC
    private const MAX_PIXELS = 20000000; // width*height
    private const MAX_SIDE = 8000;
    private const DAILY_LIMIT = 30;

    public function __construct(private ConnectionInterface $db) {}

    private function directory(): string
    {
        return dirname(__DIR__, 3).'/storage/campus-post-images';
    }

    public function handle(Request $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertPermission($actor->is_email_confirmed);
        if ($request->getAttribute('routeName') === 'campus.post.image') {
            return $this->serve($actor, (string) ($request->getQueryParams()['id'] ?? ''));
        }
        $encoded = $request->getParsedBody()['data'] ?? null;
        if (!is_string($encoded) || strlen($encoded) > self::LIMIT * 2 || !preg_match('~^data:image/(?:png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$~D', $encoded, $matches)) {
            throw new ValidationException(['image' => '请选择不超过 5 MB 的 PNG、JPEG 或 WebP 图片']);
        }
        $bytes = base64_decode($matches[1], true);
        if ($bytes === false || strlen($bytes) > self::LIMIT || strlen($bytes) < 16) {
            throw new ValidationException(['image' => '图片内容无效或超过 5 MB']);
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)
            || $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new ValidationException(['image' => '图片格式或尺寸不受支持']);
        }
        // Content-verified MIME (not the client's claim) decides what we store.
        $mime = $info['mime'];
        $today = gmdate('Y-m-d 00:00:00');
        if ($this->db->table('campus_post_images')->where('owner_id', $actor->id)->where('created_at', '>=', $today)->count() >= self::DAILY_LIMIT) {
            throw new ValidationException(['image' => '今天的图片上传次数已达上限，请明天再试']);
        }
        $bytes = $this->stripMetadata($bytes, $mime);
        $dir = $this->directory();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('图片存储目录不可写');
        }
        $id = bin2hex(random_bytes(16));
        $path = $dir.'/'.$id.'.image';
        if (@file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) throw new \RuntimeException('图片保存失败');
        @chmod($path, 0640);
        try {
            $this->db->table('campus_post_images')->insert([
                'id' => $id, 'owner_id' => $actor->id, 'discussion_id' => null, 'post_id' => null,
                'bytes' => filesize($path), 'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $error) { @unlink($path); throw $error; }
        $this->sweep();
        return new JsonResponse(['data' => ['url' => '/api/campus/post-images/'.$id]], 201);
    }

    private function serve(User $actor, string $id): ResponseInterface
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        $row = $this->db->table('campus_post_images')->where('id', $id)->first();
        if (!$row || ((int) $row->owner_id !== (int) $actor->id && !$row->post_id)) {
            // Draft/orphan images are private to the owner; attached images are public.
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
            ->withHeader('Cache-Control', $row->post_id ? 'public, max-age=604800' : 'private, max-age=300')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /** Re-encode through GD when available so EXIF/ICC/profile chunks are dropped. */
    private function stripMetadata(string $bytes, string $mime): string
    {
        if (!function_exists('imagecreatefromstring') || strlen($bytes) < 32) return $bytes;
        $src = @imagecreatefromstring($bytes);
        if (!$src) return $bytes;
        try {
            ob_start();
            $ok = match ($mime) {
                'image/jpeg' => imagejpeg($src, null, 88),
                'image/webp' => function_exists('imagewebp') ? imagewebp($src, null, 88) : false,
                default => imagepng($src, null, 7),
            };
            $clean = $ok ? ob_get_clean() : (ob_end_clean() ? '' : '');
            if ($clean !== '' && strlen($clean) >= 16) return $clean;
            return $bytes;
        } finally {
            imagedestroy($src);
        }
    }

    /** Bounded orphan sweep: unattached uploads older than 7 days. */
    private function sweep(): void
    {
        $dir = $this->directory();
        $stale = $this->db->table('campus_post_images')->whereNull('post_id')
            ->where('created_at', '<', gmdate('Y-m-d H:i:s', time() - 7 * 86400))->limit(10)->get(['id']);
        foreach ($stale as $row) {
            @unlink($dir.'/'.$row->id.'.image');
            $this->db->table('campus_post_images')->where('id', $row->id)->whereNull('post_id')->delete();
        }
    }

    /** Attach uploaded images referenced by a post's content to that post. */
    public static function attachFromContent(string $content, int $discussionId, int $postId): void
    {
        if (!preg_match_all('~/api/campus/post-images/([a-f0-9]{32})~', $content, $matches)) return;
        foreach (array_unique($matches[1]) as $id) {
            resolve(ConnectionInterface::class)->table('campus_post_images')->where('id', $id)->whereNull('post_id')
                ->update(['post_id' => $postId, 'discussion_id' => $discussionId]);
        }
    }
}
