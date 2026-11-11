<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services\Convert;

/**
 * Pure-PHP implementation of self-calibrating `gptgif-v4`:
 * calibration frame (swatches + legend + SHA-256 visual header), xorshift32
 * boustrophedon cell permutation, glyph rotation/jitter/holes, and strict digest verify.
 */
class GptGifV4Codec
{
    private const WIDTH = 640;
    private const HEIGHT = 480;
    private const GLYPH_W = 8;
    private const GLYPH_H = 8;
    private const COLS = 80;
    private const ROWS = 60;
    private const FRAME_CHARS = 4800;
    private const FRAME_BYTES = 2400;
    private const NCOLORS = 256;
    private const LEGEND_START = 320;
    private const HEADER_START = 400;
    private const HEADER_BYTES = 48;
    private const MAGIC = "GPTGIF4\0";

    private const MARKER_X = [1, 2, 3, 4];
    private const MARKER_Y = [1, 2, 3, 4];

    /** @var array<int, int> */
    private array $boustrophedon = [];

    public function __construct(
        protected Gif89aCodec $gif
    ) {
        for ($col = 0; $col < self::COLS; $col++) {
            for ($row = 0; $row < self::ROWS; $row++) {
                $logical = $col * self::ROWS + $row;
                $physRow = ($col & 1) ? (self::ROWS - 1 - $row) : $row;
                $this->boustrophedon[$logical] = $col * self::ROWS + $physRow;
            }
        }
    }

    /**
     * @param string[] $inputs
     * @param array{font?: ?array, fontSeed?: ?int, palette?: ?array, paletteSeed?: ?int} $options
     */
    public function encode(array $inputs, array $options = []): string
    {
        $font = $options['font'] ?? (isset($options['fontSeed']) ? $this->randomFont((int) $options['fontSeed']) : GptGifCodec::FONT);
        $palette = $options['palette'] ?? (isset($options['paletteSeed']) ? $this->randomPalette((int) $options['paletteSeed']) : $this->defaultPalette());

        $this->validateFont($font);
        $this->validatePalette($palette);

        $payload = implode('', $inputs);
        $digest = hash('sha256', $payload, true);
        $length = strlen($payload);

        $frames = [
            ['pixels' => $this->buildCalibrationFrame($length, $digest, $font)],
        ];

        $offset = 0;
        $frame = 0;
        while ($offset < $length) {
            $chunk = substr($payload, $offset, self::FRAME_BYTES);
            $frames[] = ['pixels' => $this->buildPayloadFrame($chunk, $frame, $font)];
            $offset += strlen($chunk);
            $frame++;
        }

        return $this->gif->writeGif(self::WIDTH, self::HEIGHT, $palette, $frames);
    }

    public function decode(string $gifBytes): string
    {
        $image = $this->gif->readGif($gifBytes);
        if ($image['width'] !== self::WIDTH || $image['height'] !== self::HEIGHT) {
            throw new \InvalidArgumentException('gptungif-v4: expected a 640x480 GIF');
        }
        if ($image['frames'] === []) {
            throw new \InvalidArgumentException('gptungif-v4: missing v4 calibration frame');
        }

        $cal = $this->learnCalibration($image);
        $payloadFrameCount = (int) ceil($cal['payloadLength'] / self::FRAME_BYTES);
        if (count($image['frames']) !== $payloadFrameCount + 1) {
            throw new \InvalidArgumentException('gptungif-v4: GIF frame count disagrees with visual header length');
        }

        $out = '';
        $written = 0;
        for ($frameIndex = 0; $frameIndex < $payloadFrameCount; $frameIndex++) {
            $count = min(self::FRAME_BYTES, $cal['payloadLength'] - $written);
            $chunk = $this->decodePayloadFrame($image['frames'][$frameIndex + 1]['pixels'], $frameIndex, $count, $cal);
            $out .= $chunk;
            $written += strlen($chunk);
        }

        if (!hash_equals($cal['payloadDigest'], hash('sha256', $out, true))) {
            throw new \InvalidArgumentException('gptungif-v4: SHA-256 digest mismatch');
        }

        return $out;
    }

