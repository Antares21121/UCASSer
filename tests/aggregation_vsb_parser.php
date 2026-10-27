<?php
require __DIR__.'/../extensions/campus/src/UcassVsbAdapter.php';

use UCASSer\Campus\UcassVsbAdapter;

function checkVsb(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}

$adapter = new UcassVsbAdapter();
$listing = <<<'HTML'
<ul>
  <li><a href="../info/1012/2132.htm"><h4>全球与区域国别学院综合考核成绩</h4><div class="wtime">2026.09.24</div></a></li>
  <li><span>09 2026/10</span><a href="../info/1029/4028.htm">应用经济学院招生目录</a></li>
  <li><span>05-26 2025</span><a href="/info/1332/4935.htm">政府管理学院奖学金通知</a></li>
  <li><span>2026年10月10日</span><a href="https://outside.example/info/1/2.htm">站外伪装公告</a></li>
  <li><a href="../info/1000/9999.htm">没有日期的导航链接</a></li>
  <li><span>2026-02-30</span><a href="../info/1000/9998.htm">日期无效的链接</a></li>
</ul>
HTML;
$entries = $adapter->listing($listing, 'https://sipe.ucass.edu.cn/xwzx/tzgg.htm', 10);
checkVsb(count($entries) === 3, '只应识别同域且日期有效的公告');
checkVsb($entries[0]['url'] === 'https://sipe.ucass.edu.cn/info/1012/2132.htm', '相对链接解析错误');
checkVsb($entries[0]['title'] === '全球与区域国别学院综合考核成绩', '标题不应带发布日期');
checkVsb($entries[0]['published_at'] === '2026-09-23 16:00:00', '发布日期的北京时间转换错误');
checkVsb($entries[1]['published_at'] === '2026-10-08 16:00:00', '日、年/月格式解析错误');
checkVsb($entries[2]['published_at'] === '2025-05-25 16:00:00', '月-日 年格式解析错误');
checkVsb(count($adapter->listing($listing, 'https://sipe.ucass.edu.cn/xwzx/tzgg.htm', 1)) === 1, '列表数量限制失效');

$pdf = <<<'HTML'
<h1 class="c-title">招生专业目录</h1>
<div id="vsb_content"><iframe src="/__local/a.pdf"></iframe>
<p>如果您无法在线浏览此 PDF 文件，则可以下载免费的 Adobe Reader PDF 阅读器，或下载此 PDF 文件。</p></div>
HTML;
$content = $adapter->article($pdf);
checkVsb($content['summary'] === '公告正文以附件为准，请打开原文核对。', 'PDF 阅读器提示不应作为摘要');
checkVsb($content['title'] === '招生专业目录', '详情标题未识别');
$updated = $adapter->article(str_replace('/__local/a.pdf', '/__local/b.pdf', $pdf));
checkVsb($updated['content_hash'] !== $content['content_hash'], '附件变化应触发复核');

$article = '<div class="v_news_content"><style>NOT_ARTICLE</style><p>关于开展秋季学术讲座的通知。欢迎全校同学参加，具体安排请以主办方公告为准。</p></div>';
$result = $adapter->article($article);
checkVsb(str_starts_with($result['summary'], '关于开展秋季学术讲座'), '正文摘要未识别');
checkVsb(!str_contains($result['summary'], 'NOT_ARTICLE'), '样式不应进入摘要');
echo "VSB aggregation parser OK\n";
