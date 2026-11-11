<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Refatbd\LaravelChatGptMirror\Services\Convert\ConversionFacade;
use Refatbd\LaravelChatGptMirror\Services\Convert\Gif89aCodec;
use Refatbd\LaravelChatGptMirror\Services\Convert\GptGifCodec;
use Refatbd\LaravelChatGptMirror\Services\Convert\GptGifV4Codec;
use Refatbd\LaravelChatGptMirror\Services\Convert\LoafCodec;
use Refatbd\LaravelChatGptMirror\Services\Convert\PngSpeakCodec;

class CodecsTest extends TestCase
{
    public function test_loaf_codec_roundtrip_and_checksum_verification(): void
    {
        $codec = new LoafCodec();
        $rawBinary = random_bytes(64);
        $loaf = $codec->makeLoaf([
            ['name' => 'hello.txt', 'content' => "Line 1\nবাংলা লাইন\n", 'mtime' => 1700000000],
            ['name' => 'blob.bin', 'content' => $rawBinary, 'mtime' => 1700000000],
        ]);

        $verified = $codec->verifyLoaf($loaf);
        $this->assertTrue($verified['ok']);
        $this->assertSame(2, count($codec->extractLoaf($loaf, true)));

        $entries = $codec->extractLoaf($loaf, true);
        $this->assertSame('hello.txt', $entries[0]['name']);
        $this->assertSame("Line 1\nবাংলা লাইন\n", $entries[0]['content']);
        $this->assertSame($rawBinary, $entries[1]['content']);

        // Corrupt a single hex character and expect verification failure
        $tampered = substr($loaf, 0, -2) . (str_ends_with(trim($loaf), '0') ? '1' : '0');
        $this->expectException(\InvalidArgumentException::class);
        $codec->extractLoaf($tampered, true);
    }

    public function test_pngspeak_codec_binary_exact_roundtrip(): void
    {
        $codec = new PngSpeakCodec();
        $payload = "Header\0Binary\xff\xfeবাংলা-UTF8-" . random_bytes(97);

        $png = $codec->encode($payload);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);

        $decoded = $codec->decode($png);
        $this->assertSame($payload, $decoded);
    }

    public function test_gif89a_and_gptgif_roundtrip(): void
    {
        $gif = new Gif89aCodec();
        $gptgif = new GptGifCodec($gif);

        $payload = 'Mirror GIF89a & 64-bit bitmask test payload!';
        $hex = bin2hex($payload);
        $clusterMap = '';
        for ($i = 0; $i < strlen($hex); $i++) {
            if (!str_contains($clusterMap, $hex[$i])) {
                $clusterMap .= $hex[$i];
            }
        }
        foreach (str_split('0123456789abcdef') as $ch) {
            if (!str_contains($clusterMap, $ch)) {
                $clusterMap .= $ch;
            }
        }

        $artifact = $gptgif->encode([$payload]);

        $this->assertStringStartsWith('GIF89a', $artifact);
        $parsed = $gif->readGif($artifact);
        $this->assertSame(640, $parsed['width']);
        $this->assertSame(480, $parsed['height']);
        $this->assertNotEmpty($parsed['frames']);

        $calibration = $gptgif->calibrate($artifact, $clusterMap);
        $this->assertStringContainsString('Cluster Label: 0', $calibration);

        $recoveredCompressed = $gptgif->decode($artifact, $clusterMap);
        $recovered = $gptgif->gunzipOutput($recoveredCompressed);
        $this->assertSame($payload, $recovered);
        $this->assertStringContainsString('Glyph 0:', $gptgif->fontPreview());
    }

    public function test_gptgif_v4_seed_reproducible_self_calibrating_roundtrip(): void
    {
        $gif = new Gif89aCodec();
        $v4 = new GptGifV4Codec($gif);

        $payload = "Seeded v4 test\nবাংলা ইউনিকোড ও বাইনারি:\x00\x01\xfe\xff";
        $options = ['fontSeed' => 1337, 'paletteSeed' => 4242, 'delayCs' => 5];

        $artifactA = $v4->encode([$payload], $options);
        $artifactB = $v4->encode([$payload], $options);

        $this->assertSame($artifactA, $artifactB);
        $this->assertStringStartsWith('GIF89a', $artifactA);
        $this->assertSame($payload, $v4->decode($artifactA));
    }

    public function test_conversion_facade_gauntlet_passes_all_roundtrippable_codecs(): void
    {
        $gif = new Gif89aCodec();
        $facade = new ConversionFacade(
            new LoafCodec(),
            new PngSpeakCodec(),
            $gif,
            new GptGifCodec($gif),
            new GptGifV4Codec($gif)
        );

        $results = $facade->runGauntlet('Gauntlet verification bytes 12345!');
        $byFormat = [];
        foreach ($results as $row) {
            $byFormat[$row['format']] = $row;
        }

        $this->assertTrue($byFormat['loaf']['ok']);
        $this->assertTrue($byFormat['pngspeak']['ok']);
        $this->assertTrue($byFormat['gptgif-v4']['ok']);
        $this->assertFalse($byFormat['gptgif']['roundTrips']);
    }
}
