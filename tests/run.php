<?php
// php tests/run.php [live]: no framework needed. Response bodies are the service's real envelope shapes.
spl_autoload_register(function (string $c): void {
    if (str_starts_with($c, 'FreeImageToVideo\\')) {
        require __DIR__ . '/../src/' . substr($c, 17) . '.php';
    }
});

use FreeImageToVideo\FreeImageToVideo;

function check(bool $ok, string $what): void { if (!$ok) { fwrite(STDERR, "FAIL $what\n"); exit(1); } echo "ok  $what\n"; }

function fake(array &$calls, array $polls): FreeImageToVideo
{
    $i = 0;
    return new FreeImageToVideo('sk-test', pollEvery: 0, baseUrl: 'https://t', transport: function (string $url, ?array $body, string $key) use (&$calls, &$i, $polls): array {
        check($key === 'sk-test', 'bearer key');
        $calls[] = [$url, $body];
        if (str_ends_with($url, '/api/ai/generate')) return [200, '{"code":0,"message":"ok","data":{"id":"t1","status":"pending"}}'];
        if (str_ends_with($url, '/api/user/get-user-credits')) return [200, '{"code":0,"message":"ok","data":{"remainingCredits":42}}'];
        $p = $polls[min($i++, count($polls) - 1)];
        return [200, '{"code":0,"message":"ok","data":' . $p . '}'];
    });
}

$calls = [];
$c = fake($calls, ['{"id":"t1","status":"processing"}', '{"id":"t1","status":"success","videos":["https://cdn/x.mp4"]}']);
check($c->generateVideo('a fox', options: ['duration' => 6]) === 'https://cdn/x.mp4', 'video url');
check($calls[0][0] === 'https://t/api/ai/generate' && $calls[0][1] === ['provider' => 'auto', 'mediaType' => 'video', 'model' => 'minimax-h3',
    'scene' => 'text-to-video', 'prompt' => 'a fox', 'options' => ['duration' => 6, 'sound' => true]], 'generate body');
check($calls[1][0] === 'https://t/api/ai/query' && $calls[1][1] === ['taskId' => 't1'], 'query body');
check($c->credits() === 42 && end($calls)[1] === null, 'credits, empty body');

$calls = [];
fake($calls, ['{"id":"t1","status":"success","videos":["https://cdn/y.mp4"]}'])->generateVideo('orbit', 'https://e.com/a.jpg');
check($calls[0][1]['scene'] === 'image-to-video' && $calls[0][1]['options']['image_input'] === ['https://e.com/a.jpg'], 'image to video body');

try { fake($calls, ['{"id":"t1","status":"failed","errorMessage":"provider down","failureKind":"upstream"}'])->generateVideo('x'); check(false, 'failed must throw'); }
catch (\FreeImageToVideo\GenerationError $e) { check($e->errorMessage === 'provider down' && $e->failureKind === 'upstream' && $e->taskId === 't1', 'failed run'); }
try { fake($calls, ['{"id":"t1","status":"canceled"}'])->generateVideo('x'); check(false, 'canceled must throw'); }
catch (\FreeImageToVideo\GenerationError $e) { check(true, 'canceled run'); }
try { $s = fake($calls, ['{"id":"t1","status":"processing","queued":true,"queueEta":60}']); $s->timeout = 0; $s->generateVideo('x'); check(false, 'timeout must throw'); }
catch (\FreeImageToVideo\TimeoutError $e) { check($e->taskId === 't1', 'timeout'); }

foreach ([
    [401, '{"code":-1,"message":"Invalid API key. Create one at https://freeimagetovideo.ai/settings/apikeys","data":{"reason":"invalid_api_key"}}', 'AuthError'],
    [401, '<html>unauthorized</html>', 'AuthError'],
    [200, '{"code":-1,"message":"insufficient credits","data":{"reason":"insufficient_credits"}}', 'QuotaError'],
    [200, '{"code":-1,"message":"This model or quality needs a plan.","data":{"reason":"plan_required"}}', 'PlanError'],
    [200, '{"code":-1,"message":"busy","data":{"reason":"concurrency"}}', 'BusyError'],
    [200, '{"code":-2,"message":"blocked"}', 'RejectedError'],
    [200, '{"code":-3,"message":"cooldown"}', 'RejectedError'],
    [200, '{"code":-1,"message":"no auth, please sign in"}', 'AuthError'],
    [200, '{"code":-1,"message":"generation failed, please try again"}', 'ApiError'],
    [502, '<html>bad gateway</html>', 'ApiError'],
] as [$st, $raw, $want]) {
    try { FreeImageToVideo::parseEnvelope($st, $raw); check(false, "no error for $raw"); }
    catch (\FreeImageToVideo\FreeImageToVideoException $e) { check(get_class($e) === "FreeImageToVideo\\$want", "classify $want " . substr($raw, 0, 40)); }
}

if (!getenv('FREEIMAGETOVIDEO_API_KEY')) {
    try { (new FreeImageToVideo())->credits(); check(false, 'missing key must throw'); }
    catch (\FreeImageToVideo\AuthError $e) { check(true, 'missing key -> AuthError'); }
}

if (($argv[1] ?? '') === 'live') {
    try { (new FreeImageToVideo('sk-wrong-key-for-test'))->submit('test'); check(false, 'wrong key accepted'); }
    catch (\FreeImageToVideo\AuthError $e) { check($e->httpStatus === 401, 'live: wrong key -> AuthError (HTTP 401)'); }
}
echo "all passed\n";
