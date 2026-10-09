<?php
try {
    $version = require __DIR__.'/../config/release.php';
    echo 'Production release check OK: '.$version.PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
