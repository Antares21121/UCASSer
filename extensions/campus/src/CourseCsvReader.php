<?php

namespace UCASSer\Campus;

/** Read only the four approved public fields from a locally held course export. */
class CourseCsvReader
{
    private const HEADERS = [
        'code' => ['课程代码', '课程编号', '课程号', 'course_code', 'code'],
        'name' => ['课程名称', '课程名', 'course_name', 'name'],
        'college' => ['开课学院', '开课单位', '教学单位', '学院', '院系', 'college'],
        'semester' => ['开课学期', '学年学期', '学期', 'semester'],
    ];
    private const LIMITS = ['code' => 32, 'name' => 80, 'college' => 120, 'semester' => 80];

    /** @return array{rows: array<string, array<string, string>>, rows_read: int, file_sha256: string} */
    public function read(string $file): array
    {
        if (!is_file($file) || !is_readable($file) || filesize($file) > 20 * 1024 * 1024) {
            throw new \InvalidArgumentException('CSV 不存在、不可读或超过 20 MB');
        }
        $stream = fopen($file, 'rb');
        if ($stream === false) throw new \InvalidArgumentException('无法读取 CSV');
        try {
            $header = fgetcsv($stream, 0, ',', '"', '');
            if (!is_array($header)) throw new \InvalidArgumentException('CSV 缺少标题行');
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $columns = [];
            foreach ($header as $position => $label) {
                if (!mb_check_encoding($label, 'UTF-8')) throw new \InvalidArgumentException('CSV 须使用 UTF-8 编码');
                foreach (self::HEADERS as $key => $aliases) {
                    if (!in_array(trim($label), $aliases, true)) continue;
                    if (isset($columns[$key])) throw new \InvalidArgumentException("CSV 有重复的 $key 列");
                    $columns[$key] = $position;
                }
            }
            if (count($columns) !== 4) {
                throw new \InvalidArgumentException('CSV 需要课程代码、课程名称、开课学院、开课学期四列；其他列会被忽略');
            }
            $rows = [];
            $read = 0;
            while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if (count($cells) === 1 && trim($cells[0]) === '') continue;
                if (++$read > 20000) throw new \InvalidArgumentException('CSV 最多允许 20000 行课程');
                $row = [];
                foreach ($columns as $key => $position) {
                    $value = $cells[$position] ?? '';
                    if (!mb_check_encoding($value, 'UTF-8')) throw new \InvalidArgumentException('CSV 须使用 UTF-8 编码');
                    $value = trim(preg_replace('/\s+/u', ' ', $value));
                    if ($value === '' || preg_match('/[\p{C}]/u', $value) || mb_strlen($value) > self::LIMITS[$key]) {
                        throw new \InvalidArgumentException('第 '.($read + 1)." 行的 $key 缺失、过长或含控制字符");
                    }
                    $row[$key] = $value;
                }
                $identity = self::identity($row);
                if (isset($rows[$identity]) && $rows[$identity]['name'] !== $row['name']) {
                    throw new \InvalidArgumentException('同一课程代码、学院、学期对应了不同课程名，请先核对导出文件');
                }
                $rows[$identity] = $row;
            }
            if (!$rows) throw new \InvalidArgumentException('CSV 没有可导入的课程');
            return ['rows' => $rows, 'rows_read' => $read, 'file_sha256' => hash_file('sha256', $file)];
        } finally {
            fclose($stream);
        }
    }

    public static function identity(array $row): string
    {
        return hash('sha256', $row['code']."\0".$row['college']."\0".$row['semester']);
    }
}
