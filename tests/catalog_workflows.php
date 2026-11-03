<?php
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'development') throw new RuntimeException('Development CLI only');
define('UCASSER_OPERATOR_PROVISION_LIBRARY', true);
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../scripts/provision_operator.php';
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\ServerRequest;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
$c = (require __DIR__.'/../site.php')->bootApp()->getContainer();
$db = $c->make(Illuminate\Database\ConnectionInterface::class);
$catalog = $c->make(UCASSer\Campus\Catalog::class);
$check = function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
$db->beginTransaction();
try {
    $marker = bin2hex(random_bytes(5)); $name = 'catalog_'.$marker;
    $p = ucasserProvisionOperator($c, ['username' => $name, 'email' => $name.'@example.invalid', 'password' => bin2hex(random_bytes(16)), 'owner_email_confirmed' => true], true);
    $actor = Flarum\User\User::findOrFail($p['user_id']);
    $college = $catalog->saveCollege($actor, ['name' => '验收学院'.$marker, 'code' => 'FIX-'.$marker, 'status' => 'active'])['data'];
    $check($college && $college->resource_category_id, 'New college must return its ID and link to a new directory node');
    $course = $catalog->saveCourse($actor, ['college_id' => $college->id, 'code' => 'FIX-'.$marker, 'name' => '验收课程'.$marker,
        'semester' => '2026秋', 'type' => '本科', 'nature' => '必修课', 'description' => '已有课程简介', 'external_key' => 'fixture-'.$marker])['data'];
    $check($course && $course->id, 'New course was stored but response was null');
    $check($db->table('campus_audit')->where('action', 'college.create')->where('target', (string) $college->id)->exists()
        && $db->table('campus_audit')->where('action', 'course.create')->where('target', (string) $course->id)->exists(), 'Creation audit incorrectly reports update');
    $check($c->make(UCASSer\Campus\ResourcesSyncCommand::class)->run(new ArrayInput([]), new BufferedOutput()) === 0, 'Directory sync failed');
    $controller = $c->make(UCASSer\Campus\CatalogAdminController::class);
    $get = function ($route, $query = []) use ($controller, $actor) {
        $r = RequestUtil::withActor(new ServerRequest(), $actor)->withAttribute('routeName', 'campus.control.catalog.'.$route)->withQueryParams($query);
        return json_decode((string) $controller->handle($r)->getBody(), true)['data'];
    };
    $rows = $get('courses', ['search' => $college->name])['items'];
    $check(count($rows) === 1 && $rows[0]['id'] === $course->id, 'College index does not filter courses');
    $row = $rows[0]; $categoryId = $row['resource_category_id'];
    $check($row['description'] === '已有课程简介' && $row['external_key'] === 'fixture-'.$marker && $categoryId, 'Course editor loses saved description, external key or directory association');
    $categories = $get('categories')['categories'];
    $leaf = array_values(array_filter($categories, fn ($r) => $r['id'] === $categoryId))[0];
    $check(str_contains($leaf['path'], $college->name.' / 必修课 / '.$course->name), 'Same-named courses lack full college/type/course path');
    $url = 'https://example.org/catalog-regression/'.$marker;
    $resource = $catalog->saveResource($actor, ['title' => '回归资料'.$marker, 'category_id' => $categoryId, 'url' => $url, 'status' => 'active'])['data'];
    $check($resource && $resource->id, 'New resource was stored but response was null');
    $public = $c->make(UCASSer\Resources\Api\ResourcesController::class);
    $visible = function () use ($public, $marker) {
        $r = RequestUtil::withActor(new ServerRequest(), new Flarum\User\Guest())->withQueryParams(['q' => $marker]);
        return json_decode((string) $public->handle($r)->getBody(), true)['resources'];
    };
    $check(count($visible()) === 1, 'New active resource not public');
    $catalog->saveResource($actor, ['id' => $resource->id, 'status' => 'archived', 'reason' => '回归归档']);
    $check($visible() === [] && $db->table('ucasser_resource_revisions')->where('resource_id', $resource->id)->count() === 2, 'Archive must hide the entry while retaining revisions');
    $replacement = $catalog->saveResource($actor, ['title' => '重新添加'.$marker, 'category_id' => $categoryId, 'url' => $url, 'status' => 'active'])['data'];
    $check($replacement->id !== $resource->id && count($visible()) === 1, 'Archived URL cannot be re-added safely');
    foreach ([fn () => $catalog->saveCollege($actor, ['id' => $college->id, 'name' => null]),
        fn () => $catalog->saveCourse($actor, ['id' => $course->id, 'name' => '']),
        fn () => $catalog->saveResource($actor, ['id' => $replacement->id, 'title' => null])] as $invalid) {
        $failed = false; try { $invalid(); } catch (Flarum\Foundation\ValidationException $e) { $failed = true; }
        $check($failed, 'Clearing a required field must be a validation error');
    }
    echo "Catalog create responses/audit, college filter, full category path, preserved edit fields, archive visibility, revision history and URL re-add OK\n";
} finally { $db->rollBack(); }
