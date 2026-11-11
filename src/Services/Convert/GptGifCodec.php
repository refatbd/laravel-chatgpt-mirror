<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services\Convert;

/**
 * Pure-PHP implementation of original `gptgif`: renders hex digits as 8x8 glyph tiles
 * inside a 640x480 indexed GIF animation and calibrates/decodes clusters via Hamming distance.
 */
class GptGifCodec
{
    public const WIDTH = 640;
    public const HEIGHT = 480;
    public const GLYPH_W = 8;
    public const GLYPH_H = 8;
    public const COLS = 80;
    public const ROWS = 60;
    public const FRAME_CHARS = 4800;

    /** @var array<int, array<int, int>> */
    public const FONT = [
        [0x00, 0x3c, 0x66, 0x6e, 0x76, 0x66, 0x3c, 0x00],
        [0x00, 0x18, 0x38, 0x18, 0x18, 0x18, 0x3c, 0x00],
        [0x00, 0x3c, 0x66, 0x0c, 0x18, 0x30, 0x7e, 0x00],
        [0x00, 0x3c, 0x66, 0x1c, 0x06, 0x66, 0x3c, 0x00],
        [0x00, 0x0c, 0x1c, 0x2c, 0x4c, 0x7e, 0x0c, 0x00],
        [0x00, 0x7e, 0x60, 0x7c, 0x06, 0x66, 0x3c, 0x00],
        [0x00, 0x3c, 0x60, 0x7c, 0x66, 0x66, 0x3c, 0x00],
        [0x00, 0x7e, 0x06, 0x0c, 0x18, 0x30, 0x30, 0x00],
        [0x00, 0x3c, 0x66, 0x3c, 0x66, 0x66, 0x3c, 0x00],
        [0x00, 0x3c, 0x66, 0x66, 0x3e, 0x06, 0x3c, 0x00],
        [0x00, 0x3c, 0x06, 0x3e, 0x66, 0x66, 0x3e, 0x00],
        [0x00, 0x60, 0x60, 0x7c, 0x66, 0x66, 0x7c, 0x00],
        [0x00, 0x3c, 0x60, 0x60, 0x60, 0x60, 0x3c, 0x00],
        [0x00, 0x06, 0x06, 0x3e, 0x66, 0x66, 0x3e, 0x00],
        [0x00, 0x3c, 0x66, 0x7e, 0x60, 0x60, 0x3c, 0x00],
        [0x00, 0x1c, 0x30, 0x30, 0x7c, 0x30, 0x30, 0x00],
    ];

    public function __construct(
        protected Gif89aCodec $gif
    ) {}

    /**
     * @param string[] $inputs
     */
    public function encode(array $inputs): string
    {
        $hex = bin2hex(implode('', $inputs));
        $palette = $this->buildPalette();
        $frames = [];
        $len = strlen($hex);

        for ($offset = 0, $frame = 0; $offset < $len; $offset += self::FRAME_CHARS, $frame++) {
            $chunk = substr($hex, $offset, self::FRAME_CHARS);
            $raster = array_fill(0, self::WIDTH * self::HEIGHT, 0);
            $chunkLen = strlen($chunk);
            for ($i = 0; $i < $chunkLen; $i++) {
                $row = intdiv($i, self::COLS);
                $col = $i % self::COLS;
                $this->drawChar($raster, $col * self::GLYPH_W, $row * self::GLYPH_H, $chunk[$i], $frame);
            }
            $frames[] = ['pixels' => $raster, 'delayCs' => 30];
        }

        return $this->gif->writeGif(self::WIDTH, self::HEIGHT, $palette, $frames);
    }

    public function calibrate(string $gifBytes, string $clusterMap = '0123456789abcdef'): string
    {
        $image = $this->gif->readGif($gifBytes);
        $tiles = $this->extractGlyphTiles($image);
        ['centroids' => $centroids] = $this->clusterGlyphTiles($tiles, strlen($clusterMap));

        $lines = ['K-Means Cluster Centroids (visualized as 8x8 glyphs):'];
        foreach ($centroids as $i => $centroid) {
            $lines[] = '';
            $lines[] = "Cluster Label: {$i}";
            for ($row = 0; $row < self::GLYPH_H; $row++) {
                $line = '';
                for ($col = 0; $col < self::GLYPH_W; $col++) {
                    $line .= $centroid[$row * self::GLYPH_W + $col] > 0.5 ? '#' : '.';
                }
                $lines[] = $line;
            }
            $lines[] = str_repeat('-', 20);
        }
        $lines[] = '';
        $lines[] = 'Now associate each index with the correct character from the cluster map.';

        return implode("\n", $lines);
    }

    public function decode(string $gifBytes, string $clusterMap = '0123456789abcdef'): string
    {
        $image = $this->gif->readGif($gifBytes);
        $tiles = $this->extractGlyphTiles($image);
        ['labels' => $labels] = $this->clusterGlyphTiles($tiles, strlen($clusterMap));

        $hex = '';
        foreach ($labels as $label) {
            $hex .= $clusterMap[$label] ?? '0';
        }
        if (strlen($hex) % 2 !== 0) {
            $hex = substr($hex, 0, -1);
        }

        $raw = $hex === '' ? '' : (string) hex2bin($hex);
        return (string) gzencode($raw, 9);
    }

