<?php
require __DIR__.'/../vendor/autoload.php';
$path = tempnam(sys_get_temp_dir(), 'ucasser-collector-config-');
$previous = getenv('MP_RSS_URL');
$check = function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
try {
    putenv('MP_RSS_URL=http://environment-service:8001');
    $method = new ReflectionMethod(UCASSer\Campus\CollectorClient::class, 'base');
    file_put_contents($path, "USERNAME=fixture\nPASSWORD=fixture-only\nBASE_URL=http://container-service:8001\n");
    $client = new UCASSer\Campus\CollectorClient($path);
    $check($client->configured(), 'Complete server-side credentials must be recognized');
    $check($method->invoke($client) === 'http://container-service:8001/api/v1/wx/', 'Private connection file must override stale loopback environment');
    file_put_contents($path, "USERNAME=fixture\nPASSWORD=fixture-only\n");
    $check($method->invoke(new UCASSer\Campus\CollectorClient($path)) === 'http://environment-service:8001/api/v1/wx/', 'Existing environment configuration must keep working');
    file_put_contents($path, "USERNAME=fixture\nBASE_URL=http://must-not-use:8001\n");
    $client = new UCASSer\Campus\CollectorClient($path);
    $check(!$client->configured(), 'Partial credentials must not claim a configured collector');
    $check($method->invoke($client) === 'http://environment-service:8001/api/v1/wx/', 'Partial credentials must not override connection routing');
    echo "Collector private URL override, environment fallback and incomplete credential handling OK\n";
} finally {
    unlink($path);
    putenv($previous === false ? 'MP_RSS_URL' : 'MP_RSS_URL='.$previous);
}
