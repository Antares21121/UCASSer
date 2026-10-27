<?php
namespace UCASSer\Campus;

use DOMDocument;
use DOMXPath;

/** Public notices on the university's Visual SiteBuilder sites. */
class UcassVsbAdapter
{
    private function articleUrl(string $baseUrl, string $href): ?string
    {
        $base = parse_url($baseUrl);
        $link = parse_url($href);
        if (!$base || !$link || isset($link['user']) || isset($link['pass'])) return null;
        $host = strtolower($base['host'] ?? '');
        if ($host === '' || strtolower($link['host'] ?? $host) !== $host
            || strtolower($link['scheme'] ?? 'https') !== 'https'
            || ($link['port'] ?? $base['port'] ?? 443) !== ($base['port'] ?? 443)) return null;
        $path = $link['path'] ?? '';
        if (!str_starts_with($path, '/')) {
            $directory = substr($base['path'] ?? '/', 0, strrpos($base['path'] ?? '/', '/') + 1);
            $path = $directory.$path;
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') { array_pop($segments); continue; }
            $segments[] = $segment;
        }
        $path = '/'.implode('/', $segments);
        if (!preg_match('~^/info/\d+/\d+\.htm$~', $path)) return null;
        $port = isset($base['port']) ? ':'.$base['port'] : '';
        return 'https://'.$host.$port.$path;
    }

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

    private function date(string $text): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (preg_match('/(?<!\d)(20\d{2})\s*[-.\/年]\s*(\d{1,2})\s*[-.\/月]\s*(\d{1,2})(?:日)?(?!\d)/u', $text, $matches)
            || preg_match('/(?<!\d)(20\d{2})\s*[-.\/]\s*(\d{1,2})\s+(\d{1,2})(?!\d)/u', $text, $matches)
            || preg_match('/(?<!\d)(20\d{2})\s+(\d{1,2})\s*[-.\/]\s*(\d{1,2})(?!\d)/u', $text, $matches)) {
            $year = (int) $matches[1]; $month = (int) $matches[2]; $day = (int) $matches[3];
        } elseif (preg_match('/(?<!\d)(\d{1,2})\s+(20\d{2})[.\/]\s*(\d{1,2})(?!\d)/u', $text, $matches)) {
            $year = (int) $matches[2]; $month = (int) $matches[3]; $day = (int) $matches[1];
        } elseif (preg_match('/(?<!\d)(\d{1,2})\s*[-.\/]\s*(\d{1,2})\s+(20\d{2})(?!\d)/u', $text, $matches)) {
            $year = (int) $matches[3]; $month = (int) $matches[1]; $day = (int) $matches[2];
        } else {
            return null;
        }
        if (!checkdate($month, $day, $year)) return null;
        $time = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), new \DateTimeZone('Asia/Shanghai'));
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function listing(string $html, string $baseUrl, int $limit = 5): array
    {
        $xpath = $this->document($html);
        $entries = [];
        foreach ($xpath->query('//a[@href]') as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            $path = parse_url($href, PHP_URL_PATH);
            if (!is_string($path) || !preg_match('~(?:^|/)info/\d+/\d+\.htm$~', $path)) continue;
            $url = $this->articleUrl($baseUrl, $href);
            if ($url === null) continue;
            $row = $xpath->query('ancestor::li[1] | ancestor::tr[1]', $anchor)->item(0);
            if (!$row) continue;
            $published = $this->date($row->textContent);
            if ($published === null) continue; // Excludes navigation and undated sidebar links.
            $titleNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " gg-ul-tt ")][1] | .//*[@title][1] | .//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6][1]', $anchor)->item(0);
            $titleText = $titleNode?->getAttribute('title') ?: ($titleNode?->textContent ?: $anchor->textContent);
            $title = trim(preg_replace('/\s+/u', ' ', $anchor->getAttribute('title') ?: $titleText));
            if (mb_strlen($title) < 3 || mb_strlen($title) > 180) continue;
            $entries[$url] = ['url' => $url, 'title' => $title, 'published_at' => $published, 'category' => 'notice'];
            if (count($entries) >= $limit) break;
        }
        return array_values($entries);
    }

    public function article(string $html): array
    {
        $xpath = $this->document($html);
        $content = $xpath->query('//*[@id="vsb_content" or @id="vsb_content_2" or contains(concat(" ", normalize-space(@class), " "), " v_news_content ")]')->item(0);
        if (!$content) throw new \RuntimeException('公告正文结构已变化');
        $parts = [];
        foreach ($xpath->query('.//text()[not(ancestor::style) and not(ancestor::script)]', $content) as $node) $parts[] = $node->textContent;
        $text = trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
        $attachments = [];
        foreach ($xpath->query('.//*[@href or @src or @data]', $content) as $node) {
            foreach (['href', 'src', 'data'] as $attribute) {
                $value = trim($node->getAttribute($attribute));
                if ($value !== '') $attachments[] = $value;
            }
        }
        $isPdfViewer = str_contains($text, '如果您无法在线浏览此 PDF 文件') && str_contains($text, 'PDF 阅读器');
        $result = [
            'summary' => mb_strlen($text) < 10 || $isPdfViewer
                ? '公告正文以附件为准，请打开原文核对。'
                : mb_substr($text, 0, 240),
            'content_hash' => hash('sha256', mb_substr($text, 0, 50000)."\n".implode("\n", $attachments)),
        ];
        $headline = $xpath->query('//h1[contains(concat(" ", normalize-space(@class), " "), " c-title ") or contains(concat(" ", normalize-space(@class), " "), " zwtit ")]')->item(0);
        if ($headline) {
            $title = trim(preg_replace('/\s+/u', ' ', $headline->textContent));
            if (mb_strlen($title) >= 3 && mb_strlen($title) <= 180) $result['title'] = $title;
        }
        return $result;
    }
}