    public function gunzipOutput(string $compressed): string
    {
        $out = @gzdecode($compressed);
        if (!is_string($out)) {
            throw new \InvalidArgumentException('gptgif: failed gunzip decode');
        }
        return $out;
    }

    public function fontPreview(): string
    {
        $lines = [];
        foreach (self::FONT as $n => $glyph) {
            $lines[] = '';
            $lines[] = 'Glyph ' . dechex($n) . ':';
            for ($y = 0; $y < 8; $y++) {
                $line = '';
                for ($x = 0; $x < 8; $x++) {
                    $line .= ($glyph[$y] & (1 << (7 - $x))) ? '#' : '.';
                }
                $lines[] = $line;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * @return array<int, array{r: int, g: int, b: int}>
     */
    protected function buildPalette(): array
    {
        $colors = [['r' => 0, 'g' => 0, 'b' => 0]];
        for ($i = 1; $i < 256; $i++) {
            $colors[] = [
                'r' => 140 + (($i * 5) % 116),
                'g' => 160 + (($i * 3) % 96),
                'b' => 130 + (($i * 7) % 126),
            ];
        }
        return $colors;
    }

    /**
     * @param array<int, int> $raster
     */
    protected function drawChar(array &$raster, int $x, int $y, string $ch, int $frame): void
    {
        $n = (int) hexdec($ch);
        $glyph = self::FONT[$n];
        for ($dy = 0; $dy < self::GLYPH_H; $dy++) {
            for ($dx = 0; $dx < self::GLYPH_W; $dx++) {
                if ($glyph[$dy] & (1 << (7 - $dx))) {
                    $raster[($y + $dy) * self::WIDTH + ($x + $dx)] = 32 + (($frame + $dy + $dx) % 223);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $image
     * @return array<int, int>
     */
    protected function extractGlyphTiles(array $image): array
    {
        $tiles = [];
        $gct = $image['globalColorTable'];
        $width = $image['width'];
        $height = $image['height'];

        foreach ($image['frames'] as $frame) {
            $pixels = $frame['pixels'];
            $binary = [];
            foreach ($pixels as $i => $idx) {
                $c = $gct[$idx] ?? ['r' => 0, 'g' => 0, 'b' => 0];
                $luma = ($c['r'] * 19595 + $c['g'] * 38470 + $c['b'] * 7471 + 0x8000) >> 16;
                $binary[$i] = $luma > 128 ? 1 : 0;
            }

            $rows = intdiv($height, self::GLYPH_H);
            $cols = intdiv($width, self::GLYPH_W);
            for ($row = 0; $row < $rows; $row++) {
                for ($col = 0; $col < $cols; $col++) {
                    $mask = 0;
                    $sum = 0;
                    $x0 = $col * self::GLYPH_W;
                    $y0 = $row * self::GLYPH_H;
                    for ($dy = 0; $dy < self::GLYPH_H; $dy++) {
                        for ($dx = 0; $dx < self::GLYPH_W; $dx++) {
                            if (!empty($binary[($y0 + $dy) * $width + ($x0 + $dx)])) {
                                $mask |= (1 << ($dy * self::GLYPH_W + $dx));
                                $sum++;
                            }
                        }
                    }
                    if ($sum < 5) {
                        return $tiles;
                    }
                    $tiles[] = $mask;
                }
            }
        }

        return $tiles;
    }

    /**
     * @param array<int, int> $tiles
     * @return array{labels: array<int, int>, centroids: array<int, array<int, float>>}
     */
    protected function clusterGlyphTiles(array $tiles, int $k): array
    {
        $clusterMasks = [];
        $labels = [];

        foreach ($tiles as $mask) {
            $found = array_search($mask, $clusterMasks, true);
            if ($found === false) {
                if (count($clusterMasks) < $k) {
                    $found = count($clusterMasks);
                    $clusterMasks[] = $mask;
                } else {
                    $found = 0;
                    $best = $this->hammingDistance($mask, $clusterMasks[0]);
                    for ($c = 1; $c < count($clusterMasks); $c++) {
                        $d = $this->hammingDistance($mask, $clusterMasks[$c]);
                        if ($d < $best) {
                            $best = $d;
                            $found = $c;
                        }
                    }
                }
            }
            $labels[] = $found;
        }

        $centroids = array_fill(0, $k, array_fill(0, 64, 0.0));
        $counts = array_fill(0, $k, 0);

        foreach ($tiles as $i => $mask) {
            $label = $labels[$i];
            $counts[$label]++;
            for ($bit = 0; $bit < 64; $bit++) {
                if (($mask >> $bit) & 1) {
                    $centroids[$label][$bit] += 1.0;
                }
            }
        }

        for ($c = 0; $c < $k; $c++) {
            if ($counts[$c] === 0) {
                continue;
            }
            for ($bit = 0; $bit < 64; $bit++) {
                $centroids[$c][$bit] /= $counts[$c];
            }
        }

        return ['labels' => $labels, 'centroids' => $centroids];
    }

    protected function hammingDistance(int $a, int $b): int
    {
        $x = $a ^ $b;
        $count = 0;
        for ($bit = 0; $bit < 64; $bit++) {
            $count += ($x >> $bit) & 1;
        }
        return $count;
    }
}
