<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services\Convert;

/**
 * Shared codec registry & format dispatcher used by both ConversionController
 * and DecoderChallengeController cleanly without duplicating codec switching.
 */
class ConversionFacade
{
    /** @var string[] */
    public const FORMATS = ['loaf', 'pngspeak', 'gptgif', 'gptgif-v4'];

    /** @var array<string, array{label: string, extension: string, mime: string, roundTrips: bool, blurb: string}> */
    public const FORMAT_INFO = [
        'loaf' => [
            'label' => 'LoaF',
            'extension' => 'loaf',
            'mime' => 'text/plain',
            'roundTrips' => true,
            'blurb' => 'A tar+gzip archive hex-encoded onto a single checksummed line.',
        ],
        'pngspeak' => [
            'label' => 'PngSpeak',
            'extension' => 'pngspk.png',
            'mime' => 'image/png',
            'roundTrips' => true,
            'blurb' => 'Raw bytes stored one-to-one as RGBA pixels in an uncompressed-scanline PNG.',
        ],
        'gptgif' => [
            'label' => 'gptgif (original)',
            'extension' => 'gptgif.gif',
            'mime' => 'image/gif',
            'roundTrips' => false,
            'blurb' => 'Bytes rendered as a grid of glyph tiles in an animated GIF; decoding needs a calibrated cluster map.',
        ],
        'gptgif-v4' => [
            'label' => 'gptgif v4',
            'extension' => 'gptgif-v4.gif',
            'mime' => 'image/gif',
            'roundTrips' => true,
            'blurb' => 'gptgif with a randomizable glyph font and color palette (seed-reproducible).',
        ],
    ];

    public function __construct(
        public LoafCodec $loaf,
        public PngSpeakCodec $pngspeak,
        public Gif89aCodec $gif89a,
        public GptGifCodec $gptgif,
        public GptGifV4Codec $gptgifV4
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function encode(string $format, string $bytes, array $options = []): string
    {
        return match ($format) {
            'loaf' => $this->loaf->makeLoaf([['name' => 'payload.bin', 'content' => $bytes, 'mtime' => 0]]),
            'pngspeak' => $this->pngspeak->encode($bytes, $options),
            'gptgif' => $this->gptgif->encode([$bytes]),
            'gptgif-v4' => $this->gptgifV4->encode([$bytes], $options),
            default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
        };
    }

    public function decode(string $format, string $artifact): string
    {
        return match ($format) {
            'loaf' => $this->decodeLoafFirst($artifact),
            'pngspeak' => $this->pngspeak->decode($artifact),
            'gptgif' => $this->gptgif->gunzipOutput($this->gptgif->decode($artifact)),
            'gptgif-v4' => $this->gptgifV4->decode($artifact),
            default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function runGauntlet(string $originalBytes): array
    {
        $results = [];
        foreach (self::FORMATS as $fmt) {
            try {
                $artifact = $this->encode($fmt, $originalBytes);
                $recovered = $this->decode($fmt, $artifact);
                $results[] = [
                    'format' => $fmt,
                    'roundTrips' => self::FORMAT_INFO[$fmt]['roundTrips'],
                    'ok' => $originalBytes === $recovered,
                    'artifactBytes' => strlen($artifact),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'format' => $fmt,
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    protected function decodeLoafFirst(string $artifact): string
    {
        $entries = $this->loaf->extractLoaf($artifact);
        if ($entries === []) {
            throw new \InvalidArgumentException('LoaF archive contains no entries.');
        }
        return $entries[0]['content'];
    }
}
