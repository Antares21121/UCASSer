<?php
require __DIR__.'/../vendor/autoload.php';

use UCASSer\Campus\CollectorClient;

$cases = json_decode(file_get_contents(__DIR__.'/fixtures/wechat-article-urls.json'), true, 32, JSON_THROW_ON_ERROR);
$xml = '<rss><channel>';
$expected = [];
foreach ($cases as $case) {
    if (CollectorClient::isArticleUrl($case['url']) !== $case['valid']) throw new RuntimeException('WeChat URL boundary failed');
    if (trim($case['url']) !== $case['url']) continue; // RSS fields are trimmed before validation.
    $xml .= '<item><title>Public article fixture</title><link>'.htmlspecialchars($case['url'], ENT_XML1).'</link></item>';
    if ($case['valid']) $expected[] = $case['url'];
}
$xml .= '</channel></rss>';
$rows = CollectorClient::parseFeedRows($xml, 20);
if (array_column($rows, 'url') !== $expected) throw new RuntimeException('Backend RSS discarded a valid tilde article or accepted an unsafe URL');
if (CollectorClient::isArticleUrl('https://mp.weixin.qq.com/s?__biz=fixture', false)) throw new RuntimeException('Short-only import boundary widened');
echo "WeChat tilde links, original URL preservation, RSS and authority boundaries OK\n";
