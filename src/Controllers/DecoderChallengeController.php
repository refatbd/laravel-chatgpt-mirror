<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Refatbd\LaravelChatGptMirror\Services\Convert\ConversionFacade;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ephemeral decoder challenge generator, tar.gz challenge kit exporter,
 * and exact hex answer verifier for the Playground Decoder Challenges feature.
 */
class DecoderChallengeController extends Controller
{
    private const CACHE_PREFIX = 'mirror:decoder_challenge:';

    /** @var array<string, string> */
    private const GUIDES = [
        'loaf' => "LoaF is one ASCII line: SHA256(-)=<64 lowercase hex hash> <hex data>.\nVerify SHA-256 of the ASCII hex data against the header, hex-decode the data, gunzip it, and extract payload.bin from the USTAR tar archive.",
        'pngspeak' => "PngSpeak stores bytes as 8-bit non-interlaced RGBA pixels row-major. Read the PNG iTXt chunk with keyword 'license' for the hex payload length and slice the raw RGBA bytes to that length.",
        'gptgif' => "Original gptgif visually renders hex nibbles across 8x8 glyph cells in a 640x480 GIF. Cluster tiles by shape and inspect the alphabet to recover bytes.",
        'gptgif-v4' => "gptgif v4 uses frame 0 as a 640x480 self-calibration frame (color swatches in cells 0..255, canonical nibbles in cells 320..335, 48-byte SHA-256 visual header in cells 400..495) and xorshift32 boustrophedon permutations in subsequent frames.",
    ];

    public function __construct(
        protected ConversionFacade $codecs,
        protected TokenPoolManager $pool
    ) {}

    public function create(Request $request): Response
    {
        $format = (string) $request->input('format', '');
        $guidance = (string) ($request->input('guidance') ?: 'guided');
        $payloadMode = (string) ($request->input('payload') ?: 'text');

        if (!isset(ConversionFacade::FORMAT_INFO[$format])) {
            return response()->json(['error' => 'format must be one of: loaf, pngspeak, gptgif, gptgif-v4'], 400);
        }

        $id = (string) Str::uuid();
        $expected = $payloadMode === 'binary'
            ? random_bytes(128)
            : "Mirror decoder expedition\nDiscovery: " . bin2hex(random_bytes(16)) . "\nKeep every byte: cafe, stars, and this final newline.\n";

        $options = $format === 'gptgif-v4'
            ? ['fontSeed' => random_int(1, 0x7fffffff), 'paletteSeed' => random_int(1, 0x7fffffff)]
            : [];
        $artifact = $this->codecs->encode($format, $expected, $options);
        $info = ConversionFacade::FORMAT_INFO[$format];
        $filename = "decoder-{$id}.{$info['extension']}";
        $transport = base64_encode((string) gzencode($artifact, 9));

        $guideText = $guidance === 'guided'
            ? "\nFORMAT GUIDE\n" . self::GUIDES[$format] . "\n"
            : "\nNo decoding guide is included. Investigate {$info['label']} directly.\n";

        $prompt = "Mirror decoder challenge {$id}\nFormat: {$info['label']}. Task: recover the exact original payload bytes.\n"
            . "First base64-decode and gzip-decompress the transport into {$filename}; THEN decode {$info['label']}.\n"
            . $guideText
            . "\nFinish with exactly one line:\nMIRROR_ANSWER_{$id}: HEX\n"
            . "\nBEGIN_GZIP_BASE64\n{$transport}\nEND_GZIP_BASE64";

        $expiresAtMs = (time() + 86400) * 1000;
        $scope = $this->pool->primaryAccountId();

        Cache::put(self::CACHE_PREFIX . $id, [
            'scope' => $scope,
            'expectedBase64' => base64_encode($expected),
            'artifactBase64' => base64_encode($artifact),
            'format' => $format,
            'prompt' => $prompt,
            'expiresAtMs' => $expiresAtMs,
        ], now()->addHours(24));

        return response()->json([
            'id' => $id,
            'format' => $format,
            'label' => $info['label'],
            'guidance' => $guidance,
            'payload' => $payloadMode,
            'filename' => $filename,
            'artifactBytes' => strlen($artifact),
            'prompt' => $prompt,
            'expiresAt' => $expiresAtMs,
        ])->header('Cache-Control', 'no-store');
    }

