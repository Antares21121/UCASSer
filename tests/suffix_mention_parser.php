<?php
// Pure checks for the suffix mention transform (no database required).
require_once __DIR__.'/../extensions/campus/src/SuffixMentionParser.php';

use UCASSer\Campus\SuffixMentionParser;

$failures = 0;
$assert = function (bool $ok, string $label) use (&$failures): void {
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL: $label\n");
    } else {
        fwrite(STDOUT, "PASS: $label\n");
    }
};

$map = [6665 => 12, 12345 => 7];

$assert(SuffixMentionParser::transform('你好 @晓明#6665 再见', $map) === '你好 @"晓明"#12 再见', 'unquoted suffix mention resolves to stable id');
$assert(SuffixMentionParser::transform('@"晓明"#6665', $map) === '@"晓明"#12', 'quoted suffix mention resolves to stable id');
$assert(SuffixMentionParser::transform('@"晓明"#12', $map) === '@"晓明"#12', 'native id token untouched');
$assert(SuffixMentionParser::transform('@xiaoming', $map) === '@xiaoming', 'plain username token untouched');
$assert(SuffixMentionParser::transform('@晓明#999', $map) === '@晓明#999', 'unknown suffix left as typed');
$assert(SuffixMentionParser::transform('邮箱 a@b.com#1 不变', $map) === '邮箱 a@b.com#1 不变', 'email-like text untouched');
$assert(SuffixMentionParser::transform('@晓明 #6665', $map) === '@晓明 #6665', 'space between name and suffix untouched (quoted form required)');
$assert(SuffixMentionParser::transform('@晓明#6665和@李华#12345', $map) === '@"晓明"#12和@"李华"#7', 'two mentions in one line');
$assert(SuffixMentionParser::transform(SuffixMentionParser::transform('@晓明#6665', $map), $map) === '@"晓明"#12', 'transform is idempotent');

exit($failures ? 1 : 0);
