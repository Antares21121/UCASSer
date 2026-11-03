<?php
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'development') throw new RuntimeException('Development CLI only');
define('UCASSER_OPERATOR_PROVISION_LIBRARY', true);
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../scripts/provision_operator.php';

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use UCASSer\Campus\CollectorClient;
use UCASSer\Campus\CollectorController;
use UCASSer\Campus\OperatorAccess;
use UCASSer\Campus\TimetableController;

class QrFixtureClient extends CollectorClient {
    public int $created = 0;
    public int $polled = 0;
    public int $images = 0;
    public function __construct() {}
    public function state(): array { return ['state' => 'authorization_required', 'message' => 'Fixture']; }
    public function qrState(): array { $this->polled++; return $this->state(); }
    public function configured(): bool { return true; }
    public function qr(): array { return ['uid' => 'fixture_uid_'.++$this->created, 'path' => '/static/qr-'.$this->created.'.png']; }
    public function qrImage(string $path): string {
        $this->images++;
        if ($path !== '/static/qr-'.$this->created.'.png') throw new RuntimeException('QR path changed');
        return "\x89PNG\r\n\x1a\n".str_repeat('x', 120);
    }
}
$c = (require __DIR__.'/../site.php')->bootApp()->getContainer();
$db = $c->make(Illuminate\Database\ConnectionInterface::class);
$check = function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
$makeRequest = function ($actor, string $route, array $body = [], array $query = []) {
    $stream = new Stream('php://temp', 'r+'); $stream->write(json_encode($body)); $stream->rewind();
    return RequestUtil::withActor(new ServerRequest(), $actor)->withAttribute('routeName', $route)->withBody($stream)->withQueryParams($query);
};
$cache = $c->make(Illuminate\Contracts\Cache\Repository::class);
$active = $cache->get('campus.collector.active_qr');
$keys = [];
$db->beginTransaction();
try {
    $actors = [];
    for ($i = 0; $i < 2; $i++) {
        $name = 'qrtimetable_'.bin2hex(random_bytes(4));
        $p = ucasserProvisionOperator($c, ['username' => $name, 'email' => $name.'@example.invalid',
            'password' => bin2hex(random_bytes(16)), 'owner_email_confirmed' => true], true);
        $actors[] = Flarum\User\User::findOrFail($p['user_id']);
    }
    $client = new QrFixtureClient();
    $controller = new CollectorController($client, $c->make(OperatorAccess::class), $db);
    $read = fn ($route, $query = [], $actor = null) => $controller->handle($makeRequest($actor ?? $actors[0], 'campus.control.collector'.$route, [], $query));
    $qr = json_decode((string) $read('.qr')->getBody(), true)['data'];
    $keys[] = 'campus.collector.qr.'.hash('sha256', $actors[0]->id.':'.$qr['uid']);
    $check($qr['image'] === '/api/campus/control/collector/qr-image?v='.$qr['uid'], 'Browser must use private image relay');
    $image = $read('.qr-image', ['v' => $qr['uid']]);
    $check($image->getStatusCode() === 200 && $image->getHeaderLine('Content-Type') === 'image/png' && $client->created === 1, 'Image read generated another QR');
    $read('', ['qr' => $qr['uid']]);
    $check($client->polled === 1, 'Owned active QR did not advance handshake');
    $denied = false;
    try { $read('', ['qr' => $qr['uid']], $actors[1]); } catch (Flarum\Foundation\ValidationException $e) { $denied = true; }
    $check($denied && $client->polled === 1 && $read('.qr-image', ['v' => $qr['uid']], $actors[1])->getStatusCode() === 502, 'Another operator accessed a private QR');
    $replacement = json_decode((string) $read('.qr')->getBody(), true)['data'];
    $keys[] = 'campus.collector.qr.'.hash('sha256', $actors[0]->id.':'.$replacement['uid']);
    $check($read('.qr-image', ['v' => $qr['uid']])->getStatusCode() === 502, 'Superseded QR still accepted');
    $read(''); $check($client->polled === 1, 'Routine state check advanced a QR flow');

    $schedule = json_decode(file_get_contents(__DIR__.'/../extensions/campus/resources/data/timetable.json'), true);
    $entry = $schedule['entries'][0]['id'];
    $timetable = new TimetableController($db);
    $subscribe = fn ($actor, $id, $enabled) => $timetable->handle($makeRequest($actor, 'campus.timetable.subscribe', ['entry_id' => $id, 'enabled' => $enabled]));
    $ids = fn ($actor) => json_decode((string) $timetable->handle($makeRequest($actor, 'campus.timetable.subscriptions'))->getBody(), true)['data']['ids'];
    $subscribe($actors[0], $entry, true); $subscribe($actors[0], $entry, true);
    $check($ids($actors[0]) === [$entry] && $ids($actors[1]) === [], 'Subscriptions must persist once and stay private per user');
    $subscribe($actors[1], $entry, false); $check($ids($actors[0]) === [$entry], 'Another user removed a subscription');
    $denied = false; try { $subscribe($actors[0], '000000000000', true); } catch (Flarum\Foundation\ValidationException $e) { $denied = true; }
    $check($denied && $ids($actors[0]) === [$entry], 'Fabricated timetable entry was stored');
    $actors[1]->is_email_confirmed = false;
    $denied = false; try { $subscribe($actors[1], $entry, true); } catch (Flarum\User\Exception\PermissionDeniedException $e) { $denied = true; }
    $check($denied, 'Unconfirmed user wrote a subscription');
    $subscribe($actors[0], $entry, false); $check($ids($actors[0]) === [], 'Unsubscribe did not persist');
    echo "Operator QR creation/image continuity, owner isolation, replacement and timetable persistence/idempotence/account isolation OK\n";
} finally {
    $db->rollBack();
    foreach ($keys as $key) $cache->forget($key);
    if ($active === null) $cache->forget('campus.collector.active_qr'); else $cache->put('campus.collector.active_qr', $active, 300);
}
