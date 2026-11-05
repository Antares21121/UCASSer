<?php
require __DIR__.'/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use UCASSer\Campus\CollectorClient;

$check = function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$settings = tempnam(sys_get_temp_dir(), 'collector-workflow-');
file_put_contents($settings, "USERNAME=fixture\nPASSWORD=fixture-only\nBASE_URL=http://collector-fixture:8001\n");
$json = fn (array $data, int $status = 200) => new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
$ok = fn (array $data = []) => $json(['code' => 0, 'data' => $data]);
$login = fn () => $ok(['access_token' => 'fixture-only']);
$make = function (array $responses, array &$history) use ($settings): CollectorClient {
    $history = []; $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    return new CollectorClient($settings, new Client(['handler' => $stack]));
};
$fails = function (callable $call) use ($check): void {
    $failed = false;
    try { $call(); } catch (Throwable $e) { $failed = true; $check(!str_contains($e->getMessage(), 'PRIVATE-MARKER'), 'Private upstream text escaped'); }
    $check($failed, 'Expected failure was silently accepted');
};
try {
    $history = [];
    $client = $make([$login(), $ok(['mp_configured' => true, 'cookie' => 'PRIVATE-MARKER']), $ok(['mp_id' => CollectorClient::AUTH_PROBE_MP_ID])], $history);
    $check($client->state()['state'] === 'ready' && count($history) === 3, 'Saved auth must survive absent QR singleton state');
    foreach ($history as $request) $check(!str_contains($request['request']->getUri()->getPath(), '/qr/'), 'State check requested a QR');
    $check(str_ends_with($history[2]['request']->getUri()->getPath(), '/weread/mp/test')
        && json_decode((string) $history[2]['request']->getBody(), true) === ['mp_id' => CollectorClient::AUTH_PROBE_MP_ID],
        'MP authorization must not depend on the unrelated bookshelf API');
    foreach ([[], ['ok' => true], ['mp_id' => 'MP_WXS_999']] as $invalid) {
        $client = $make([$login(), $ok(['mp_configured' => true]), $ok($invalid)], $history);
        $check($client->state()['state'] === 'unavailable', 'Missing or wrong publisher identity must not validate authorization');
    }
    $client = $make([$login(), $ok(['mp_configured' => false])], $history);
    $check($client->state()['state'] === 'authorization_required', 'Missing saved authorization');
    $client = $make([$login(), $ok(['mp_configured' => true]), $json(['code' => 400, 'data' => ['ok' => false, 'error' => 'PRIVATE-MARKER']], 400)], $history);
    $result = $client->state();
    $check($result['state'] === 'unavailable' && !str_contains(json_encode($result), 'PRIVATE-MARKER'), 'Network ambiguity must not force QR or leak details');
    $client = $make([$login(), $ok(['mp_configured' => true]), $json(['code' => 400, 'data' => ['code' => -2012]], 400)], $history);
    $check($client->state()['state'] === 'authorization_required', 'Explicit expiration code must be surfaced');
    foreach ([401, 302, 500] as $status) {
        $client = $make([$json(['code' => 0, 'message' => 'PRIVATE-MARKER'], $status)], $history);
        $check($client->state()['state'] === 'unavailable' && count($history) === 1, 'Bad HTTP status must not be accepted or redirected');
    }
    $client = $make([$login(), $json(['code' => 500, 'message' => 'PRIVATE-MARKER'])], $history);
    $fails(fn () => $client->refreshFeed('MP_WXS_3518809936', '中国社会科学院大学'));

    $client = $make([$login(), $ok(['code' => '/static/weread-fixture.png', 'uid' => 'fixture_uid_123'])], $history);
    $qr = $client->qr(); $check(count($history) === 2 && $qr['path'] === '/static/weread-fixture.png', 'QR metadata must be validated');
    $client = $make([$login(), $ok(['login_status' => false])], $history);
    $check($client->qrState()['state'] === 'authorization_required' && count($history) === 2, 'Unconfirmed QR must remain waiting');
    $client = $make([$login(), $ok(['login_status' => true, 'cookie' => 'PRIVATE-MARKER']), $login(), $ok(['mp_configured' => true]), $ok(['mp_id' => CollectorClient::AUTH_PROBE_MP_ID])], $history);
    $result = $client->qrState();
    $check($result['state'] === 'ready' && !str_contains(json_encode($result), 'PRIVATE-MARKER'), 'QR completion must verify saved auth without exposing session data');
    $png = "\x89PNG\r\n\x1a\n".str_repeat('x', 120);
    $client = $make([new Response(200, ['Content-Type' => 'image/png'], $png)], $history);
    $check($client->qrImage($qr['path']) === $png && count($history) === 1, 'Image read must not replace a QR');
    foreach (['https://attacker.invalid/static/a.png', '//attacker.invalid/static/a.png', '/static/../config.png', '/static/a.png?token=fixture', 'file:///static/a.png'] as $path) {
        $client = $make([], $history); $fails(fn () => $client->qrImage($path));
        $check(count($history) === 0, 'Untrusted QR path caused network access');
    }
    $client = $make([$login(), $ok(['list' => [
        ['id' => 'MP_WXS_1', 'status' => 1], ['id' => 'MP_WXS_2', 'status' => 0], ['id' => 'FEATURED', 'status' => 1],
    ]])], $history);
    $check(array_column($client->feeds(), 'id') === ['MP_WXS_1'], 'Paused and virtual feeds must not be collected');

    $name = '中国社会科学院大学'; $id = 'MP_WXS_3518809936';
    $url = 'https://mp.weixin.qq.com/s?__biz='.rawurlencode(base64_encode('3518809936')).'&mid=fixture';
    $client = $make([$login(), $ok(['mp_id' => $id, 'mp_name' => 'Wrong publisher'])], $history);
    $fails(fn () => $client->subscribe($name, $url));
    $check(count($history) === 2, 'Publisher mismatch wrote a subscription');
    $client = $make([$login(), $ok(['mp_id' => $id, 'mp_name' => $name]), $ok(['list' => []]), $ok()], $history);
    $check($client->subscribe($name, $url)['mp_id'] === $id && count($history) === 4, 'Percent-encoded canonical link must resolve and verify before writing');
    $client = $make([$login(), $ok(['mp_info' => ['biz' => base64_encode('3518809936')]]), $ok(['mp_id' => $id, 'mp_name' => $name]), $ok(['list' => [['id' => $id, 'mp_name' => $name, 'status' => 0]]])], $history);
    $client->subscribe($name, 'https://mp.weixin.qq.com/s/fixture~article');
    $check(count($history) === 4 && str_contains((string) $history[1]['request']->getUri(), '/mps/by_article?'), 'Short-link resolver must preserve an existing paused feed without featured-feed creation');
    $check(CollectorClient::publisherMatches('MP_WXS_3894823465', '中国社科大国际政治经济学院', '中国社科大全球与区域国别学院'), 'Reviewed rename missing');
    $check(!CollectorClient::publisherMatches('MP_WXS_1', '中国社科大国际政治经济学院', '中国社科大全球与区域国别学院'), 'Rename allowed for wrong identity');
    $check(CollectorClient::sourceName('MP_WXS_3894823465', '中国社科大全球与区域国别学院') === '中国社科大国际政治经济学院', 'Reviewed rename must retain the historical source identity');
    $client = $make([$login(), $ok(['list' => [['id' => 'MP_WXS_3894823465', 'mp_name' => '中国社科大全球与区域国别学院']]])], $history);
    $check($client->subscribed() === ['中国社科大国际政治经济学院'], 'Renamed subscribed publisher appears unsubscribed');
    $rows = CollectorClient::parseFeedRows('<rss><channel><item><title>Fixture article</title><link>https://mp.weixin.qq.com/s/fixture~article</link><pubDate>Mon, 12 Oct 2026 09:00:00 +0800</pubDate></item></channel></rss>');
    $check($rows[0]['published_at'] === '2026-10-12 01:00:00', 'PHP RSS time must agree with the Python import path in UTC');
    $fails(fn () => CollectorClient::parseFeedRows('<html>Not an RSS response</html>'));
    echo "Collector saved auth, safe error states, QR continuity/origin, publisher identity, paused feeds, API failures and UTC import OK\n";
} finally { unlink($settings); }
