<?php
namespace UCASSer\Campus;

use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Shared validation and import engine for the operator catalog. Both the
 * single-row forms and the batch CSV/JSON imports run through the same rules,
 * so no client can write data the import path would reject. Every applied
 * batch records per-row before/after state and can be rolled back.
 */
class Catalog
{
    public const KINDS = ['colleges', 'courses', 'resources'];
    private const MAX_ROWS = 500;
    private const MAX_BODY = 2 * 1024 * 1024;

    public function __construct(private ConnectionInterface $db, private SettingsRepositoryInterface $settings, private Identity $identity) {}

    public function hasColleges(): bool
    {
        return $this->db->getSchemaBuilder()->hasTable('campus_colleges');
    }

    // ---------------------------------------------------------------- text
    public function text(mixed $value, int $max, int $min = 0, string $key = 'value'): string
    {
        if (!is_string($value)) throw new ValidationException([$key => '请输入文本']);
        $value = trim($value);
        if (mb_strlen($value) < $min || mb_strlen($value) > $max) throw new ValidationException([$key => "长度须在 $min–$max 字之间"]);
        return $value;
    }

    public function optional(mixed $value, int $max, string $key): ?string
    {
        if ($value === null || $value === '') return null;
        return $this->text($value, $max, 0, $key);
    }

    // -------------------------------------------------------------- colleges
    public function validateCollege(array $d, bool $partial = false): array
    {
        $name = $partial ? $this->optional($d['name'] ?? null, 120, 'name') : $this->text($d['name'] ?? null, 120, 2, 'name');
        $out = [];
        if (array_key_exists('name', $d)) $out['name'] = $name;
        if (array_key_exists('code', $d)) $out['code'] = $partial ? $this->optional($d['code'] ?? null, 40, 'code') : $this->text($d['code'] ?? null, 40, 1, 'code');
        if (array_key_exists('aliases', $d)) $out['aliases'] = $this->optional($d['aliases'] ?? null, 300, 'aliases');
        if (array_key_exists('position', $d)) {
            if (!is_int($d['position']) || $d['position'] < 0) throw new ValidationException(['position' => '排序须为非负整数']);
            $out['position'] = $d['position'];
        }
        if (array_key_exists('status', $d)) {
            if (!in_array($d['status'], ['active', 'inactive'], true)) throw new ValidationException(['status' => '状态无效']);
            $out['status'] = $d['status'];
        }
        if (array_key_exists('external_key', $d)) $out['external_key'] = $this->optional($d['external_key'] ?? null, 80, 'external_key');
        $dupe = $this->db->table('campus_colleges')->where('name', $name)->first();
        if ($dupe && (!$partial || !isset($d['id']) || (int) $dupe->id !== (int) $d['id'])) throw new ValidationException(['name' => '学院名称已存在，请编辑原记录']);
        return $out;
    }

