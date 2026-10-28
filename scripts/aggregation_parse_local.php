<?php
/** Parse locally downloaded official pages with the forum's existing adapters. */
require __DIR__.'/../extensions/campus/src/UcassVsbAdapter.php';
require __DIR__.'/../extensions/campus/src/UcassNoticesAdapter.php';
require __DIR__.'/../extensions/campus/src/UcassEmploymentAdapter.php';
require __DIR__.'/../extensions/campus/src/OfficialSourceCatalog.php';

use UCASSer\Campus\OfficialSourceCatalog;
use UCASSer\Campus\UcassNoticesAdapter;
use UCASSer\Campus\UcassVsbAdapter;

try {
    $mode = $argv[1] ?? '';
    if ($mode === 'catalog' && count($argv) === 2) {
        $data = OfficialSourceCatalog::WEB;
    } else {
        $kind = $argv[2] ?? '';
        $adapter = match ($kind) {
            'ucass-vsb' => new UcassVsbAdapter(),
            'ucass-notices' => new UcassNoticesAdapter(),
            default => throw new RuntimeException('Unknown official-source adapter'),
        };
        $html = stream_get_contents(STDIN, 1024 * 1024 + 1);
        if ($html === false || strlen($html) > 1024 * 1024) throw new RuntimeException('Page exceeds 1 MB');
        $data = match ($mode) {
            'listing' => $adapter->listing($html, (string) ($argv[3] ?? ''), (int) ($argv[4] ?? 0)),
            'article' => $adapter->article($html),
            default => throw new RuntimeException('Unknown parser mode'),
        };
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
