<?php
namespace UCASSer\Campus;

/** Public notice JSON returned by the university employment centre. */
class UcassEmploymentAdapter
{
    public const LIST_URL = 'https://jyzd.ucass.edu.cn/frontpage/ucass/html/newsList.html?id=3fcc824cecbc42aea3dce3700e5a4839';
    public const TOKEN_URL = 'https://jyzd.ucass.edu.cn/f/ajaxHome/getToken';
    public const API_LIST_URL = 'https://jyzd.ucass.edu.cn/f/newsCenter/ajax_list';
    public const API_DETAIL_URL = 'https://jyzd.ucass.edu.cn/f/newsCenter/ajax_view';
    public const CATEGORY_ID = '3fcc824cecbc42aea3dce3700e5a4839';

    private function payload(string $json): array
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('就业中心返回的 JSON 无法解析', 0, $error);
        }
        if (!is_array($data) || (string) ($data['state'] ?? '') !== '1') {
            throw new \RuntimeException('就业中心接口未返回成功状态');
        }
        return $data;
    }

    private function cleanText(mixed $value): string
    {
        if (!is_string($value)) return '';
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function publishedAt(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^20\d{2}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?$/D', $value)) return null;
        $format = strlen($value) === 16 ? '!Y-m-d H:i' : '!Y-m-d H:i:s';
        $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('Asia/Shanghai'));
        if (!$date || \DateTimeImmutable::getLastErrors() && \DateTimeImmutable::getLastErrors()['warning_count']) return null;
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function token(string $json): string
    {
        $data = $this->payload($json);
        $token = $data['data'] ?? null;
        if (!is_string($token) || strlen($token) < 8 || strlen($token) > 2048 || !preg_match('/^[A-Za-z0-9._~-]+$/D', $token)) {
            throw new \RuntimeException('就业中心未返回有效访问令牌');
        }
        return $token;
    }

    public function listing(string $json, int $limit = 5): array
    {
        $data = $this->payload($json);
        $rows = $data['object']['newsPage']['list'] ?? null;
        if (!is_array($rows)) throw new \RuntimeException('就业中心公告列表结构已变化');
        $entries = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $id = $row['id'] ?? null;
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) continue;
            // Construct the public URL from the validated ID; never follow API-supplied links.
            $title = $this->cleanText($row['title'] ?? null);
            $published = $this->publishedAt($row['releaseDate'] ?? null);
            if (mb_strlen($title) < 3 || mb_strlen($title) > 180 || !$published) continue;
            $entries[$id] = [
                'id' => $id,
                'url' => 'https://jyzd.ucass.edu.cn/frontpage/ucass/html/newsDetail.html?id='.$id,
                'title' => $title,
                'published_at' => $published,
                'category' => 'notice',
            ];
            if (count($entries) >= $limit) break;
        }
        return array_values($entries);
    }

    public function article(string $json, string $expectedId): array
    {
        $data = $this->payload($json);
        $article = $data['object']['article'] ?? null;
        if (!is_array($article) || ($article['id'] ?? null) !== $expectedId) {
            throw new \RuntimeException('就业中心公告详情与列表不一致');
        }
        $title = $this->cleanText($article['title'] ?? null);
        $description = $this->cleanText($article['description'] ?? null);
        $body = $this->cleanText($article['articleData']['content'] ?? null);
        $summary = mb_substr($description ?: $body, 0, 240);
        if (mb_strlen($summary) < 10) $summary = '请打开就业指导中心原文，核对公告正文及附件。';
        $result = ['summary' => $summary, 'content_hash' => hash('sha256', mb_substr($body ?: $description, 0, 50000))];
        if (mb_strlen($title) >= 3 && mb_strlen($title) <= 180) $result['title'] = $title;
        return $result;
    }
}
