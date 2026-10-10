<?php
// Phase 0 uses upstream 2.x RC for development. Production waits for stable 2.x.
$lock = json_decode(file_get_contents(__DIR__.'/../composer.lock'), true, 512, JSON_THROW_ON_ERROR);
foreach ($lock['packages'] ?? [] as $package) {
    if ($package['name'] === 'flarum/core') {
        $version = $package['version'];
        if (preg_match('/^v?2\.\d+\.\d+$/D', $version) !== 1) {
            throw new RuntimeException('Production blocked: Flarum 2.x stable release required; RC/beta/dev builds are development-only');
        }
        return $version;
    }
}
throw new RuntimeException('Production blocked: missing locked flarum/core version');
