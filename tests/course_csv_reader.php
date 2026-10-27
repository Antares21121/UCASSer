<?php

require __DIR__.'/../extensions/campus/src/CourseCsvReader.php';

use UCASSer\Campus\CourseCsvReader;

function checkCourseCsv(bool $passed, string $message): void
{
    if (!$passed) throw new RuntimeException($message);
}

$file = tempnam(sys_get_temp_dir(), 'ucasser-course-csv-');
if ($file === false) throw new RuntimeException('无法建立测试文件');
try {
    // A real export may contain more columns. None of them may enter the public row.
    file_put_contents($file, "\xEF\xBB\xBF课程代码,课程名称,开课学院,开课学期,教师姓名,教室\n".
        "SO101,社会学概论,社会与民族学院,2026秋,某老师,A101\n".
        "SO101,社会学概论,社会与民族学院,2026秋,另一老师,B202\n");
    $result = (new CourseCsvReader())->read($file);
    checkCourseCsv($result['rows_read'] === 2, '应读取两行原始课程');
    checkCourseCsv(count($result['rows']) === 1, '同一课程的多个班次应合并为一个目录条目');
    $row = array_values($result['rows'])[0];
    checkCourseCsv(array_keys($row) === ['code', 'name', 'college', 'semester'], '不得保留导出文件中的私人列');
    checkCourseCsv(!str_contains(json_encode($row, JSON_UNESCAPED_UNICODE), '某老师'), '教师姓名进入了公开行');
    checkCourseCsv(strlen($result['file_sha256']) === 64, '应记录文件指纹以便追溯导入批次');

    file_put_contents($file, "课程代码,课程名称,开课学院,开课学期\nSO101,社会学概论,社会与民族学院,2026秋\nSO101,民族学概论,社会与民族学院,2026秋\n");
    try {
        (new CourseCsvReader())->read($file);
        throw new RuntimeException('冲突的课程名称未拒绝');
    } catch (InvalidArgumentException $exception) {
        checkCourseCsv(str_contains($exception->getMessage(), '不同课程名'), '冲突错误不明确');
    }
} finally {
    unlink($file);
}

echo "Course CSV privacy and identity OK\n";
