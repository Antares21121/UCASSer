<?php
// CLI only, explicit development guard, native API writes and no stored passwords.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'development') throw new RuntimeException('Development CLI only');
require __DIR__.'/../vendor/autoload.php';
$container = (require __DIR__.'/../site.php')->bootApp()->getContainer();
$db = $container->make(Illuminate\Database\ConnectionInterface::class);
$settings = $container->make(Flarum\Settings\SettingsRepositoryInterface::class);
$admin = Flarum\User\User::query()->whereHas('groups', fn($q) => $q->where('groups.id', 1))->firstOrFail();
$client = $container->make(Flarum\Api\Client::class);
$call = function ($actor, string $method, string $path, array $body) use ($client): array {
    $response = $client->withActor($actor)->withBody($body)->withoutErrorHandling()->send($method, $path);
    if ($response->getStatusCode() >= 400) throw new RuntimeException('Demo operation failed: '.$method.' '.$path);
    return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'];
};
$manifest = json_decode($settings->get('ucasser-campus.demo_manifest', '{}'), true, 512, JSON_THROW_ON_ERROR);
$save = function () use (&$manifest, $settings): void { $settings->set('ucasser-campus.demo_manifest', json_encode($manifest, JSON_THROW_ON_ERROR)); };
$authors = [];
foreach (['林同学', '小陈', '周末去图书馆', '晚风', '今天也在学习', '青禾'] as $index => $nickname) {
    $username = 'campus_demo_'.($index + 1);
    $user = Flarum\User\User::query()->where('username', $username)->first();
    if (!$user) {
        $data = $call($admin, 'POST', '/users', ['data' => ['type' => 'users', 'attributes' => [
            'username' => $username, 'email' => $username.'@example.invalid',
            'password' => bin2hex(random_bytes(32)), 'isEmailConfirmed' => true
        ]]]);
        $user = Flarum\User\User::query()->findOrFail($data['id']);
    }
    if ($user->isAdmin() || $user->email !== $username.'@example.invalid') throw new RuntimeException('Demo username belongs to a different account');
    if (!str_ends_with($user->display_name, '（演示）')) $call($user, 'PATCH', '/users/'.$user->id, ['data' => ['type' => 'users', 'id' => (string)$user->id,
        'attributes' => ['nickname' => $nickname.'（演示）', 'preferences' => ['notify_newPost_email' => false, 'notify_newPost_alert' => false]]]]);
    $authors[] = $user;
}
$sections = collect($container->make(UCASSer\Campus\Sections::class)->rows())->keyBy('key');
$dataset = json_decode(file_get_contents(__DIR__.'/demo-content.json'), true, 512, JSON_THROW_ON_ERROR);
$count = 0;
$footer = "\n\n---\n开发环境演示内容：人物、活动和课程体验为模拟，不代表真实公告、办事政策或课程口碑。";
foreach ($dataset as $board => $entries) {
    $section = $sections->get($board);
    if (!$section) continue;
    foreach ($entries as $index => $entry) {
        $key = $board.':'.$index;
        if (isset($manifest[$key]) && Flarum\Discussion\Discussion::query()->where('id', $manifest[$key]['discussion_id'])->exists()) continue;
        $actor = $board === 'governance' ? $admin : $authors[$index % count($authors)];
        [$category, $title, $body] = $entry;
        $type = ['resources' => 'resource', 'courses' => 'review', 'questions' => 'question'][$board] ?? 'information';
        $payload = ['type' => $type, 'category' => $category, 'title' => $title, 'body' => $body.$footer,
            'section_id' => $section->id, 'tags' => '校园互助,开发演示'];
        if ($type === 'information') {
            $payload += ['source' => '演示账号原创的模拟信息', 'location' => '校内公共空间（演示）', 'organizer' => '同学自发交流（演示）'];
            if (in_array($board, ['news', 'academic'], true)) {
                $payload += ['starts_at' => gmdate('Y-m-d\TH:i', strtotime('+'.($index + 2).' days 10:00 UTC')),
                    'ends_at' => gmdate('Y-m-d\TH:i', strtotime('+'.($index + 2).' days 12:00 UTC')),
                    'deadline' => gmdate('Y-m-d\TH:i', strtotime('+'.($index + 1).' days 18:00 UTC'))];
            }
        } elseif ($type === 'resource') {
            $payload += ['source' => 'Microsoft Fluent 官方设计文档', 'source_url' => 'https://fluent2.microsoft.design/'.['typography','color','accessibility','design-tokens','typography'][$index],
                'license' => '仅索引公开官方文档，版权与使用条款以原网站为准，不托管原文件。', 'topic' => '界面设计与阅读体验', 'edition' => '公开网页'];
        } elseif ($type === 'question') {
            $payload += ['background' => '已经尝试搜索相关讨论，希望大家结合自己的方法补充。'];
        } elseif ($type === 'review') {
            $courseKey = 'course:'.$index;
            if (!isset($manifest[$courseKey]) || !$db->table('campus_courses')->where('id', $manifest[$courseKey]['id'])->exists()) {
                $course = $call($admin, 'POST', '/campus/courses', ['name' => ['学术写作','数据分析入门','科学计算','公共知识整理','信息检索'][$index].'（演示）',
                    'type' => '模拟选修', 'semester' => '2026 秋（演示）', 'grade' => '各年级', 'nature' => '演示课程',
                    'assessment' => '阅读讨论与课程项目（模拟）', 'resources' => '', 'reason' => '初始化明确标注的开发演示课程']);
                $manifest[$courseKey] = ['id' => $course['id']]; $save();
            }
            $payload += ['course_id' => $manifest[$courseKey]['id'], 'semester' => '2026 秋（演示）',
                'overall' => 4, 'difficulty' => [3,2,4,2,3][$index], 'workload' => [3,3,4,2,3][$index],
                'assessment' => '模拟：平时讨论、阅读记录与期末项目', 'advice' => '每周留出固定时间整理笔记，并提前拆分课程项目。',
                'prerequisites' => '模拟课程，仅用于展示评价结构'];
        }
        $record = $call($actor, 'POST', '/campus/records', $payload);
        $manifest[$key] = ['discussion_id' => $record['discussion_id'], 'record_id' => $record['id']]; $save();
        $count++;
        // Generate actual native replies and question acceptance, never fake counters.
        if ($index < 3 && $board !== 'governance') {
            $reply = $call($authors[($index + 1) % count($authors)], 'POST', '/campus/records/'.$record['id'].'/comments',
                ['body' => ['感谢整理！我通常先把要解决的问题列成清单，再按优先级逐个处理。',
                    '补充一个实践方法：保存原始链接和更新时间，过一段时间再回来核对。',
                    '我也有类似经历，提前沟通具体需求会更容易找到合适的伙伴。'][$index]."\n\n（开发演示回复）"]);
            if ($type === 'question' && $index === 0) {
                $answer = end($reply['answers']);
                $call($actor, 'POST', '/campus/records/'.$record['id'].'/accept', ['post_id' => $answer['id']]);
            }
        }
    }
}
$hidden = 0;
if (($argv[1] ?? '') === 'tidy-tests') {
    // The first Windows directory seed used local PHP time. Touch only the two
    // untouched official defaults through the API, recording the correction.
    if ($db->getSchemaBuilder()->hasTable('ucasser_resources')) {
        foreach ($db->table('ucasser_resources')->whereNull('updated_by')->whereIn('url', ['https://cet.neea.edu.cn/', 'https://ncre.neea.edu.cn/'])->get() as $resource) {
            if ($resource->updated_at <= gmdate('Y-m-d H:i:s')) continue;
            $response = $client->withActor($admin)->withBody((array)$resource + ['reason' => '统一开发环境资料时间显示'])->withoutErrorHandling()->patch('/ucasser/resources/'.$resource->id);
            if ($response->getStatusCode() >= 400) throw new RuntimeException('Directory seed time correction failed');
        }
    }
    // Exact legacy seed titles or known test-author/title pairs only. Soft hiding
    // preserves users, replies, revisions and audit trails; real posts stay intact.
    $patterns = ['/^【示例】.*：欢迎参与公共知识维护$/u', '/^【示例】(校园公共知识分享活动|公开笔记索引与授权说明|如何共同维护校园公共知识？|公共知识导论学习体验)$/u',
        '/^【示例】(校园分享活动|公开学习指南|如何寻找校园指南|问答整理后的指南|课程体验分享|通知与个人中心|分页检索|浏览器活动验收|原生界面重试|浏览器举报上下文|浏览器评价)( |$)/u',
        '/^【示例】浏览器 (resource|question) \d+$/u', '/^【示例】普通讨论检索 [a-f0-9]{10}$/u'];
    $protected = array_column(array_filter($manifest, fn($row) => isset($row['discussion_id'])), 'discussion_id');
    foreach (Flarum\Discussion\Discussion::query()->whereNull('hidden_at')->with('user')->where('title','like','【示例】%')->get() as $discussion) {
        $username = $discussion->user?->username ?? '';
        if (!preg_match('/^(phase0_admin|campus_[a-f0-9]{8}|browser_[a-f0-9]{10})$/', $username) || in_array($discussion->id, $protected)) continue;
        if (!array_filter($patterns, fn($pattern) => preg_match($pattern, $discussion->title))) continue;
        $call($admin, 'PATCH', '/discussions/'.$discussion->id, ['data' => ['type' => 'discussions', 'id' => (string)$discussion->id, 'attributes' => ['isHidden' => true]]]);
        $hidden++;
    }
}
echo 'Demo ready: '.$count.' new discussions; '.$hidden." recognized test discussions archived (recoverable)\n";
