# Free Image to Video: PHP SDK

Free image to video AI with no daily cap: start with no sign-up, keep going on a free account. Every video comes with sound and no watermark. This is the official PHP SDK for [Free Image to Video](https://freeimagetovideo.ai/?utm_source=packagist&utm_medium=package): turn a prompt or a photo into a video clip from PHP.

```php
use FreeImageToVideo\FreeImageToVideo;

$fitv  = new FreeImageToVideo();   // reads FREEIMAGETOVIDEO_API_KEY
$clip  = $fitv->generateVideo('a paper lantern drifting over a night river');
$moved = $fitv->generateVideo('the camera slowly orbits the statue',
    imageUrl: 'https://example.com/statue.jpg',
    options: ['duration' => 6, 'aspect_ratio' => '9:16']);
```

Each call submits the run, waits for it and returns the URL of the finished clip. With `imageUrl` it is [image to video AI with sound](https://freeimagetovideo.ai/video/image-to-video-ai-with-sound): your photo is the first frame. Without it, it is [free text to video AI](https://freeimagetovideo.ai/video/free-text-to-video-ai) from the prompt alone.

## Install

```bash
composer require hanshs474/freeimagetovideo
```

PHP 8.1+ with `ext-curl` and `ext-json`. No other dependencies.

## API key and plans

Create a key at [freeimagetovideo.ai/settings/apikeys](https://freeimagetovideo.ai/settings/apikeys) and set `FREEIMAGETOVIDEO_API_KEY` (or pass it: `new FreeImageToVideo('sk-...')`). API runs need a paid plan: each run draws on the plan's full-speed allowance, then waits in the relaxed queue. See [pricing](https://freeimagetovideo.ai/pricing). To make clips without a key, use the generator on [freeimagetovideo.ai](https://freeimagetovideo.ai/?utm_source=packagist&utm_medium=package): start with no sign-up, keep going on a free account.

## Options

| option | values |
|---|---|
| `duration` | 4, 5, 6, 8, 10, 15 (seconds) |
| `resolution` | `480p` (default), `768p` |
| `aspect_ratio` | `16:9` (default), `9:16`, `1:1` |

Model: MiniMax H3 (`minimax-h3`). Every clip comes with sound.

## Lower level

```php
$id   = $fitv->submit('a koi pond in the rain');
$run  = $fitv->status($id);   // $run['status']: pending | processing | success | failed | canceled
$url  = $fitv->wait($id);     // polls every 10 s, gives up after 30 minutes
$left = $fitv->credits();
```

## Errors worth catching

All extend `FreeImageToVideo\FreeImageToVideoException`:

- `AuthError`: no key, or the key is wrong or deleted
- `QuotaError`: not enough credits for this run
- `PlanError`: API runs need a paid plan
- `BusyError`: too many runs generating at once
- `RejectedError`: the content filter refused the prompt, or a cooldown is active
- `GenerationError`: the run failed or was canceled; `$e->errorMessage` says why
- `TimeoutError`: still generating at the deadline; check the run later with `status()`
- `ApiError`: any other failed request

MIT licensed.
