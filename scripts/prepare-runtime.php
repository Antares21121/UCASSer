<?php

// Prepare directories as the installer, before the web process reads packages.
// The locked Flarum 2 RC bundles register jsDirectory() for frontends even when
// a frontend has no chunks. Composer archives omit empty directories, while
// Flysystem tries to create missing source roots during asset compilation.
$root = dirname(__DIR__);
$directories = ['public/assets/avatars'];
foreach (['cache', 'formatter', 'less', 'locale', 'logs', 'sessions', 'tmp', 'views'] as $name) {
    $directories[] = 'storage/'.$name;
}
foreach ($directories as $relative) {
    $path = $root.'/'.$relative;
    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot prepare runtime directory: '.$relative);
    }
}
foreach (['vendor/*/*/js/dist', 'extensions/*/js/dist'] as $pattern) {
    foreach (glob($root.'/'.$pattern, GLOB_ONLYDIR) ?: [] as $dist) {
        foreach (['common', 'forum', 'admin'] as $frontend) {
            $path = $dist.'/'.$frontend;
            if (!is_dir($path) && !mkdir($path, 0755) && !is_dir($path)) {
                throw new RuntimeException('Cannot prepare frontend source directory');
            }
        }
    }
}
echo "Runtime and frontend source directories ready\n";