    /**
     * @return array<int, array<int, int>>
     */
    public function randomFont(int $seedIn): array
    {
        $seed = $seedIn & 0xffffffff;
        if ($seed === 0) {
            $seed = 0x6d2b79f5;
        }
        $font = array_fill(0, 16, array_fill(0, 8, 0));

        for ($nibble = 0; $nibble < 16; $nibble++) {
            for ($y = 1; $y <= 6; $y++) {
                for ($x = 1; $x <= 6; $x++) {
                    $reserved = ($x === 5 && $y === 5);
                    for ($bit = 0; $bit < 4; $bit++) {
                        if ($x === self::MARKER_X[$bit] && $y === self::MARKER_Y[$bit]) {
                            $reserved = true;
                        }
                    }
                    $seed = $this->xorshift32($seed);
                    if (!$reserved && ($seed & 1)) {
                        $font[$nibble][$y] |= (1 << (7 - $x));
                    }
                }
            }
            $font[$nibble][5] |= (1 << (7 - 5));
            for ($bit = 0; $bit < 4; $bit++) {
                if ($nibble & (1 << $bit)) {
                    $font[$nibble][self::MARKER_Y[$bit]] |= (1 << (7 - self::MARKER_X[$bit]));
                }
            }
        }

        return $font;
    }

    /**
     * @return array<int, array{r: int, g: int, b: int}>
     */
    public function defaultPalette(): array
    {
        $colors = [];
        $colors[0] = ['r' => 5, 'g' => 10, 'b' => 20];
        for ($i = 1; $i < self::NCOLORS; $i++) {
            if ($i <= 15) {
                $colors[$i] = ['r' => $i * 2, 'g' => $i * 2 + 5, 'b' => $i * 3 + 20];
            } elseif ($i === 31) {
                $colors[$i] = ['r' => 80, 'g' => 90, 'b' => 120];
            } else {
                $colors[$i] = ['r' => 10 + (($i * 2) % 64), 'g' => 100 + (($i * 3) % 80), 'b' => 150 + (($i * 5) % 100)];
            }
        }
        return $colors;
    }

    /**
     * @return array<int, array{r: int, g: int, b: int}>
     */
    public function randomPalette(int $seedIn): array
    {
        $seed = $seedIn & 0xffffffff;
        if ($seed === 0) {
            $seed = 0xa5a5a5a5;
        }
        $colors = [];
        for ($i = 0; $i < self::NCOLORS; $i++) {
            $seed = $this->xorshift32($seed);
            $colors[$i] = ['r' => $seed & 0xff, 'g' => ($seed >> 8) & 0xff, 'b' => ($seed >> 16) & 0xff];
        }

        for ($real = 40; $real <= 239; $real++) {
            while (true) {
                $collision = false;
                for ($low = 0; $low <= 31; $low++) {
                    if ($low > 15 && $low !== 31) {
                        continue;
                    }
                    if ($colors[$real] === $colors[$low]) {
                        $collision = true;
                        break;
                    }
                }
                if (!$collision) {
                    break;
                }
                $seed = $this->xorshift32($seed);
                $colors[$real] = ['r' => $seed & 0xff, 'g' => ($seed >> 8) & 0xff, 'b' => ($seed >> 16) & 0xff];
            }
        }

        return $colors;
    }

    /**
     * @param array<int, array<int, int>> $font
     */
    public function fontToBytes(array $font): string
    {
        $out = '';
        for ($n = 0; $n < 16; $n++) {
            for ($y = 0; $y < 8; $y++) {
                $out .= chr($font[$n][$y] & 0xff);
            }
        }
        return $out;
    }

    /**
     * @return array<int, array<int, int>>
     */
    public function fontFromBytes(string $bytes): array
    {
        if (strlen($bytes) !== 128) {
            throw new \InvalidArgumentException('gptgif-v4: font file must contain exactly 128 bytes, got ' . strlen($bytes));
        }
        $font = [];
        for ($n = 0; $n < 16; $n++) {
            $rows = [];
            for ($y = 0; $y < 8; $y++) {
                $rows[] = ord($bytes[$n * 8 + $y]);
            }
            $font[] = $rows;
        }
        return $font;
    }

    /**
     * @param array<int, array{r: int, g: int, b: int}> $colors
     */
    public function paletteToBytes(array $colors): string
    {
        $out = '';
        for ($i = 0; $i < self::NCOLORS; $i++) {
            $c = $colors[$i] ?? ['r' => 0, 'g' => 0, 'b' => 0];
            $out .= chr($c['r'] & 0xff) . chr($c['g'] & 0xff) . chr($c['b'] & 0xff);
        }
        return $out;
    }

