<?php
// PHP built-in server: local development only, with the same public boundary.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (preg_match('~(?:^|/)\.|\.(?:php|phar)(?:/|$)~i', $path) || strpos($path, '\\') !== false) {
    if ($path !== '/index.php') {
        http_response_code(404);
        exit;
    }
}
$public = realpath(__DIR__.'/../public');
$file = realpath($public.$path);
if ($file && strncmp($file, $public.DIRECTORY_SEPARATOR, strlen($public) + 1) === 0 && is_file($file)) {
    if ($path !== '/index.php') {
        return false;
    }
}
chdir($public);
require $public.'/index.php';