    public function kit(Request $request): Response
    {
        $ids = $request->input('ids');
        if (!is_array($ids) || $ids === [] || count($ids) > 4) {
            return response()->json(['error' => 'ids array (1..4 UUIDs) is required'], 400);
        }

        $scope = $this->pool->primaryAccountId();
        $entries = [];

        foreach ($ids as $id) {
            $challenge = Cache::get(self::CACHE_PREFIX . (string) $id);
            if (!is_array($challenge) || ($challenge['scope'] ?? '') !== $scope) {
                return response()->json(['error' => 'Challenge expired or unavailable. Generate a new one.'], 404);
            }

            $fmt = (string) $challenge['format'];
            $info = ConversionFacade::FORMAT_INFO[$fmt];
            $prefix = "{$fmt}-{$id}";
            $entries[] = ['name' => "{$prefix}/decoder-{$id}.{$info['extension']}", 'content' => (string) base64_decode($challenge['artifactBase64'])];
            $entries[] = ['name' => "{$prefix}/prompt.txt", 'content' => (string) $challenge['prompt']];
            $entries[] = ['name' => "{$prefix}/format-guide.txt", 'content' => self::GUIDES[$fmt]];
            $entries[] = ['name' => "{$prefix}/README.txt", 'content' => "{$info['label']} decoder challenge {$id}\n\nPaste prompt.txt into ChatGPT to solve.\n"];
        }

        $loaf = $this->codecs->loaf->makeLoaf($entries);
        $hex = explode(' ', $loaf, 2)[1] ?? '';
        $tarGz = (string) hex2bin($hex);

        return response($tarGz, 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="mirror-decoder-kits.tar.gz"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function artifact(string $id): Response
    {
        $challenge = Cache::get(self::CACHE_PREFIX . $id);
        if (!is_array($challenge) || ($challenge['scope'] ?? '') !== $this->pool->primaryAccountId()) {
            return response()->json(['error' => 'Challenge expired or unavailable. Generate a new one.'], 404);
        }

        $info = ConversionFacade::FORMAT_INFO[$challenge['format']];
        $artifact = (string) base64_decode($challenge['artifactBase64']);

        return response($artifact, 200, [
            'Content-Type' => $info['mime'],
            'Content-Disposition' => "attachment; filename=\"decoder-{$id}.{$info['extension']}\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    public function verify(Request $request, string $id): Response
    {
        $challenge = Cache::get(self::CACHE_PREFIX . $id);
        if (!is_array($challenge) || ($challenge['scope'] ?? '') !== $this->pool->primaryAccountId()) {
            return response()->json(['error' => 'Challenge expired or unavailable. Generate a new one.'], 404);
        }

        $hex = trim((string) $request->input('hex', ''));
        if (!preg_match('/^(?:[0-9a-fA-F]{2})*$/', $hex)) {
            return response()->json(['error' => 'Answer must contain complete hexadecimal byte pairs, without spaces.'], 400);
        }

        $actual = $hex === '' ? '' : (string) hex2bin($hex);
        $expected = (string) base64_decode($challenge['expectedBase64']);

        $minLen = min(strlen($expected), strlen($actual));
        $matchingBytes = 0;
        for ($i = 0; $i < $minLen; $i++) {
            if ($expected[$i] === $actual[$i]) {
                $matchingBytes++;
            }
        }

        $verdict = $actual === $expected ? 'exact' : ($matchingBytes > 0 ? 'partial' : 'mismatch');

        return response()->json([
            'verdict' => $verdict,
            'matchingBytes' => $matchingBytes,
            'expectedBytes' => strlen($expected),
            'actualBytes' => strlen($actual),
        ])->header('Cache-Control', 'no-store');
    }
}