    /**
     * @return array<int, array{r: int, g: int, b: int}>
     */
    public function paletteFromBytes(string $bytes): array
    {
        if (strlen($bytes) !== 768) {
            throw new \InvalidArgumentException('gptgif-v4: palette file must contain exactly 768 bytes, got ' . strlen($bytes));
        }
        $colors = [];
        for ($i = 0; $i < self::NCOLORS; $i++) {
            $colors[] = [
                'r' => ord($bytes[$i * 3]),
                'g' => ord($bytes[$i * 3 + 1]),
                'b' => ord($bytes[$i * 3 + 2]),
            ];
        }
        return $colors;
    }

    /**
     * @param array<int, array<int, int>> $font
     */
    public function validateFont(array $font): void
    {
        for ($rot = 0; $rot < 4; $rot++) {
            for ($jx = -1; $jx <= 1; $jx++) {
                for ($jy = -1; $jy <= 1; $jy++) {
                    for ($holes = 0; $holes <= 1; $holes++) {
                        $masks = [];
                        for ($n = 0; $n < 16; $n++) {
                            $mask = 0;
                            for ($y = 0; $y < 8; $y++) {
                                for ($x = 0; $x < 8; $x++) {
                                    if (!(($font[$n][$y] ?? 0) & (1 << (7 - $x)))) {
                                        continue;
                                    }
                                    if ($holes === 1 && (($x + $y) % 2) === 1) {
                                        continue;
                                    }
                                    [$u, $v] = $this->rotatePixel($x, $y, $rot);
                                    $u += $jx;
                                    $v += $jy;
                                    if ($u < 0 || $u >= 8 || $v < 0 || $v >= 8) {
                                        throw new \InvalidArgumentException('gptgif-v4: font glyph ' . dechex($n) . ' escapes its cell under jitter');
                                    }
                                    $mask |= (1 << ($v * 8 + $u));
                                }
                            }
                            if ($mask === 0) {
                                throw new \InvalidArgumentException('gptgif-v4: font glyph ' . dechex($n) . ' disappears under an allowed transform');
                            }
                            for ($other = 0; $other < $n; $other++) {
                                if ($masks[$other] === $mask) {
                                    throw new \InvalidArgumentException('gptgif-v4: font glyphs ' . dechex($other) . ' and ' . dechex($n) . ' collide under an allowed transform');
                                }
                            }
                            $masks[$n] = $mask;
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array<int, array{r: int, g: int, b: int}> $colors
     */
    public function validatePalette(array $colors): void
    {
        for ($real = 40; $real <= 239; $real++) {
            for ($low = 0; $low <= 31; $low++) {
                if ($low > 15 && $low !== 31) {
                    continue;
                }
                if ($colors[$real] === $colors[$low]) {
                    throw new \InvalidArgumentException("gptgif-v4: foreground color role {$real} collides with role {$low}");
                }
            }
        }
    }

    protected function xorshift32(int $state): int
    {
        $x = $state & 0xffffffff;
        $x = ($x ^ ($x << 13)) & 0xffffffff;
        $x = ($x ^ (($x >> 17) & 0xffffffff)) & 0xffffffff;
        $x = ($x ^ ($x << 5)) & 0xffffffff;
        return $x & 0xffffffff;
    }

    /**
     * @return array{int, int}
     */
    protected function rotatePixel(int $x, int $y, int $rot): array
    {
        return match ($rot) {
            0 => [$x, $y],
            1 => [7 - $y, $x],
            2 => [7 - $x, 7 - $y],
            default => [$y, 7 - $x],
        };
    }

    /**
     * @param array<int, array<int, int>> $font
     * @return array<int, int>
     */
    protected function buildCalibrationFrame(int $length, string $digest, array $font): array
    {
        $raster = array_fill(0, self::WIDTH * self::HEIGHT, 0);

        for ($role = 0; $role < self::NCOLORS; $role++) {
            $baseX = ($role % self::COLS) * self::GLYPH_W;
            $baseY = intdiv($role, self::COLS) * self::GLYPH_H;
            for ($y = 0; $y < 8; $y++) {
                $rowOff = ($baseY + $y) * self::WIDTH + $baseX;
                for ($x = 0; $x < 8; $x++) {
                    $raster[$rowOff + $x] = $role;
                }
            }
        }

        for ($n = 0; $n < 16; $n++) {
            $this->drawCanonical($raster, self::LEGEND_START + $n, $n, $font);
        }

        $header = self::MAGIC . pack('P', $length) . $digest;
        for ($i = 0; $i < self::HEADER_BYTES; $i++) {
            $byte = ord($header[$i]);
            $this->drawCanonical($raster, self::HEADER_START + $i * 2, $byte & 15, $font);
            $this->drawCanonical($raster, self::HEADER_START + $i * 2 + 1, ($byte >> 4) & 15, $font);
        }

        return $raster;
    }

    /**
     * @param array<int, int> $raster
     * @param array<int, array<int, int>> $font
     */
    protected function drawCanonical(array &$raster, int $cell, int $nibble, array $font): void
    {
        $baseX = ($cell % self::COLS) * self::GLYPH_W;
        $baseY = intdiv($cell, self::COLS) * self::GLYPH_H;
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                if ($font[$nibble][$y] & (1 << (7 - $x))) {
                    $raster[($baseY + $y) * self::WIDTH + $baseX + $x] = 40;
                }
            }
        }
    }

    /**
     * @param array<int, array<int, int>> $font
     * @return array<int, int>
     */
    protected function buildPayloadFrame(string $bytes, int $frame, array $font): array
    {
        $raster = array_fill(0, self::WIDTH * self::HEIGHT, 0);
        $length = strlen($bytes);
        $nibbleCount = $length * 2;
        $nibbles = array_fill(0, self::FRAME_CHARS, 0);

        for ($i = 0; $i < $length; $i++) {
            $b = ord($bytes[$i]) ^ 0xa5;
            $nibbles[2 * $i] = $b & 15;
            $nibbles[2 * $i + 1] = ($b >> 4) & 15;
        }

        $seedPerm = (0xc0ffee00 + $frame * 0x9e3779b1 + 0xdefc0ffe) & 0xffffffff;
        $seedGlyph = ($seedPerm ^ 0x12345678) & 0xffffffff;
        $seedNoise = ($seedPerm ^ 0xa5a5a5a5) & 0xffffffff;

        $perm = range(0, self::FRAME_CHARS - 1);
        for ($i = self::FRAME_CHARS - 1; $i > 0; $i--) {
            $seedPerm = $this->xorshift32($seedPerm);
            $j = $seedPerm % ($i + 1);
            [$perm[$i], $perm[$j]] = [$perm[$j], $perm[$i]];
        }

        $noiseCount = intdiv(self::WIDTH * self::HEIGHT, 50);
        for ($k = 0; $k < $noiseCount; $k++) {
            $seedNoise = $this->xorshift32($seedNoise);
            $pos = $seedNoise % (self::WIDTH * self::HEIGHT);
            if ($raster[$pos] === 0) {
                $raster[$pos] = (($seedNoise >> 16) % 15) + 1;
            }
        }

        $glyphSeeds = [];
        for ($i = 0; $i < self::FRAME_CHARS; $i++) {
            $seedGlyph = $this->xorshift32($seedGlyph);
            $glyphSeeds[$i] = $seedGlyph;
        }

        $shift = (($frame & 15) * 7 + 13) & 15;
        for ($i = 0; $i < $nibbleCount; $i++) {
            $src = ($frame & 1) ? ($i ^ 1) : $i;
            $encoded = ($nibbles[$src] + $shift) & 15;
            $p = $perm[$this->boustrophedon[$i]];
            $this->drawGlyph($raster, intdiv($p, self::ROWS) * 8, ($p % self::ROWS) * 8, $encoded, $frame, $glyphSeeds[$i], false, $font);
        }

        for ($i = $nibbleCount; $i < self::FRAME_CHARS; $i++) {
            $seedGlyph = $this->xorshift32($seedGlyph);
            if ($seedGlyph % 100 < 20) {
                $p = $perm[$this->boustrophedon[$i]];
                $this->drawGlyph($raster, intdiv($p, self::ROWS) * 8, ($p % self::ROWS) * 8, $seedGlyph % 16, $frame, $seedGlyph, true, $font);
            }
        }

        return $raster;
    }

    /**
     * @param array<int, int> $raster
     * @param array<int, array<int, int>> $font
     */
    protected function drawGlyph(array &$raster, int $baseX, int $baseY, int $nibble, int $frame, int $seed, bool $decoy, array $font): void
    {
        $rot = $seed & 3;
        $jx = min((($seed >> 2) & 3) - 1, 1);
        $jy = min((($seed >> 4) & 3) - 1, 1);
        $holes = !$decoy && (($seed >> 6) & 7) === 0;
        $frameColor = ($frame % 200) * 5;

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                if (!($font[$nibble][$y] & (1 << (7 - $x)))) {
                    continue;
                }
                if ($holes && (($x + $y) % 2) === 1) {
                    continue;
                }
                [$u, $v] = $this->rotatePixel($x, $y, $rot);
                $cx = $u - 4;
                $cy = $v - 4;
                $dist = $cx * $cx + $cy * $cy;
                $role = $decoy ? 31 : (40 + (($frameColor + $dist * 17 + ($seed & 127)) % 200));
                $raster[($baseY + $v + $jy) * self::WIDTH + ($baseX + $u + $jx)] = $role;
            }
        }
    }

    /**
     * @param array<string, mixed> $image
     * @return array{templates: array<int, array<int, int>>, styleBySeed: array<int, int>, payloadLength: int, payloadDigest: string}
     */
    protected function learnCalibration(array $image): array
    {
        $raster = $image['frames'][0]['pixels'];
        $canonical = [];
        for ($n = 0; $n < 16; $n++) {
            $canonical[] = $this->readRowMajorMask($raster, self::LEGEND_START + $n, fn (int $v) => $v === 40);
        }

        [$templates, $styleBySeed] = $this->learnTemplates($canonical);

        $headerBytes = '';
        for ($i = 0; $i < self::HEADER_BYTES; $i++) {
            $lo = array_search($this->readRowMajorMask($raster, self::HEADER_START + $i * 2, fn (int $v) => $v === 40), $canonical, true);
            $hi = array_search($this->readRowMajorMask($raster, self::HEADER_START + $i * 2 + 1, fn (int $v) => $v === 40), $canonical, true);
            if ($lo === false || $hi === false) {
                throw new \InvalidArgumentException('gptungif-v4: visual header contains an unknown glyph');
            }
            $headerBytes .= chr($lo | ($hi << 4));
        }

        if (substr($headerBytes, 0, 8) !== self::MAGIC) {
            throw new \InvalidArgumentException('gptungif-v4: visual header is not gptgif v4');
        }

        $len = unpack('P', substr($headerBytes, 8, 8))[1];
        $digest = substr($headerBytes, 16, 32);

        return [
            'templates' => $templates,
            'styleBySeed' => $styleBySeed,
            'payloadLength' => (int) $len,
            'payloadDigest' => $digest,
        ];
    }

    protected function readRowMajorMask(array $raster, int $cell, callable $pred): int
    {
        $baseX = ($cell % self::COLS) * 8;
        $baseY = intdiv($cell, self::COLS) * 8;
        $mask = 0;
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                if ($pred($raster[($baseY + $y) * self::WIDTH + $baseX + $x])) {
                    $mask |= (1 << ($y * 8 + $x));
                }
            }
        }
        return $mask;
    }

