<?php

declare(strict_types=1);

namespace FreeImageToVideo;

/**
 * Official PHP SDK for Free Image to Video (https://freeimagetovideo.ai).
 * Free image to video AI with no daily cap: start with no sign-up, keep going on a free account.
 * Every video comes with sound and no watermark.
 *
 *     $fitv  = new FreeImageToVideo\FreeImageToVideo();   // reads FREEIMAGETOVIDEO_API_KEY
 *     $clip  = $fitv->generateVideo('a paper lantern drifting over a night river');
 *     $moved = $fitv->generateVideo('the camera slowly orbits the statue',
 *         imageUrl: 'https://example.com/statue.jpg', options: ['duration' => 6]);
 *
 * Create a key at https://freeimagetovideo.ai/settings/apikeys. API runs need a paid plan.
 */
final class FreeImageToVideo
{
    public const VERSION = '0.1.0';
    public const BASE_URL = 'https://freeimagetovideo.ai';
    public const KEYS_URL = self::BASE_URL . '/settings/apikeys';
    /** The model the site runs: MiniMax H3. */
    public const DEFAULT_MODEL = 'minimax-h3';

    private string $apiKey;
    private string $baseUrl;
    /** @var callable(string,?array,string):array{0:int,1:string} */
    private $transport;

    /**
     * @param string|null $apiKey  defaults to FREEIMAGETOVIDEO_API_KEY
     * @param float $timeout  seconds generateVideo waits for a run (default 30 minutes; runs can queue)
     * @param float $pollEvery  seconds between status checks (default 10)
     * @param string|null $baseUrl  defaults to FREEIMAGETOVIDEO_BASE_URL, then https://freeimagetovideo.ai
     * @param callable|null $transport  fn(string $url, ?array $body, string $key): array{int, string}, for tests
     */
    public function __construct(
        ?string $apiKey = null,
        public float $timeout = 1800.0,
        public float $pollEvery = 10.0,
        ?string $baseUrl = null,
        ?callable $transport = null,
    ) {
        $this->apiKey = trim($apiKey ?? (getenv('FREEIMAGETOVIDEO_API_KEY') ?: ''));
        $this->baseUrl = rtrim($baseUrl ?? (getenv('FREEIMAGETOVIDEO_BASE_URL') ?: self::BASE_URL), '/');
        $this->transport = $transport ?? [self::class, 'http'];
    }

    /**
     * Generate one clip with sound and return its URL. With $imageUrl the photo is the first frame.
     *
     * @param array<string,mixed> $options duration (4/5/6/8/10/15), resolution (480p/768p), aspect_ratio (16:9/9:16/1:1)
     */
    public function generateVideo(string $prompt, ?string $imageUrl = null, array $options = []): string
    {
        return $this->wait($this->submit($prompt, $imageUrl, $options));
    }

    /** The request body for POST /api/ai/generate. */
    public static function body(string $prompt, ?string $imageUrl = null, array $options = []): array
    {
        $options['sound'] = true;
        $scene = 'text-to-video';
        if ($imageUrl !== null && $imageUrl !== '') {
            $options['image_input'] = [$imageUrl];
            $scene = 'image-to-video';
        }
        return ['provider' => 'auto', 'mediaType' => 'video', 'model' => self::DEFAULT_MODEL,
            'scene' => $scene, 'prompt' => $prompt, 'options' => $options];
    }

    /** Start a run without waiting and return its task id. */
    public function submit(string $prompt, ?string $imageUrl = null, array $options = []): string
    {
        if (trim($prompt) === '') {
            throw new ApiError('prompt is required');
        }
        $data = $this->post('/api/ai/generate', self::body($prompt, $imageUrl, $options));
        $id = is_array($data) ? ($data['id'] ?? null) : null;
        if ($id === null || $id === '') {
            throw new ApiError('freeimagetovideo.ai accepted the run but returned no id');
        }
        return (string) $id;
    }

    /**
     * Look up a run: ['id', 'status' => pending|processing|success|failed|canceled, 'videos', 'errorMessage', 'failureKind', 'queued', 'queueEta'].
     *
     * @return array<string,mixed>
     */
    public function status(string $taskId): array
    {
        $data = $this->post('/api/ai/query', ['taskId' => $taskId]);
        if (!is_array($data)) {
            throw new ApiError('unexpected status answer');
        }
        return $data;
    }

    /** Credits left on the account. */
    public function credits(): int
    {
        $data = $this->post('/api/user/get-user-credits', null);
        return (int) (is_array($data) ? ($data['remainingCredits'] ?? 0) : 0);
    }

    /** Poll a submitted run until it finishes and return its video URL. */
    public function wait(string $taskId): string
    {
        $deadline = microtime(true) + $this->timeout;
        while (true) {
            $t = $this->status($taskId);
            $s = $t['status'] ?? '';
            if ($s === 'success') {
                $v = $t['videos'] ?? [];
                if (!is_array($v) || !isset($v[0])) {
                    throw new GenerationError("run $taskId: finished with no video", taskId: $taskId, errorMessage: 'finished with no video');
                }
                return (string) $v[0];
            }
            if ($s === 'failed' || $s === 'canceled') {
                $em = isset($t['errorMessage']) ? (string) $t['errorMessage'] : null;
                throw new GenerationError("generation failed: run $taskId: " . ($em ?: $s), taskId: $taskId,
                    errorMessage: $em, failureKind: isset($t['failureKind']) ? (string) $t['failureKind'] : null);
            }
            if (microtime(true) + $this->pollEvery > $deadline) {
                throw new TimeoutError("still generating; check run $taskId later", taskId: $taskId);
            }
            usleep((int) ($this->pollEvery * 1_000_000));
        }
    }

    /**
     * Read one answer: the envelope's data on code 0, else throw the classified error.
     * Refusals usually arrive as HTTP 200 with a non-zero code, so the status alone reads a refusal as success.
     */
    public static function parseEnvelope(int $status, string $raw): mixed
    {
        $env = [];
        if (trim($raw) !== '') {
            $env = json_decode($raw, true);
            if (!is_array($env)) {
                if ($status === 401) {
                    throw FreeImageToVideoException::classify(401, []);
                }
                throw new ApiError("freeimagetovideo.ai returned a non-JSON response (HTTP $status)", $status);
            }
        }
        if ($status < 400 && ($env['code'] ?? null) === 0) {
            return $env['data'] ?? null;
        }
        throw FreeImageToVideoException::classify($status, $env);
    }

    private function post(string $path, ?array $body): mixed
    {
        if ($this->apiKey === '') {
            throw new AuthError('set FREEIMAGETOVIDEO_API_KEY (create a key at ' . self::KEYS_URL . ')');
        }
        [$status, $raw] = ($this->transport)($this->baseUrl . $path, $body, $this->apiKey);
        return self::parseEnvelope($status, $raw);
    }

    /** @return array{0:int,1:string} */
    public static function http(string $url, ?array $body, string $key): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key, 'User-Agent: fitv-php/' . self::VERSION],
            CURLOPT_POSTFIELDS => $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new ApiError('could not reach freeimagetovideo.ai: ' . ($err ?: 'request failed'));
        }
        return [$status, (string) $raw];
    }
}
