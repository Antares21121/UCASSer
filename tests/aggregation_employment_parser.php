<?php
require __DIR__.'/../extensions/campus/src/UcassEmploymentAdapter.php';

use UCASSer\Campus\UcassEmploymentAdapter;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$adapter = new UcassEmploymentAdapter();
check($adapter->token(json_encode(['state' => 1, 'data' => '0123456789abcdef'])) === '0123456789abcdef', '访问令牌解析错误');
$id = 'bbbbe73beea247f08d8b9c90267c38e8';
$listing = json_encode(['state' => 1, 'object' => ['newsPage' => ['list' => [
    ['id' => $id, 'title' => '就业指导中心公告', 'releaseDate' => '2026-10-12 15:42', 'url' => 'https://elsewhere.example/private'],
    ['id' => '../../private', 'title' => '不安全链接', 'releaseDate' => '2026-10-12 15:42'],
]]]], JSON_UNESCAPED_UNICODE);
$entries = $adapter->listing($listing);
check(count($entries) === 1, '列表应只接受有效公告 ID');
check($entries[0]['url'] === 'https://jyzd.ucass.edu.cn/frontpage/ucass/html/newsDetail.html?id='.$id, '公告链接应由官方域名和 ID 构造');
check($entries[0]['published_at'] === '2026-10-12 07:42:00', '发布时间应按北京时间转 UTC');

$detail = json_encode(['state' => 1, 'object' => ['article' => [
    'id' => $id,
    'title' => '就业指导中心公告全文',
    'description' => '欢迎参加官方就业指导活动，具体安排请核对原文。',
    'articleData' => ['content' => '<p>活动时间和地点请查看官方通知。</p><p>报名方式请核对原文。</p>'],
]]], JSON_UNESCAPED_UNICODE);
$article = $adapter->article($detail, $id);
check($article['title'] === '就业指导中心公告全文', '公告标题解析错误');
check(str_starts_with($article['summary'], '欢迎参加'), '摘要解析错误');
check(strlen($article['content_hash']) === 64, '内容指纹错误');
try {
    $adapter->article($detail, '00000000000000000000000000000000');
    throw new RuntimeException('公告 ID 不一致应拒绝');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), '不一致'), '公告 ID 不一致未拒绝');
}

echo "Employment aggregation parser OK\n";
