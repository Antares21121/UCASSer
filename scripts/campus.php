<?php
// CLI only; configuration is loaded by the existing environment launcher.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../vendor/autoload.php';
$site = require __DIR__.'/../site.php';
$app = $site->bootApp();
$container = $app->getContainer();
$action = $argv[1] ?? 'status';
if ($action === 'enable') {
    $container->make(Flarum\Extension\ExtensionManager::class)->enable('ucasser-campus');
    foreach (['flarum-audit', 'flarum-nicknames', 'flarum-gdpr'] as $extension) $container->make(Flarum\Extension\ExtensionManager::class)->enable($extension);
    echo "Campus extension enabled; run campus:setup\n";
} elseif ($action === 'mail') {
    if (getenv('APP_ENV')!=='development') throw new RuntimeException('Local mailbox is development-only');
    $settings=$container->make(Flarum\Settings\SettingsRepositoryInterface::class);
    $input=json_decode(file_get_contents(__DIR__.'/../.runtime/dev-mail/config.json'),true,512,JSON_THROW_ON_ERROR); $old=[];
    foreach (['mail_driver','mail_host','mail_port','mail_encryption','mail_username','mail_password','mail_from'] as $key) {
        $old[$key]=$settings->get($key); if (array_key_exists($key,$input)) $settings->set($key,$input[$key]);
    }
    $output=__DIR__.'/../.runtime/dev-mail/previous.json'; $inputPath=__DIR__.'/../.runtime/dev-mail/config.json';
    file_put_contents($output,json_encode($old,JSON_THROW_ON_ERROR));
    if (PHP_OS_FAMILY!=='Windows') {chown($output,fileowner($inputPath)); chgrp($output,filegroup($inputPath));}
    chmod($output,0600);
    echo "Development mailbox settings updated\n";
} elseif ($action === 'seed') {
    if (getenv('APP_ENV') !== 'development') throw new RuntimeException('Example data is development-only');
    $actor = Flarum\User\User::query()->whereHas('groups', fn ($q) => $q->where('groups.id', 1))->firstOrFail();
    $client = $container->make(Flarum\Api\Client::class)->withActor($actor)->withoutErrorHandling();
    $count = 0;
    foreach ($container->make(UCASSer\Campus\Sections::class)->rows() as $s) {
        $title = '【示例】'.$s->name.'：欢迎参与公共知识维护';
        if (Flarum\Discussion\Discussion::query()->where('title', $title)->exists()) continue;
        $response = $client->withBody(['data' => ['type' => 'discussions', 'attributes' => ['title' => $title, 'content' => '这是一条明确标注的开发示例，不代表真实校园公告。请共同维护来源、有效性与友善讨论。'], 'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => (string)$s->tag_id]]]]]])->post('/discussions');
        if ($response->getStatusCode() >= 400) throw new RuntimeException('Example creation failed');
        $count++;
    }
    $sections=[]; foreach ($container->make(UCASSer\Campus\Sections::class)->rows() as $s) $sections[$s->key]=$s;
    $db=$container->make(Illuminate\Database\ConnectionInterface::class);
    $course=$db->table('campus_courses')->where('name','【示例】公共知识导论')->first();
    if (!$course) {
        $response=$client->withBody(['name'=>'【示例】公共知识导论','type'=>'示例通识','semester'=>'2026 秋（示例）','grade'=>'所有年级（示例）','nature'=>'示例课程，非真实课程','assessment'=>'【示例】课程资料维护练习','resources'=>'https://example.invalid/course','reason'=>'【示例】开发环境初始化课程'])->post('/campus/courses');
        if ($response->getStatusCode()>=400) throw new RuntimeException('Example course creation failed');
        $course=(object)json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR)['data'];
    }
    $examples=[
        ['type'=>'information','title'=>'【示例】校园公共知识分享活动','category'=>'activity','section_id'=>$sections['news']->id,'source'=>'【示例】维护者','source_url'=>'https://example.invalid/event','deadline'=>gmdate('Y-m-d\TH:i',time()+86400),'starts_at'=>gmdate('Y-m-d\TH:i',time()+172800)],
        ['type'=>'resource','title'=>'【示例】公开笔记索引与授权说明','category'=>'notes','section_id'=>$sections['resources']->id,'source'=>'【示例】原创作者','source_url'=>'https://example.invalid/notes','license'=>'【示例】作者自行决定授权；此链接不对应真实资料','course'=>'【示例】公共知识导论','topic'=>'示例知识整理','grade'=>'示例所有年级'],
        ['type'=>'question','title'=>'【示例】如何共同维护校园公共知识？','category'=>'procedure','section_id'=>$sections['questions']->id,'background'=>'【示例】请核验来源与许可，避免公开个人信息'],
        ['type'=>'review','title'=>'【示例】公共知识导论学习体验','category'=>'experience','section_id'=>$sections['courses']->id,'course_id'=>$course->id,'semester'=>'2026 秋（示例）','overall'=>3,'difficulty'=>3,'workload'=>3,'experience'=>'【示例】开发流程演示，不代表真实课程口碑'],
    ];
    foreach ($examples as $example) {
        if (Flarum\Discussion\Discussion::query()->where('title',$example['title'])->exists()) continue;
        $response=$client->withBody($example+['body'=>'【示例】用于开发环境演示，不是真实校园数据。请发布可核验的来源并维护知识，所有内容使用正常账号。'])->post('/campus/records');
        if ($response->getStatusCode()>=400) throw new RuntimeException('Structured example creation failed');
        $count++;
    }
    echo "Created $count labelled example discussions; existing records retained\n";
} else {
    echo 'Flarum '.Composer\InstalledVersions::getPrettyVersion('flarum/core').'; campus command '.$action."\n";
}
