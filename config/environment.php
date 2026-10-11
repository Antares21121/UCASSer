<?php
// This project bridge is called from the ignored config.php AFTER installation.
// Keys match Flarum 2.x's generated configuration; .env is loaded by the launcher.
$required = static function (string $name): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException('Missing environment variable: '.$name);
    }
    return $value;
};
$url = $required('APP_URL');
$production = getenv('APP_ENV') === 'production';
if ($production && (parse_url($url, PHP_URL_SCHEME) !== 'https' || getenv('APP_DEBUG') !== 'false')) {
    throw new RuntimeException('Production requires HTTPS and APP_DEBUG=false');
}
if ($production) {
    require __DIR__.'/release.php';
}
// Flarum stores unzoned database timestamps in UTC. A local PHP timezone
// makes Eloquent reinterpret those values and shifts API dates by eight hours.
// TZ can still configure the host/container; browser display uses local time.
date_default_timezone_set('UTC');
return [
    'debug' => !$production && getenv('APP_DEBUG') === 'true',
    'database' => [
        'driver' => 'mariadb', // Flarum 2.x distinguishes MariaDB from MySQL.
        'host' => $required('DB_HOST'),
        'port' => (int) $required('DB_PORT'),
        'database' => $required('DB_NAME'),
        'username' => $required('DB_USER'),
        'password' => $required('DB_PASSWORD'),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => getenv('DB_PREFIX') ?: '',
        'prefix_indexes' => true,
        'strict' => false,
        'engine' => 'InnoDB',
    ],
    'url' => $url,
    'paths' => ['api' => 'api', 'admin' => 'admin'],
    'headers' => ['poweredByHeader' => false, 'referrerPolicy' => 'same-origin'],
    'queue' => ['driver' => 'sync'],
];
