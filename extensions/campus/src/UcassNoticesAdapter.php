<?php
namespace UCASSer\Campus;

use DOMDocument;
use DOMXPath;

/** Parser for the public notice list at jw.ucass.edu.cn/tzgg.htm. */
class UcassNoticesAdapter
{
    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.ltrim($html, "\xEF\xBB\xBF"), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new DOMXPath($document);
    }

    public function listing(string $html, string $baseUrl, int $limit = 5): array
    {
        $xpath = $this->document($html);
        $out = [];
        foreach ($xpath->query('//li[span and a]') as $row) {
            $anchor = $xpath->query('./a', $row)->item(0);
            $href = trim($anchor?->getAttribute('href') ?? '');
            if (!preg_match('~^(?:/)?info/\d+/\d+\.htm$~', $href)) continue;
            $title = trim(preg_replace('/\s+/u', ' ', $anchor->getAttribute('title') ?: $anchor->textContent));
            $date = trim($xpath->query('./span', $row)->item(0)?->textContent ?? '');
            if (mb_strlen($title) < 3 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            $origin = parse_url($baseUrl);
            $url = $origin['scheme'].'://'.$origin['host'].'/'.ltrim($href, '/');
            $time = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Asia/Shanghai'));
            $out[] = ['url' => $url, 'title' => $title, 'published_at' => $time ? $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null, 'category' => 'notice'];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    public function article(string $html): array
    {
        $xpath = $this->document($html);
        $content = $xpath->query('//*[@id="vsb_content"]')->item(0);
        if (!$content) throw new \RuntimeException('公告正文结构已变化');
        $parts = [];
        foreach ($xpath->query('.//text()[not(ancestor::style) and not(ancestor::script)]', $content) as $node) {
            $parts[] = $node->textContent;
        }
        $text = trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
        if (mb_strlen($text) < 10) throw new \RuntimeException('公告正文为空');
        return ['summary' => mb_substr($text, 0, 240), 'content_hash' => hash('sha256', mb_substr($text, 0, 50000))];
    }
}
