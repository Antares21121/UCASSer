<?php
require __DIR__.'/../extensions/campus/src/UcassNoticesAdapter.php';

use UCASSer\Campus\UcassNoticesAdapter;

function check(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}

$adapter = new UcassNoticesAdapter();
$listing = <<<'HTML'
<ul>
  <li><span>2026-10-10</span><a href="info/1150/2287.htm" title="讲座通知">讲座通知</a></li>
  <li><span>2026-10-09</span><a href="https://outside.example/post" title="站外内容">站外内容</a></li>
</ul>
HTML;
$entries = $adapter->listing($listing, 'https://jw.ucass.edu.cn/tzgg.htm');
check(count($entries) === 1, '只应识别本站公告');
check($entries[0]['url'] === 'https://jw.ucass.edu.cn/info/1150/2287.htm', '公告链接错误');
check($entries[0]['published_at'] === '2026-10-09 16:00:00', '公告日期时区错误');

$article = <<<'HTML'
<div id="vsb_content"><style>body { content: "NOT_ARTICLE"; }</style>
<p>关于开展秋季学术讲座的通知。欢迎全校同学参加，具体安排请以主办方公告为准。</p></div>
HTML;
$content = $adapter->article($article);
check(str_starts_with($content['summary'], '关于开展秋季学术讲座'), '摘要未取到正文');
check(!str_contains($content['summary'], 'NOT_ARTICLE'), '摘要包含样式内容');
check(strlen($content['content_hash']) === 64, '内容指纹错误');
echo "Aggregation parser OK\n";
