<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Refatbd\LaravelChatGptMirror\Services\Convert\ConversionFacade;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes all Mirror file-conversion & codec utilities under `/api/convert/*`
 * supporting both JSON (`dataBase64` / `parts`) and binary (`raw_in` / `raw_out` / `raw`) I/O.
 */
class ConversionController extends Controller
{
    /** @var string[] */
    private const FORTUNES = [
        'You will decode a byte you were not looking for.',
        'A GIF you generate today will outlive every chat that made it.',
        'Somewhere, a base64 string is dreaming of being an image.',
        'The bug is not in your code. It is in the format spec you invented.',
        'Trust the checksum. The checksum has seen things.',
        'Every pixel you emit is a promise to some future decoder.',
        'Today is lucky palette seed: it is whatever you pass in.',
        'A tar archive, once gzipped, forgets it was ever readable by eye.',
    ];

    public function __construct(
        protected ConversionFacade $codecs
    ) {}

    public function formats(): Response
    {
        return response()->json([
            'formats' => collect(ConversionFacade::FORMATS)->map(fn (string $id) => array_merge(
                ['id' => $id],
                ConversionFacade::FORMAT_INFO[$id]
            ))->all(),
        ])->header('Cache-Control', 'no-store');
    }

    public function loafEncode(Request $request): Response
    {
        [$rawIn, $rawOut] = $this->ioFlags($request);
        try {
            if ($rawIn) {
                $name = trim((string) ($request->query('name') ?: 'payload.bin'));
                $mtimeStr = $request->query('mtime');
                $mtime = is_string($mtimeStr) && $mtimeStr !== '' ? strtotime($mtimeStr) : time();
                $entries = [['name' => $name, 'content' => $request->getContent(), 'mtime' => $mtime ?: time()]];
            } else {
                $rawEntries = $request->input('entries');
                if (!is_array($rawEntries) || $rawEntries === []) {
                    return response()->json(['error' => 'entries array is required'], 400);
                }
                $entries = [];
                foreach ($rawEntries as $e) {
                    if (!is_array($e) || empty($e['name'])) {
                        return response()->json(['error' => 'Each entry must include name'], 400);
                    }
                    $item = ['name' => (string) $e['name']];
                    if (isset($e['linkTarget']) && is_string($e['linkTarget'])) {
                        $item['linkTarget'] = $e['linkTarget'];
                    } elseif (isset($e['contentBase64']) && is_string($e['contentBase64'])) {
                        $item['content'] = $this->decodeB64($e['contentBase64']);
                    }
                    if (!empty($e['mtime']) && is_string($e['mtime'])) {
                        $item['mtime'] = strtotime($e['mtime']) ?: time();
                    }
                    $entries[] = $item;
                }
            }

            $loaf = $this->codecs->loaf->makeLoaf($entries);
            return $this->sendArtifact($rawOut, 'loaf', $loaf);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function loafDecode(Request $request): Response
    {
        [$rawIn] = $this->ioFlags($request);
        try {
            $loaf = $rawIn ? $request->getContent() : (string) $request->input('loaf', '');
            $verify = $rawIn
                ? filter_var($request->query('verify', 'false'), FILTER_VALIDATE_BOOLEAN)
                : (bool) $request->input('verify', false);

            $entries = $this->codecs->loaf->extractLoaf($loaf, $verify);
            $mapped = array_map(fn (array $e) => [
                'name' => $e['name'],
                'isDirectory' => $e['isDirectory'],
                'isSymlink' => $e['isSymlink'],
                'linkTarget' => $e['linkTarget'],
                'bytes' => strlen($e['content']),
                'contentBase64' => base64_encode($e['content']),
            ], $entries);

            return response()->json(['entries' => $mapped])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function loafVerify(Request $request): Response
    {
        [$rawIn] = $this->ioFlags($request);
        try {
            $loaf = $rawIn ? $request->getContent() : (string) $request->input('loaf', '');
            return response()->json($this->codecs->loaf->verifyLoaf($loaf))->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function pngspeakEncode(Request $request): Response
    {
        [$rawIn, $rawOut] = $this->ioFlags($request);
        try {
            $data = $rawIn ? $request->getContent() : $this->decodeB64((string) $request->input('dataBase64', ''));
            $src = $rawIn ? $request->query() : $request->all();
            $opts = [
                'width' => isset($src['width']) ? (int) $src['width'] : null,
                'height' => isset($src['height']) ? (int) $src['height'] : null,
                'length' => isset($src['length']) ? (int) $src['length'] : null,
                'rand' => isset($src['rand']) ? (string) $src['rand'] : null,
                'upscaleWidth' => isset($src['upscaleWidth']) ? (int) $src['upscaleWidth'] : null,
                'upscaleHeight' => isset($src['upscaleHeight']) ? (int) $src['upscaleHeight'] : null,
            ];

            $png = $this->codecs->pngspeak->encode($data, $opts);
            return $this->sendArtifact($rawOut, 'pngspeak', $png);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function pngspeakDecode(Request $request): Response
    {
        [$rawIn] = $this->ioFlags($request);
        try {
            $png = $rawIn ? $request->getContent() : $this->decodeB64((string) $request->input('dataBase64', ''));
            $src = $rawIn ? $request->query() : $request->all();
            $decoded = $this->codecs->pngspeak->decode($png, [
                'length' => isset($src['length']) ? (int) $src['length'] : null,
                'rand' => isset($src['rand']) ? (string) $src['rand'] : null,
            ]);

            return response()->json([
                'bytes' => strlen($decoded),
                'dataBase64' => base64_encode($decoded),
            ])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function gptgifEncode(Request $request): Response
    {
        [$rawIn, $rawOut] = $this->ioFlags($request);
        try {
            $inputs = $rawIn ? [$request->getContent()] : $this->bodyToInputs($request);
            $gif = $this->codecs->gptgif->encode($inputs);
            return $this->sendArtifact($rawOut, 'gptgif', $gif);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function gptgifDecode(Request $request): Response
    {
        [$rawIn] = $this->ioFlags($request);
        try {
            $gif = $rawIn ? $request->getContent() : $this->decodeB64((string) $request->input('dataBase64', ''));
            $clusterMap = (string) (($rawIn ? $request->query('clusterMap') : $request->input('clusterMap')) ?: '0123456789abcdef');
            $decoded = $this->codecs->gptgif->gunzipOutput($this->codecs->gptgif->decode($gif, $clusterMap));

            return response()->json([
                'bytes' => strlen($decoded),
                'dataBase64' => base64_encode($decoded),
            ])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function gptgifCalibrate(Request $request): Response
    {
        [$rawIn] = $this->ioFlags($request);
        try {
            $gif = $rawIn ? $request->getContent() : $this->decodeB64((string) $request->input('dataBase64', ''));
            $clusterMap = (string) (($rawIn ? $request->query('clusterMap') : $request->input('clusterMap')) ?: '0123456789abcdef');
            return response()->json([
                'clusterMap' => $this->codecs->gptgif->calibrate($gif, $clusterMap),
            ])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function gptgifFontPreview(): Response
    {
        return response($this->codecs->gptgif->fontPreview(), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function gptgifV4Encode(Request $request): Response
    {
        [$rawIn, $rawOut] = $this->ioFlags($request);
        try {
            $inputs = $rawIn ? [$request->getContent()] : $this->bodyToInputs($request);
            $src = $rawIn ? $request->query() : $request->all();

            $font = null;
            if (isset($src['fontBase64'])) {
                $font = $this->codecs->gptgifV4->fontFromBytes($this->decodeB64((string) $src['fontBase64']));
            } elseif (isset($src['fontSeed'])) {
                $font = $this->codecs->gptgifV4->randomFont((int) $src['fontSeed']);
            }

            $palette = null;
            if (isset($src['paletteBase64'])) {
                $palette = $this->codecs->gptgifV4->paletteFromBytes($this->decodeB64((string) $src['paletteBase64']));
            } elseif (isset($src['paletteSeed'])) {
                $palette = $this->codecs->gptgifV4->randomPalette((int) $src['paletteSeed']);
            }

            $gif = $this->codecs->gptgifV4->encode($inputs, [
                'font' => $font,
                'palette' => $palette,
            ]);

            return $this->sendArtifact($rawOut, 'gptgif-v4', $gif, [
                'fontSeed' => isset($src['fontSeed']) ? (int) $src['fontSeed'] : null,
                'paletteSeed' => isset($src['paletteSeed']) ? (int) $src['paletteSeed'] : null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function gptgifV4Decode(Request $request): Response
    {
        [$rawIn] = $this->ioFlags($request);
        try {
            $gif = $rawIn ? $request->getContent() : $this->decodeB64((string) $request->input('dataBase64', ''));
            $decoded = $this->codecs->gptgifV4->decode($gif);
            return response()->json([
                'bytes' => strlen($decoded),
                'dataBase64' => base64_encode($decoded),
            ])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function gptgifV4RandomStyle(Request $request): Response
    {
        $fontSeed = $request->query('fontSeed') !== null ? (int) $request->query('fontSeed') : random_int(1, 0x7fffffff);
        $paletteSeed = $request->query('paletteSeed') !== null ? (int) $request->query('paletteSeed') : random_int(1, 0x7fffffff);
        $font = $this->codecs->gptgifV4->randomFont($fontSeed);
        $palette = $this->codecs->gptgifV4->randomPalette($paletteSeed);

        return response()->json([
            'fontSeed' => $fontSeed,
            'paletteSeed' => $paletteSeed,
            'font' => $font,
            'palette' => $palette,
            'fontBase64' => base64_encode($this->codecs->gptgifV4->fontToBytes($font)),
            'paletteBase64' => base64_encode($this->codecs->gptgifV4->paletteToBytes($palette)),
        ])->header('Cache-Control', 'no-store');
    }

    public function gptgifV4DefaultStyle(): Response
    {
        $font = \Refatbd\LaravelChatGptMirror\Services\Convert\GptGifCodec::FONT;
        $palette = $this->codecs->gptgifV4->defaultPalette();

        return response()->json([
            'font' => $font,
            'palette' => $palette,
            'fontBase64' => base64_encode($this->codecs->gptgifV4->fontToBytes($font)),
            'paletteBase64' => base64_encode($this->codecs->gptgifV4->paletteToBytes($palette)),
        ])->header('Cache-Control', 'no-store');
    }

    public function gptgifV4ValidateFont(Request $request): Response
    {
        try {
            $font = $this->codecs->gptgifV4->fontFromBytes($this->decodeB64((string) $request->input('fontBase64', '')));
            $this->codecs->gptgifV4->validateFont($font);
            return response()->json(['valid' => true])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['valid' => false, 'error' => $e->getMessage()])->header('Cache-Control', 'no-store');
        }
    }

    public function gptgifV4ValidatePalette(Request $request): Response
    {
        try {
            $palette = $this->codecs->gptgifV4->paletteFromBytes($this->decodeB64((string) $request->input('paletteBase64', '')));
            $this->codecs->gptgifV4->validatePalette($palette);
            return response()->json(['valid' => true])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['valid' => false, 'error' => $e->getMessage()])->header('Cache-Control', 'no-store');
        }
    }

    public function gif89aEncode(Request $request): Response
    {
        [$rawIn, $rawOut] = $this->ioFlags($request);
        try {
            if ($rawIn) {
                $width = (int) $request->query('width', 0);
                $height = (int) $request->query('height', 0);
                if ($width < 1 || $height < 1) {
                    return response()->json(['error' => 'width and height query params are required in raw_in mode'], 400);
                }
                $pixelsStr = $request->getContent();
                if (strlen($pixelsStr) !== $width * $height) {
                    return response()->json(['error' => "Expected {$width}*{$height} raw pixel bytes"], 400);
                }
                $colorsCsv = $request->query('colors');
                $colors = is_string($colorsCsv) && $colorsCsv !== ''
                    ? array_map(fn ($hex) => [
                        'r' => (int) hexdec(substr($hex, 0, 2)),
                        'g' => (int) hexdec(substr($hex, 2, 2)),
                        'b' => (int) hexdec(substr($hex, 4, 2)),
                    ], explode(',', $colorsCsv))
                    : array_map(fn ($i) => ['r' => $i, 'g' => $i, 'b' => $i], range(0, 255));
                $frames = [['pixels' => $pixelsStr, 'delayCs' => (int) $request->query('delayCs', 0)]];
            } else {
                $width = (int) $request->input('width', 0);
                $height = (int) $request->input('height', 0);
                $colors = $request->input('colors');
                $frames = $request->input('frames');
                if ($width < 1 || $height < 1 || !is_array($colors) || !is_array($frames)) {
                    return response()->json(['error' => 'width, height, colors, and frames are required'], 400);
                }
            }

            $gif = $this->codecs->gif89a->writeGif($width, $height, $colors, $frames);
            if ($rawOut) {
                return response($gif, 200, [
                    'Content-Type' => 'image/gif',
                    'Content-Disposition' => 'attachment; filename="mirror.gif"',
                    'Cache-Control' => 'no-store',
                ]);
            }
            return response()->json([
                'bytes' => strlen($gif),
                'dataBase64' => base64_encode($gif),
            ])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function gif89aInspect(Request $request): Response
    {
        [$rawIn] = $this->ioFlags($request);
        try {
            $data = $rawIn ? $request->getContent() : $this->decodeB64((string) $request->input('dataBase64', ''));
            $gif = $this->codecs->gif89a->readGif($data);
            return response()->json([
                'width' => $gif['width'],
                'height' => $gif['height'],
                'colors' => $gif['globalColorTable'],
                'frameCount' => count($gif['frames']),
                'frames' => array_map(fn (array $f) => [
                    'delayCs' => $f['delayCs'],
                    'pixelCount' => count($f['pixels']),
                ], $gif['frames']),
            ])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function roundtrip(Request $request): Response
    {
        [$rawIn] = $this->ioFlags($request);
        try {
            $original = $rawIn ? $request->getContent() : $this->decodeB64((string) $request->input('dataBase64', ''));
            $format = (string) ($rawIn ? $request->query('format') : $request->input('format', 'loaf'));
            $artifact = $this->codecs->encode($format, $original);
            $recovered = $this->codecs->decode($format, $artifact);

            return response()->json([
                'format' => $format,
                'ok' => $original === $recovered,
                'originalBytes' => strlen($original),
                'artifactBytes' => strlen($artifact),
                'recoveredBytes' => strlen($recovered),
                'artifactBase64' => base64_encode($artifact),
            ])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function onionEncode(Request $request): Response
    {
        [$rawIn, $rawOut] = $this->ioFlags($request);
        try {
            $current = $rawIn ? $request->getContent() : $this->decodeB64((string) $request->input('dataBase64', ''));
            $chain = $rawIn
                ? array_values(array_filter(array_map('trim', explode(',', (string) $request->query('chain', '')))))
                : (array) $request->input('chain', []);
            if ($chain === [] || count($chain) > 6) {
                return response()->json(['error' => 'chain must contain 1..6 formats'], 400);
            }
            foreach ($chain as $fmt) {
                $current = $this->codecs->encode((string) $fmt, $current);
            }
            $finalFormat = (string) end($chain);
            return $this->sendArtifact($rawOut, $finalFormat, $current, ['chain' => $chain]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function onionDecode(Request $request): Response
    {
        [$rawIn] = $this->ioFlags($request);
        try {
            $current = $rawIn ? $request->getContent() : $this->decodeB64((string) $request->input('dataBase64', ''));
            $chain = $rawIn
                ? array_values(array_filter(array_map('trim', explode(',', (string) $request->query('chain', '')))))
                : (array) $request->input('chain', []);
            foreach (array_reverse($chain) as $fmt) {
                $current = $this->codecs->decode((string) $fmt, $current);
            }
            return response()->json([
                'chain' => $chain,
                'bytes' => strlen($current),
                'dataBase64' => base64_encode($current),
            ])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function gptPrompt(Request $request): Response
    {
        [$rawIn] = $this->ioFlags($request);
        try {
            $data = $rawIn ? $request->getContent() : $this->decodeB64((string) $request->input('dataBase64', ''));
            $format = (string) ($rawIn ? $request->query('format') : $request->input('format', 'loaf'));
            $filename = (string) ($rawIn ? ($request->query('filename') ?: 'payload.bin') : ($request->input('filename') ?: 'payload.bin'));
            $note = (string) ($rawIn ? $request->query('note', '') : $request->input('note', ''));

            if (!isset(ConversionFacade::FORMAT_INFO[$format])) {
                return response()->json(['error' => "Unknown format: {$format}"], 400);
            }

            $info = ConversionFacade::FORMAT_INFO[$format];
            $artifact = $this->codecs->encode($format, $data);
            $transport = base64_encode((string) gzencode($artifact, 9));
            $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) ?: 'payload.bin';

            $prompt = "Mirror file-conversion payload. Format: {$info['label']} ({$info['blurb']})\n"
                . "The artifact is transported below as base64(gzip(file bytes)) to survive being pasted as plain text.\n"
                . "First base64-decode and gzip-decompress the transport into a file named \"decoded.{$info['extension']}\"; THEN decode the {$info['label']} format itself to recover the original bytes.\n"
                . ($note !== '' ? "\nNote from the sender: {$note}\n" : '')
                . "\nOriginal filename hint for the recovered payload: {$safeFilename}\n"
                . "\nBEGIN_GZIP_BASE64\n{$transport}\nEND_GZIP_BASE64";

            return response()->json([
                'format' => $format,
                'filename' => $safeFilename,
                'artifactBytes' => strlen($artifact),
                'transportBytes' => strlen($transport),
                'prompt' => $prompt,
            ])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function mystery(Request $request): Response
    {
        $fmt = ConversionFacade::FORMATS[array_rand(ConversionFacade::FORMATS)];
        $text = $request->query('text');
        $data = is_string($text) ? $text : random_bytes(64);
        $artifact = $this->codecs->encode($fmt, $data);

        return response()->json([
            'format' => $fmt,
            'surprise' => ConversionFacade::FORMAT_INFO[$fmt]['blurb'],
            'bytes' => strlen($artifact),
            'dataBase64' => base64_encode($artifact),
        ])->header('Cache-Control', 'no-store');
    }

    public function fortune(Request $request): Response
    {
        $fmt = (string) ($request->query('format') ?: 'pngspeak');
        if (!isset(ConversionFacade::FORMAT_INFO[$fmt])) {
            return response()->json(['error' => "Unknown format: {$fmt}"], 400);
        }

        $fortune = self::FORTUNES[array_rand(self::FORTUNES)];
        $artifact = $this->codecs->encode($fmt, $fortune);
        $info = ConversionFacade::FORMAT_INFO[$fmt];

        return response($artifact, 200, [
            'Content-Type' => $info['mime'],
            'Content-Disposition' => "inline; filename=\"fortune.{$info['extension']}\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    public function gauntlet(Request $request): Response
    {
        [$rawIn] = $this->ioFlags($request);
        try {
            $original = $rawIn ? $request->getContent() : $this->decodeB64((string) $request->input('dataBase64', ''));
            return response()->json([
                'results' => $this->codecs->runGauntlet($original),
            ])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @return array{bool, bool}
     */
    protected function ioFlags(Request $request): array
    {
        $raw = filter_var($request->query('raw', 'false'), FILTER_VALIDATE_BOOLEAN);
        $rawIn = $raw || filter_var($request->query('raw_in', 'false'), FILTER_VALIDATE_BOOLEAN);
        $rawOut = $raw || filter_var($request->query('raw_out', 'false'), FILTER_VALIDATE_BOOLEAN);
        return [$rawIn, $rawOut];
    }

    /**
     * @return string[]
     */
    protected function bodyToInputs(Request $request): array
    {
        $parts = $request->input('parts');
        if (is_array($parts) && $parts !== []) {
            return array_map(fn ($p) => $this->decodeB64((string) $p), $parts);
        }
        return [$this->decodeB64((string) $request->input('dataBase64', ''))];
    }

    protected function decodeB64(string $b64): string
    {
        if ($b64 === '') {
            return '';
        }
        $decoded = base64_decode($b64, true);
        if (!is_string($decoded)) {
            throw new \InvalidArgumentException('Invalid base64 payload');
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $extra
     */
    protected function sendArtifact(bool $rawOut, string $format, string $artifact, array $extra = []): Response
    {
        $info = ConversionFacade::FORMAT_INFO[$format];
        if ($rawOut) {
            return response($artifact, 200, [
                'Content-Type' => $info['mime'],
                'Content-Disposition' => "attachment; filename=\"mirror-convert.{$info['extension']}\"",
                'Cache-Control' => 'no-store',
            ]);
        }

        return response()->json(array_merge([
            'format' => $format,
            'bytes' => strlen($artifact),
            'dataBase64' => base64_encode($artifact),
        ], array_filter($extra, fn ($v) => $v !== null)))->header('Cache-Control', 'no-store');
    }
}