    /**
     * @param array<int, int> $canonical
     * @return array{array<int, array<int, int>>, array<int, int>}
     */
    protected function learnTemplates(array $canonical): array
    {
        $coords = [];
        foreach ($canonical as $mask) {
            $pts = [];
            for ($bit = 0; $bit < 64; $bit++) {
                if (($mask >> $bit) & 1) {
                    $pts[] = [$bit % 8, intdiv($bit, 8)];
                }
            }
            $coords[] = $pts;
        }

        $templates = [];
        $styleMap = [];

        for ($rot = 0; $rot < 4; $rot++) {
            for ($jx = -1; $jx <= 1; $jx++) {
                for ($jy = -1; $jy <= 1; $jy++) {
                    foreach ([0, 1] as $holes) {
                        $styleMap["{$rot},{$jx},{$jy},{$holes}"] = count($templates);
                        $variants = [];
                        foreach ($coords as $pts) {
                            $mask = 0;
                            foreach ($pts as [$x, $y]) {
                                if ($holes === 1 && (($x + $y) % 2) === 1) {
                                    continue;
                                }
                                [$u, $v] = $this->rotatePixel($x, $y, $rot);
                                $u += $jx;
                                $v += $jy;
                                if ($u < 0 || $u >= 8 || $v < 0 || $v >= 8) {
                                    throw new \InvalidArgumentException('gptungif-v4: learned alphabet clips under v4 jitter');
                                }
                                $mask |= (1 << ($v * 8 + $u));
                            }
                            $variants[] = $mask;
                        }
                        if (in_array(0, $variants, true) || count(array_unique($variants, SORT_REGULAR)) !== 16) {
                            throw new \InvalidArgumentException('gptungif-v4: learned alphabet has empty or ambiguous transformed glyphs');
                        }
                        $templates[] = $variants;
                    }
                }
            }
        }

        $styleBySeed = [];
        for ($s = 0; $s < 512; $s++) {
            $rot = $s & 3;
            $jx = min((($s >> 2) & 3) - 1, 1);
            $jy = min((($s >> 4) & 3) - 1, 1);
            $holes = (($s >> 6) & 7) === 0 ? 1 : 0;
            $styleBySeed[$s] = $styleMap["{$rot},{$jx},{$jy},{$holes}"];
        }

        return [$templates, $styleBySeed];
    }

