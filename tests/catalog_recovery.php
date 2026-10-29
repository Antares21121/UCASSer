<?php
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'development') throw new RuntimeException('Development CLI only');
define('UCASSER_CATALOG_RECOVERY_LIBRARY', true);
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../scripts/restore_catalog.php';
$container = (require __DIR__.'/../site.php')->bootApp()->getContainer();
$db = $container->make(Illuminate\Database\ConnectionInterface::class);
$tables = ['campus_colleges', 'campus_courses', 'campus_course_offerings', 'ucasser_resource_categories', 'campus_import_batches', 'campus_audit', 'users', 'campus_operators'];
$counts = function () use ($db, $tables): array { return array_map(fn ($table) => $db->table($table)->count(), $tables); };
$check = function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
$before = $counts();
$db->beginTransaction();
try {
    $preview = ucasserRestoreCatalog($container, dirname(__DIR__), false);
    $check($preview['public_courses_linked'] === 501, 'Preview must validate all official course links');
    $check($counts() === $before, 'Preview must retain all data and audit rows');
    $first = ucasserRestoreCatalog($container, dirname(__DIR__), true);
    $after = $counts();
    $second = ucasserRestoreCatalog($container, dirname(__DIR__), true);
    $check($first['public_courses_linked'] === 501, 'Apply must link all official courses');
    $check($second['added_colleges'] === 0 && $second['added_courses'] === 0, 'Repeating recovery must add no duplicate catalog entries');
    $check($counts() === $after, 'Repeating recovery must preserve counts including audit batches');
    $check($after[6] === $before[6] && $after[7] === $before[7], 'Recovery must not create accounts or grant operator permissions');
    echo "Public catalog recovery preview, apply, repeat and account preservation OK\n";
} finally {
    $db->rollBack();
}
$check($counts() === $before, 'Test fixtures must be rolled back');
