<?php
/** Restore the reviewed public catalog into an existing installation, never a whole development database. */

function ucasserRestoreCatalog($container, string $root, bool $apply): array
{
    $db = $container->make(Illuminate\Database\ConnectionInterface::class);
    $catalog = $container->make(UCASSer\Campus\Catalog::class);
    $manifest = [
        'colleges' => [18, '4bf1c16b5c4202a0d14b5114fb780b2cc5ab1c2fcff297098455b4a52e3906f7'],
        'courses' => [501, 'ff1e422b734f8426efa3e53269ae68074bcb653d6dff6cd657dde410b96d526e'],
    ];
    $data = [];
    foreach ($manifest as $kind => [$count, $hash]) {
        $path = $root.'/data/import/2026-autumn/'.$kind.'.csv';
        $bytes = file_get_contents($path);
        if ($bytes === false || hash('sha256', str_replace("\r\n", "\n", $bytes)) !== $hash) {
            throw new RuntimeException('Catalog source changed or missing: '.$kind);
        }
        $stream = fopen($path, 'rb');
        try {
            $header = fgetcsv($stream, 0, ',', '"', '');
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $rows = [];
            while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($cells === [null]) continue;
                if (count($cells) !== count($header)) throw new RuntimeException('Malformed catalog CSV');
                $row = array_combine($header, $cells);
                if ($kind === 'colleges') $row['position'] = (int) $row['position'];
                $rows[] = $row;
            }
        } finally {
            fclose($stream);
        }
        if (count($rows) !== $count) throw new RuntimeException('Unexpected row count: '.$kind);
        $data[$kind] = $rows;
    }
    // Use an existing accountable deployer for the audit; never create users,
    // copy passwords, or silently grant the separate operator role.
    $actor = Flarum\User\User::query()->where('is_email_confirmed', true)->get()->first(fn ($user) => $user->isAdmin());
    if (!$actor) throw new RuntimeException('No confirmed administrator available to attribute deployment audit');
    $before = [];
    foreach (['campus_colleges', 'campus_courses', 'campus_course_offerings', 'ucasser_resource_categories'] as $table) {
        $before[$table] = $db->table($table)->count();
    }
    $summary = ['apply' => $apply, 'actor' => $actor->username, 'before' => $before, 'added_colleges' => 0, 'added_courses' => 0];
    $import = function (string $kind, array $rows) use ($catalog, $actor): void {
        foreach (array_chunk($rows, 500) as $batch) {
            $preview = $catalog->import($actor, $kind, $batch, false);
            if ($preview['failed'] || $preview['conflicts']) throw new RuntimeException('Import validation failed: '.json_encode($preview, JSON_UNESCAPED_UNICODE));
            $result = $catalog->import($actor, $kind, $batch, true);
            if ($result['failed'] || $result['conflicts']) throw new RuntimeException('Import changed while applying');
        }
    };
    $db->beginTransaction();
    try {
        $missing = [];
        foreach ($data['colleges'] as $row) {
            $existing = $db->table('campus_colleges')->where('external_key', $row['external_key'])->orWhere('code', $row['code'])->orWhere('name', $row['name'])->first();
            if ($existing && $existing->name !== $row['name']) throw new RuntimeException('College code/name conflict: '.$row['code']);
            if (!$existing) $missing[] = $row;
        }
        $import('colleges', $missing);
        $summary['added_colleges'] = count($missing);
        $collegeIds = [];
        foreach ($data['colleges'] as $row) {
            $collegeIds[$row['code']] = (int) $db->table('campus_colleges')->where('name', $row['name'])->value('id');
        }
        $missing = [];
        $identities = [];
        foreach ($data['courses'] as $row) {
            $row['college_id'] = $collegeIds[$row['college_key']];
            unset($row['college_key']);
            $hash = UCASSer\Campus\Catalog::identityHash($row['college_id'], $row['code'], $row['semester']);
            $identities[] = $hash;
            $existing = $db->table('campus_course_offerings')->where('external_key', $row['external_key'])->orWhere('identity_hash', $hash)->first();
            if ($existing && ($existing->name !== $row['name'] || $existing->identity_hash !== $hash)) throw new RuntimeException('Course identity/name conflict: '.$row['external_key']);
            if (!$existing) $missing[] = $row;
        }
        $import('courses', $missing);
        $summary['added_courses'] = count($missing);
        $output = new Symfony\Component\Console\Output\BufferedOutput();
        $exit = $container->make(UCASSer\Campus\ResourcesSyncCommand::class)->run(new Symfony\Component\Console\Input\ArrayInput([]), $output);
        if ($exit !== 0) throw new RuntimeException($output->fetch());
        $linked = 0;
        foreach (array_chunk($identities, 400) as $chunk) {
            $linked += $db->table('campus_course_offerings')->whereIn('identity_hash', $chunk)->whereNotNull('resource_category_id')->count();
        }
        if ($linked !== 501) throw new RuntimeException('Incomplete course/resource links: '.$linked);
        $summary['public_courses_linked'] = $linked;
        $summary['after'] = [];
        foreach (array_keys($before) as $table) $summary['after'][$table] = $db->table($table)->count();
        if ($apply) $db->commit();
        else $db->rollBack();
        return $summary;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

if (!defined('UCASSER_CATALOG_RECOVERY_LIBRARY')) {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
    try {
        $args = array_slice($_SERVER['argv'] ?? [], 1);
        if (array_diff($args, ['--apply'])) throw new RuntimeException('Usage: php scripts/restore_catalog.php [--apply]');
        require getcwd().'/vendor/autoload.php';
        $container = (require getcwd().'/site.php')->bootApp()->getContainer();
        echo json_encode(ucasserRestoreCatalog($container, getcwd(), in_array('--apply', $args, true)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'Catalog recovery failed: '.$error->getMessage()."\n");
        exit(1);
    }
}