    /**
     * @param array<int, int> $raster
     * @param array{templates: array<int, array<int, int>>, styleBySeed: array<int, int>} $cal
     */
    protected function decodePayloadFrame(array $raster, int $frameIndex, int $byteCount, array $cal): string
    {
        $physMasks = [];
        for ($cell = 0; $cell < self::FRAME_CHARS; $cell++) {
            $col = intdiv($cell, self::ROWS);
            $row = $cell % self::ROWS;
            $baseX = $col * 8;
            $baseY = $row * 8;
            $mask = 0;
            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 8; $x++) {
                    $v = $raster[($baseY + $y) * self::WIDTH + $baseX + $x];
                    if ($v >= 40 && $v <= 239) {
                        $mask |= (1 << ($y * 8 + $x));
                    }
                }
            }
            $physMasks[$cell] = $mask;
        }

        $initial = (0xc0ffee00 + $frameIndex * 0x9e3779b1 + 0xdefc0ffe) & 0xffffffff;
        $state = $initial;
        $perm = range(0, self::FRAME_CHARS - 1);
        for ($i = self::FRAME_CHARS - 1; $i > 0; $i--) {
            $state = $this->xorshift32($state);
            $j = $state % ($i + 1);
            [$perm[$i], $perm[$j]] = [$perm[$j], $perm[$i]];
        }

        $gState = ($initial ^ 0x12345678) & 0xffffffff;
        $styles = [];
        for ($i = 0; $i < self::FRAME_CHARS; $i++) {
            $gState = $this->xorshift32($gState);
            $styles[$i] = $cal['styleBySeed'][$gState & 0x1ff];
        }

        $length = $byteCount * 2;
        $nibbles = [];
        for ($i = 0; $i < self::FRAME_CHARS; $i++) {
            $obs = $physMasks[$perm[$this->boustrophedon[$i]]];
            if ($i < $length) {
                if ($obs === 0) {
                    throw new \InvalidArgumentException("gptungif-v4: payload frame {$frameIndex} disagrees with header length");
                }
                $idx = array_search($obs, $cal['templates'][$styles[$i]], true);
                if ($idx === false) {
                    throw new \InvalidArgumentException("gptungif-v4: unknown or ambiguous glyph in frame {$frameIndex}");
                }
                $nibbles[$i] = $idx;
            } elseif ($obs !== 0) {
                throw new \InvalidArgumentException("gptungif-v4: unexpected foreground glyph after payload end");
            }
        }

        $shift = (($frameIndex * 7 + 13) & 15);
        for ($i = 0; $i < $length; $i++) {
            $nibbles[$i] = ($nibbles[$i] - $shift) & 15;
        }
        if ($frameIndex & 1) {
            for ($i = 0; $i < $length; $i += 2) {
                [$nibbles[$i], $nibbles[$i + 1]] = [$nibbles[$i + 1], $nibbles[$i]];
            }
        }

        $out = '';
        for ($i = 0; $i < $byteCount; $i++) {
            $out .= chr(($nibbles[2 * $i] | ($nibbles[2 * $i + 1] << 4)) ^ 0xa5);
        }

        return $out;
    }
}
