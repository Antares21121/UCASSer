<?php
namespace UCASSer\Campus;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

/** Per-member timetable subscriptions on the published schedule export. */
class TimetableController implements RequestHandlerInterface
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function handle(Request $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $route = (string) $request->getAttribute('routeName');

        if ($route === 'campus.timetable.subscriptions') {
            $ids = $actor->isGuest() ? [] : $this->db->table('campus_course_subscriptions')
                ->where('user_id', $actor->id)->orderBy('created_at')->pluck('entry_id')->all();
            return new JsonResponse(['data' => ['ids' => $ids, 'logged_in' => !$actor->isGuest()]]);
        }

        if ($route === 'campus.timetable.subscribe') {
            $input = json_decode((string) $request->getBody(), true);
            $entryId = trim((string) ($input['entry_id'] ?? ''));
            $enabled = $input['enabled'] ?? null;
            if (!preg_match('/^[a-f0-9]{12}$/', $entryId) || !is_bool($enabled)) {
                throw new ValidationException(['entry_id' => '请提供有效的课程安排编号']);
            }
            if ($actor->isGuest()) throw new \Flarum\User\Exception\PermissionDeniedException();
            assert($actor instanceof User);
            if ($enabled) {
                $this->db->table('campus_course_subscriptions')->insertOrIgnore([
                    'user_id' => $actor->id, 'entry_id' => $entryId, 'created_at' => gmdate('Y-m-d H:i:s'),
                ]);
            } else {
                $this->db->table('campus_course_subscriptions')->where('user_id', $actor->id)->where('entry_id', $entryId)->delete();
            }
            $count = $this->db->table('campus_course_subscriptions')->where('user_id', $actor->id)->count();
            return new JsonResponse(['data' => ['entry_id' => $entryId, 'enabled' => $enabled, 'total' => $count]]);
        }

        throw new ValidationException(['route' => '未知的课程订阅操作']);
    }
}