    private function collegeCategoryNode(string $name): ?int
    {
        if (!$this->db->getSchemaBuilder()->hasTable('ucasser_resource_categories')) return null;
        $table = $this->db->table('ucasser_resource_categories');
        $node = $table->where('kind', 'college')->where('name', $name)->first();
        if ($node) return (int) $node->id;
        $root = $table->where('kind', 'root')->where('is_campus', 1)->whereNull('parent_id')->first();
        if (!$root) return null;
        $now = gmdate('Y-m-d H:i:s');
        return (int) $table->insertGetId(['parent_id' => $root->id, 'kind' => 'college', 'name' => $name, 'position' => 100, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function saveCollege(User $actor, array $d): array
    {
        $id = isset($d['id']) ? (int) $d['id'] : null;
        $row = $id ? $this->db->table('campus_colleges')->where('id', $id)->first() : null;
        if ($id && !$row) throw new ValidationException(['id' => '学院不存在']);
        $values = $this->validateCollege($d, $id !== null);
        $now = gmdate('Y-m-d H:i:s');
        $out = $this->db->transaction(function () use ($actor, $id, $row, $values, $now, $d) {
            $before = $row ? (array) $row : null;
            if ($id) {
                $this->db->table('campus_colleges')->where('id', $id)->update($values + ['updated_at' => $now]);
                if (isset($values['name']) && $row->resource_category_id) {
                    // Keep the mirrored directory node in sync when we created it.
                    $this->db->table('ucasser_resource_categories')->where('id', $row->resource_category_id)->where('name', $row->name)->update(['name' => $values['name']]);
                }
            } else {
                $values += ['resource_category_id' => $this->collegeCategoryNode($values['name']), 'created_at' => $now, 'updated_at' => $now];
                $id = $this->db->table('campus_colleges')->insertGetId($values);
            }
            $this->identity->audit($actor, $id ? 'college.update' : 'college.create', (string) $id, $d['reason'] ?? null);
            return ['id' => $id, 'before' => $before, 'values' => $values];
        });
        return ['data' => $this->db->table('campus_colleges')->where('id', $out['id'])->first()];
    }

    // ---------------------------------------------------------------- courses
    public function collegeByKey(string $key): ?object
    {
        if (!$this->hasColleges()) return null;
        return $this->db->table('campus_colleges')->where('code', $key)->orWhere('external_key', $key)->first();
    }

    public function validateCourse(array $d, bool $partial = false): array
    {
        $name = $partial ? $this->optional($d['name'] ?? null, 80, 'name') : $this->text($d['name'] ?? null, 80, 2, 'name');
        $out = [];
        if (array_key_exists('name', $d)) $out['name'] = $name;
        if (array_key_exists('code', $d)) $out['code'] = $this->optional($d['code'] ?? null, 32, 'code');
        if (array_key_exists('semester', $d)) $out['semester'] = $this->optional($d['semester'] ?? null, 80, 'semester');
        if (array_key_exists('type', $d)) $out['type'] = $this->optional($d['type'] ?? null, 80, 'type');
        if (array_key_exists('nature', $d)) $out['nature'] = $this->optional($d['nature'] ?? null, 80, 'nature');
        if (array_key_exists('description', $d)) $out['description'] = $this->optional($d['description'] ?? null, 2000, 'description');
        if (array_key_exists('external_key', $d)) $out['external_key'] = $this->optional($d['external_key'] ?? null, 80, 'external_key');
        if (array_key_exists('college_id', $d)) {
            $collegeId = $d['college_id'] === null || $d['college_id'] === '' ? null : (int) $d['college_id'];
            if ($collegeId && !$this->db->table('campus_colleges')->where('id', $collegeId)->exists()) throw new ValidationException(['college_id' => '学院不存在']);
            $out['college_id'] = $collegeId;
        }
        if (!$partial && empty($out['code']) && empty($out['external_key'])) throw new ValidationException(['code' => '请提供课程代码或外部稳定编号']);
        return $out;
    }

    /** course-column subset that never writes NULL into the NOT NULL fields. */
    private static function courseColumns(array $values): array
    {
        $out = array_intersect_key($values, array_flip(['name', 'type', 'grade', 'semester', 'nature', 'college_id']));
        foreach (['type', 'grade', 'semester', 'nature'] as $key) {
            if (array_key_exists($key, $out) && $out[$key] === null) $out[$key] = '';
        }
        return $out;
    }

    public static function identityHash(?int $collegeId, string $code, string $semester): string
    {
        return hash('sha256', implode('|', [(string) $collegeId, mb_strtolower(trim($code)), trim($semester)]));
    }

    public function saveCourse(User $actor, array $d): array
    {
        $id = isset($d['id']) ? (int) $d['id'] : null;
        $row = $id ? $this->db->table('campus_courses')->where('id', $id)->first() : null;
        if ($id && !$row) throw new ValidationException(['id' => '课程不存在']);
        $values = $this->validateCourse($d, $id !== null);
        // campus_courses holds the review-facing catalog fields; code and
        // import identity live on the offering row.
        $courseValues = self::courseColumns($values);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->transaction(function () use ($actor, $id, $row, $values, $courseValues, $now, $d) {
            if ($id) {
                $this->db->table('campus_courses')->where('id', $id)->update($courseValues + ['version' => $row->version + 1]);
            } else {
                $id = $this->db->table('campus_courses')->insertGetId($courseValues + ['version' => 1]);
            }
            // Keep the offering identity row in sync (one offering per course).
            $offering = $this->db->table('campus_course_offerings')->where('course_id', $id)->first();
            $code = $values['code'] ?? $offering?->code ?? '';
            $semester = $values['semester'] ?? $offering?->semester ?? '';
            $collegeId = array_key_exists('college_id', $values) ? $values['college_id'] : $offering?->college_id;
            $collegeName = $collegeId ? $this->db->table('campus_colleges')->where('id', $collegeId)->value('name') : null;
            $payload = [
                'course_id' => $id,
                'identity_hash' => self::identityHash($collegeId, $code, $semester),
                'code' => $code, 'name' => $values['name'] ?? $row?->name ?? '',
                'college' => $collegeName ?? ($offering?->college ?? ''), 'college_id' => $collegeId,
                'semester' => $semester, 'nature' => $values['nature'] ?? $offering?->nature,
                'description' => $values['description'] ?? $offering?->description,
                'external_key' => $values['external_key'] ?? $offering?->external_key,
                'imported_at' => $offering?->imported_at ?? $now,
            ];
            if ($offering) $this->db->table('campus_course_offerings')->where('id', $offering->id)->update($payload);
            else $this->db->table('campus_course_offerings')->insert($payload);
            $this->identity->audit($actor, $id ? 'course.update' : 'course.create', (string) $id, $d['reason'] ?? null);
        });
        return ['data' => $this->db->table('campus_courses')->where('id', $id)->first()];
    }

    // -------------------------------------------------------------- resources
    public function validateResource(array $d, bool $partial = false): array
    {
        $out = [];
        if (array_key_exists('title', $d)) $out['title'] = $partial ? $this->optional($d['title'] ?? null, 180, 'title') : $this->text($d['title'] ?? null, 180, 2, 'title');
        if (array_key_exists('url', $d)) {
            $url = $this->text($d['url'] ?? null, 2048, 1, 'url');
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if ($scheme !== 'https' && !($this->settings->get('ucasser-campus.allow_http_resources') === '1' && $scheme === 'http')) {
                throw new ValidationException(['url' => '共享资料链接只允许 https（特殊情况由管理员配置）']);
            }
            $host = parse_url($url, PHP_URL_HOST);
            if (!$host || !str_contains($host, '.')) throw new ValidationException(['url' => '链接域名无效']);
            $dupe = $this->db->table('ucasser_resources')->where('url', $url)->first();
            if ($dupe && (!$partial || !isset($d['id']) || (int) $dupe->id !== (int) $d['id'])) throw new ValidationException(['url' => '该链接已存在，请编辑原资料']);
            $out['url'] = $url;
        }
        if (array_key_exists('category_id', $d)) {
            $categoryId = (int) $d['category_id'];
            $category = $this->db->table('ucasser_resource_categories')->where('id', $categoryId)->first();
            if (!$category || !in_array($category->kind, ['course', 'external_topic'], true)) throw new ValidationException(['category_id' => '资料只能放在具体课程或外部主题下']);
            $out['category_id'] = $categoryId;
        }
        foreach (['resource_type' => 40, 'access_code' => 80] as $key => $max) {
            if (array_key_exists($key, $d)) $out[$key] = $this->optional($d[$key] ?? null, $max, $key);
        }
        foreach (['description' => 2000, 'source_url' => 2048, 'share_permission' => 2000] as $key => $max) {
            if (array_key_exists($key, $d)) {
                $value = $this->optional($d[$key] ?? null, $max, $key);
                if ($key === 'source_url' && $value) {
                    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
                    if (!in_array($scheme, ['https', 'http'], true)) throw new ValidationException(['source_url' => '来源链接无效']);
                }
                $out[$key] = $value;
            }
        }
        if (array_key_exists('status', $d)) {
            if (!in_array($d['status'], ['active', 'needs_review', 'broken', 'archived'], true)) throw new ValidationException(['status' => '状态无效']);
            $out['status'] = $d['status'];
        }
        if (array_key_exists('external_key', $d)) $out['external_key'] = $this->optional($d['external_key'] ?? null, 80, 'external_key');
        if (!$partial && ($out['url'] ?? '') === '' && ($out['external_key'] ?? '') === '') throw new ValidationException(['url' => '请提供资料链接']);
        return $out;
    }

    public function saveResource(User $actor, array $d): array
    {
        $id = isset($d['id']) ? (int) $d['id'] : null;
        $row = $id ? $this->db->table('ucasser_resources')->where('id', $id)->first() : null;
        if ($id && !$row) throw new ValidationException(['id' => '资料不存在']);
        $values = $this->validateResource($d, $id !== null);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->transaction(function () use ($actor, $id, $row, $values, $now, $d) {
            $previous = $id ? (array) $row : null;
            if ($id) {
                $this->db->table('ucasser_resources')->where('id', $id)->update($values + ['updated_by' => $actor->id, 'updated_at' => $now]);
            } else {
                $id = $this->db->table('ucasser_resources')->insertGetId($values + ['updated_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now]);
            }
            $new = $this->db->table('ucasser_resources')->where('id', $id)->first();
            $this->db->table('ucasser_resource_revisions')->insert([
                'resource_id' => $id, 'editor_id' => $actor->id,
                'previous_data' => $previous ? json_encode($previous, JSON_UNESCAPED_UNICODE) : null,
                'new_data' => json_encode($new, JSON_UNESCAPED_UNICODE),
                'reason' => $this->optional($d['reason'] ?? null, 255, 'reason'), 'created_at' => $now,
            ]);
            $this->identity->audit($actor, $previous ? 'resource.update' : 'resource.create', (string) $id, $d['reason'] ?? null);
        });
        return ['data' => $this->db->table('ucasser_resources')->where('id', $id)->first()];
    }

    // ----------------------------------------------------------- batch import
    public function rowsFromPayload(mixed $rows): array
    {
        if (!is_array($rows) || count($rows) > self::MAX_ROWS) throw new ValidationException(['rows' => '每批最多 '.self::MAX_ROWS.' 行']);
        $out = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) throw new ValidationException(['rows' => '第 '.($index + 1).' 行格式无效']);
            $out[] = $row;
        }
        return $out;
    }

    /** Preview or apply one batch; returns per-row results and totals. */
    public function import(User $actor, string $kind, array $rows, bool $apply): array
    {
        if (!in_array($kind, self::KINDS, true)) throw new ValidationException(['kind' => '导入类型无效']);
        $results = [];
        $valid = $new = $updated = $skipped = $conflicts = $failed = 0;
        $plan = [];
        foreach ($rows as $index => $row) {
            $line = $index + 1;
            try {
                $externalKey = isset($row['external_key']) ? $this->optional($row['external_key'], 80, 'external_key') : null;
                $plan[] = match ($kind) {
                    'colleges' => $this->planCollege($line, $row, $externalKey, $results, $valid, $new, $updated, $skipped, $conflicts, $failed),
                    'courses' => $this->planCourse($line, $row, $externalKey, $results, $valid, $new, $updated, $skipped, $conflicts, $failed),
                    'resources' => $this->planResource($line, $row, $externalKey, $results, $valid, $new, $updated, $skipped, $conflicts, $failed),
                };
            } catch (ValidationException $error) {
                $failed++;
                $results[] = ['line' => $line, 'action' => 'failed', 'error' => implode('；', array_values($error->getAttributes()))];
            }
        }
        $summary = ['total' => count($rows), 'valid' => $valid, 'new' => $new, 'updated' => $updated, 'skipped' => $skipped, 'conflicts' => $conflicts, 'failed' => $failed, 'results' => $results];
        if (!$apply) return $summary;
        $batchId = $this->db->table('campus_import_batches')->insertGetId([
            'kind' => $kind, 'actor_id' => $actor->id, 'total_rows' => count($rows),
            'applied_rows' => count($plan), 'status' => 'applied', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->db->transaction(function () use ($plan, $batchId, $actor, $kind) {
            foreach ($plan as $step) {
                ($step->apply)($actor);
                $this->db->table('campus_import_batch_rows')->insert([
                    'batch_id' => $batchId, 'external_key' => $step->externalKey, 'action' => $step->action,
                    'entity_id' => $step->entityId, 'before' => $step->before, 'after' => $step->after, 'error' => $step->error,
                ]);
            }
            $this->identity->audit($actor, 'catalog.import.'.$kind, 'batch:'.$batchId, 'applied '.count($plan).' rows');
        });
        $summary['batch_id'] = $batchId;
        return $summary;
    }

    /** Build an executable import step with its own rollback bookkeeping. */
    private function step(int $line, string $externalKey, string $action, ?int $entityId, ?string $before, string $after, callable $apply, array &$results, ?string $error = null): object
    {
        $results[] = ['line' => $line, 'action' => $action, 'external_key' => $externalKey, 'error' => $error];
        $step = (object) [
            'line' => $line, 'externalKey' => $externalKey, 'action' => $action,
            'entityId' => $entityId, 'before' => $before, 'after' => $after, 'error' => $error,
        ];
        $step->apply = $apply;
        return $step;
    }

    private function planCollege(int $line, array $row, ?string $externalKey, array &$results, int &$valid, int &$new, int &$updated, int &$skipped, int &$conflicts, int &$failed): object
    {
        if (!$externalKey) throw new ValidationException(['external_key' => '缺少外部稳定编号']);
        $existing = $this->db->table('campus_colleges')->where('external_key', $externalKey)->orWhere('code', $row['code'] ?? '')->first();
        if ($existing) $row['id'] = (int) $existing->id;
        $values = $this->validateCollege($row, $existing !== null);
        $valid++;
        $now = gmdate('Y-m-d H:i:s');
        if ($existing) {
            $changed = array_diff_assoc($values, array_intersect_key((array) $existing, $values));
            if (!$changed) { $skipped++; return $this->step($line, $externalKey, 'skipped', (int) $existing->id, null, json_encode($values, JSON_UNESCAPED_UNICODE), fn () => null, $results); }
            if (($values['name'] ?? $existing->name) !== $existing->name) { $conflicts++; return $this->step($line, $externalKey, 'conflict', (int) $existing->id, json_encode($existing, JSON_UNESCAPED_UNICODE), json_encode($values, JSON_UNESCAPED_UNICODE), fn () => null, $results, '名称变化需人工处理，已跳过'); }
            $updated++;
            return $this->step($line, $externalKey, 'updated', (int) $existing->id, json_encode($existing, JSON_UNESCAPED_UNICODE), json_encode($values, JSON_UNESCAPED_UNICODE), function () use ($existing, $values, $now) {
                $this->db->table('campus_colleges')->where('id', $existing->id)->update($values + ['updated_at' => $now]);
            }, $results);
        }
        $new++;
        return $this->step($line, $externalKey, 'inserted', null, null, json_encode($values, JSON_UNESCAPED_UNICODE), function (User $actor) use ($values, $now) {
            $values += ['resource_category_id' => $this->collegeCategoryNode($values['name']), 'created_at' => $now, 'updated_at' => $now];
            $this->db->table('campus_colleges')->insert($values);
        }, $results);
    }

    private function planCourse(int $line, array $row, ?string $externalKey, array &$results, int &$valid, int &$new, int &$updated, int &$skipped, int &$conflicts, int &$failed): object
    {
        if (!$externalKey) throw new ValidationException(['external_key' => '缺少外部稳定编号']);
        // The import template identifies the college by its stable code so the
        // same CSV works on any install; resolve it before validation.
        if (empty($row['college_id']) && !empty($row['college_key'])) {
            $collegeId = $this->db->table('campus_colleges')->where('code', (string) $row['college_key'])->value('id');
            if (!$collegeId) throw new ValidationException(['college_key' => '开课学院编号不存在：'.(string) $row['college_key']]);
            $row['college_id'] = (int) $collegeId;
        }
        $values = $this->validateCourse($row);
        $hash = self::identityHash($values['college_id'] ?? null, $values['code'] ?? '', $values['semester'] ?? '');
        $offering = $this->db->table('campus_course_offerings')->where('external_key', $externalKey)->orWhere('identity_hash', $hash)->first();
        $valid++;
        $now = gmdate('Y-m-d H:i:s');
        if ($offering) {
            $course = $this->db->table('campus_courses')->where('id', $offering->course_id)->first();
            $current = (array) $offering + (array) $course;
            $next = $values + ['course_id' => $offering->course_id];
            $changed = array_diff_assoc($next, array_intersect_key($current, $next));
            if (!$changed) { $skipped++; return $this->step($line, $externalKey, 'skipped', (int) $offering->course_id, null, json_encode($next, JSON_UNESCAPED_UNICODE), fn () => null, $results); }
            if (($values['name'] ?? $course->name) !== $course->name) { $conflicts++; return $this->step($line, $externalKey, 'conflict', (int) $offering->course_id, json_encode($current, JSON_UNESCAPED_UNICODE), json_encode($next, JSON_UNESCAPED_UNICODE), fn () => null, $results, '课程名称变化需人工处理，已跳过'); }
            $updated++;
            return $this->step($line, $externalKey, 'updated', (int) $offering->course_id, json_encode($current, JSON_UNESCAPED_UNICODE), json_encode($next, JSON_UNESCAPED_UNICODE), function () use ($offering, $course, $values, $now) {
                $courseValues = self::courseColumns($values);
                $this->db->table('campus_courses')->where('id', $course->id)->update($courseValues + ['version' => $course->version + 1]);
                $collegeName = isset($values['college_id']) ? $this->db->table('campus_colleges')->where('id', $values['college_id'])->value('name') : $offering->college;
                $this->db->table('campus_course_offerings')->where('id', $offering->id)->update([
                    'code' => $values['code'] ?? $offering->code, 'name' => $values['name'] ?? $offering->name,
                    'college_id' => array_key_exists('college_id', $values) ? $values['college_id'] : $offering->college_id,
                    'college' => $collegeName ?? $offering->college, 'semester' => $values['semester'] ?? $offering->semester,
                    'nature' => array_key_exists('nature', $values) ? $values['nature'] : $offering->nature,
                    'description' => array_key_exists('description', $values) ? $values['description'] : $offering->description,
                    'external_key' => $values['external_key'] ?? $offering->external_key ?? $externalKey,
                    'identity_hash' => self::identityHash(array_key_exists('college_id', $values) ? $values['college_id'] : $offering->college_id, $values['code'] ?? $offering->code, $values['semester'] ?? $offering->semester),
                ]);
            }, $results);
        }
        $new++;
        return $this->step($line, $externalKey, 'inserted', null, null, json_encode($values, JSON_UNESCAPED_UNICODE), function (User $actor) use ($values, $externalKey, $now) {
            $courseValues = self::courseColumns($values);
            $courseId = $this->db->table('campus_courses')->insertGetId($courseValues + ['version' => 1]);
            $collegeName = isset($values['college_id']) && $values['college_id'] ? $this->db->table('campus_colleges')->where('id', $values['college_id'])->value('name') : '';
            $this->db->table('campus_course_offerings')->insert([
                'course_id' => $courseId,
                'identity_hash' => self::identityHash($values['college_id'] ?? null, $values['code'] ?? '', $values['semester'] ?? ''),
                'code' => $values['code'] ?? '', 'name' => $values['name'],
                'college' => $collegeName, 'college_id' => $values['college_id'] ?? null,
                'semester' => $values['semester'] ?? '', 'nature' => $values['nature'] ?? null,
                'description' => $values['description'] ?? null, 'external_key' => $externalKey,
                'imported_at' => $now,
            ]);
        }, $results);
    }

    private function planResource(int $line, array $row, ?string $externalKey, array &$results, int &$valid, int &$new, int &$updated, int &$skipped, int &$conflicts, int &$failed): object
    {
        if (!$externalKey) throw new ValidationException(['external_key' => '缺少外部稳定编号']);
        $existing = $this->db->getSchemaBuilder()->hasColumn('ucasser_resources', 'external_key')
            ? $this->db->table('ucasser_resources')->where('external_key', $externalKey)->first() : null;
        if (!$existing && isset($row['url'])) $existing = $this->db->table('ucasser_resources')->where('url', $row['url'])->first();
        if ($existing) $row['id'] = (int) $existing->id;
        $values = $this->validateResource($row, $existing !== null);
        $valid++;
        $now = gmdate('Y-m-d H:i:s');
        if ($existing) {
            $changed = array_diff_assoc($values, array_intersect_key((array) $existing, $values));
            if (!$changed) { $skipped++; return $this->step($line, $externalKey, 'skipped', (int) $existing->id, null, json_encode($values, JSON_UNESCAPED_UNICODE), fn () => null, $results); }
            if (($values['title'] ?? $existing->title) !== $existing->title) { $conflicts++; return $this->step($line, $externalKey, 'conflict', (int) $existing->id, json_encode($existing, JSON_UNESCAPED_UNICODE), json_encode($values, JSON_UNESCAPED_UNICODE), fn () => null, $results, '标题变化需人工处理，已跳过'); }
            $updated++;
            return $this->step($line, $externalKey, 'updated', (int) $existing->id, json_encode($existing, JSON_UNESCAPED_UNICODE), json_encode($values, JSON_UNESCAPED_UNICODE), function (User $actor) use ($existing, $values, $now) {
                $this->db->table('ucasser_resources')->where('id', $existing->id)->update($values + ['updated_by' => $actor->id, 'updated_at' => $now]);
                $this->db->table('ucasser_resource_revisions')->insert([
                    'resource_id' => $existing->id, 'editor_id' => $actor->id,
                    'previous_data' => json_encode($existing, JSON_UNESCAPED_UNICODE),
                    'new_data' => json_encode($values + ['id' => (int) $existing->id, 'created_at' => $existing->created_at], JSON_UNESCAPED_UNICODE),
                    'reason' => '批量导入批次', 'created_at' => $now,
                ]);
            }, $results);
        }
        $new++;
        return $this->step($line, $externalKey, 'inserted', null, null, json_encode($values, JSON_UNESCAPED_UNICODE), function (User $actor) use ($values, $externalKey, $now) {
            $id = $this->db->table('ucasser_resources')->insertGetId($values + ['updated_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now]);
            $this->db->table('ucasser_resource_revisions')->insert([
                'resource_id' => $id, 'editor_id' => $actor->id, 'previous_data' => null,
                'new_data' => json_encode($values + ['id' => $id, 'created_at' => $now], JSON_UNESCAPED_UNICODE),
                'reason' => '批量导入批次', 'created_at' => $now,
            ]);
        }, $results);
    }

    public function rollback(int $batchId, User $actor): array
    {
        $batch = $this->db->table('campus_import_batches')->where('id', $batchId)->first();
        if (!$batch) throw new ValidationException(['batch' => '批次不存在']);
        if ($batch->status === 'rolled_back') throw new ValidationException(['batch' => '该批次已回滚']);
        $rows = $this->db->table('campus_import_batch_rows')->where('batch_id', $batchId)->get();
        $reverted = 0;
        $this->db->transaction(function () use ($batch, $rows, &$reverted) {
            foreach ($rows as $row) {
                if ($row->action === 'inserted' && $row->entity_id) {
                    $deleted = match ($batch->kind) {
                        'colleges' => $this->db->table('campus_colleges')->where('id', $row->entity_id)->delete(),
                        'courses' => $this->db->table('campus_courses')->where('id', $row->entity_id)->delete() && $this->db->table('campus_course_offerings')->where('course_id', $row->entity_id)->delete(),
                        'resources' => $this->db->table('ucasser_resources')->where('id', $row->entity_id)->delete(),
                    };
                    if ($deleted) $reverted++;
                } elseif ($row->action === 'updated' && $row->entity_id && $row->before) {
                    $before = json_decode($row->before, true, 512, JSON_THROW_ON_ERROR);
                    $updated = match ($batch->kind) {
                        'colleges' => $this->db->table('campus_colleges')->where('id', $row->entity_id)->update($before),
                        'courses' => $this->db->table('campus_courses')->where('id', $row->entity_id)->update($before) && $this->db->table('campus_course_offerings')->where('course_id', $row->entity_id)->update($before),
                        'resources' => $this->db->table('ucasser_resources')->where('id', $row->entity_id)->update($before),
                    };
                    if ($updated) $reverted++;
                }
            }
            $this->db->table('campus_import_batches')->where('id', $batch->id)->update(['status' => 'rolled_back']);
        });
        $this->identity->audit($actor, 'catalog.import.rollback', 'batch:'.$batchId, 'reverted '.$reverted.' rows');
        return ['data' => ['batch_id' => (int) $batchId, 'reverted' => $reverted]];
    }
}
