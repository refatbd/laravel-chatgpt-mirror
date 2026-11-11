<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services\Convert;

/**
 * Pure-PHP implementation of PngSpeak: encodes arbitrary bytes as RGBA pixels inside
 * an 8-bit non-interlaced PNG with filter-0 scanlines and embedded `license` iTXt length.
 */
class PngSpeakCodec
{
    private const PNG_SIG = "\x89PNG\r\n\x1a\n";
    private const BPP = 4;

    /**
     * @param array{width?: ?int, height?: ?int, length?: ?int, rand?: ?string, upscaleWidth?: ?int, upscaleHeight?: ?int} $options
     */
    public function encode(string $input, array $options = []): string
    {
        $actualLength = strlen($input);
        $optLen = $options['length'] ?? null;
        $rand = $options['rand'] ?? null;

        if ($optLen !== null) {
            $lengthForHeader = (int) $optLen;
            if ($actualLength < $lengthForHeader) {
                $dataToEmbed = $input . $this->padBytes($lengthForHeader - $actualLength, $rand);
            } elseif ($actualLength > $lengthForHeader) {
                $dataToEmbed = substr($input, 0, $lengthForHeader);
            } else {
                $dataToEmbed = $input;
            }
        } else {
            $lengthForHeader = $actualLength;
            $dataToEmbed = $input;
        }

        $pixelsNeeded = max(1, (int) ceil(strlen($dataToEmbed) / self::BPP));
        [$gridW, $gridH] = $this->computeGridDimensions(
            $pixelsNeeded,
            isset($options['width']) ? (int) $options['width'] : null,
            isset($options['height']) ? (int) $options['height'] : null
        );

        $gridCapacity = $gridW * $gridH * self::BPP;
        $finalPixels = $dataToEmbed;
        if (strlen($finalPixels) < $gridCapacity) {
            $finalPixels .= $this->padBytes($gridCapacity - strlen($finalPixels), $rand);
        } elseif (strlen($finalPixels) > $gridCapacity) {
            $finalPixels = substr($finalPixels, 0, $gridCapacity);
        }

        $upscaleW = $options['upscaleWidth'] ?? null;
        $upscaleH = $options['upscaleHeight'] ?? null;
        $ihdrW = $upscaleW ?: $gridW;
        $ihdrH = $upscaleH ?: $gridH;

        $out = self::PNG_SIG;
        $ihdr = pack('NNCCCCC', $ihdrW, $ihdrH, 8, 6, 0, 0, 0);
        $out .= $this->chunk('IHDR', $ihdr);

        $hexLen = $this->minimalBigEndianHex($lengthForHeader);
        $hexLenLen = $this->minimalBigEndianHex(strlen($hexLen));
        $itxt = "license\x00\x00\x00\x00\x00{$hexLenLen} {$hexLen}";
        $out .= $this->chunk('iTXt', $itxt);

        if ($upscaleW && $upscaleH) {
            $dataForIdat = $this->bilinearUpscale($finalPixels, $gridW, $gridH, (int) $upscaleW, (int) $upscaleH);
            $idatW = (int) $upscaleW;
            $idatH = (int) $upscaleH;
        } else {
            $dataForIdat = $finalPixels;
            $idatW = $gridW;
            $idatH = $gridH;
        }

        $rowBytes = $idatW * self::BPP;
        $raw = '';
        for ($y = 0; $y < $idatH; $y++) {
            $raw .= "\x00" . substr($dataForIdat, $y * $rowBytes, $rowBytes);
        }

        $compressed = gzcompress($raw);
        if (!is_string($compressed)) {
            throw new \RuntimeException('pngspeak: zlib deflate failed');
        }

        $out .= $this->chunk('IDAT', $compressed);
        $out .= $this->chunk('IEND', '');

        return $out;
    }

    /**
     * @param array{length?: ?int, rand?: ?string} $options
     */
    public function decode(string $png, array $options = []): string
    {
        if (substr($png, 0, 8) !== self::PNG_SIG) {
            throw new \InvalidArgumentException('pngspeak: not a PNG file (bad signature)');
        }

        $offset = 8;
        $len = strlen($png);
        $width = 0;
        $height = 0;
        $idat = '';
        $headerLen = null;

        while ($offset + 8 <= $len) {
            $chunkLen = unpack('N', substr($png, $offset, 4))[1];
            $chunkType = substr($png, $offset + 4, 4);
            $chunkData = substr($png, $offset + 8, $chunkLen);
            $offset += 8 + $chunkLen + 4;

            if ($chunkType === 'IHDR') {
                $parts = unpack('Nw/Nh', $chunkData);
                $width = $parts['w'];
                $height = $parts['h'];
            } elseif ($chunkType === 'IDAT') {
                $idat .= $chunkData;
            } elseif ($chunkType === 'iTXt' && $headerLen === null) {
                $nulls = [];
                for ($i = 0; $i < strlen($chunkData) && count($nulls) < 5; $i++) {
                    if ($chunkData[$i] === "\x00") {
                        $nulls[] = $i;
                    }
                }
                if (count($nulls) >= 5 && substr($chunkData, 0, $nulls[0]) === 'license') {
                    $text = trim(substr($chunkData, $nulls[4] + 1));
                    $split = explode(' ', $text);
                    if (count($split) === 2) {
                        $headerLen = (int) hexdec($split[1]);
                    }
                }
            } elseif ($chunkType === 'IEND') {
                break;
            }
        }

        $decompressed = @gzuncompress($idat);
        if (!is_string($decompressed)) {
            throw new \InvalidArgumentException('pngspeak: failed to inflate IDAT');
        }

        $rowSize = 1 + ($width * self::BPP);
        $pixelData = '';
        for ($y = 0; $y < $height; $y++) {
            $pixelData .= substr($decompressed, $y * $rowSize + 1, $width * self::BPP);
        }

        $maxBytes = $width * $height * self::BPP;
        $target = $options['length'] ?? $headerLen;
        if ($target === null) {
            return substr($pixelData, 0, $maxBytes);
        }
        $target = (int) $target;
        if ($target <= $maxBytes) {
            return substr($pixelData, 0, $target);
        }

        return substr($pixelData, 0, $maxBytes) . $this->padBytes($target - $maxBytes, $options['rand'] ?? null);
    }

    protected function chunk(string $type, string $data): string
    {
        $crc = crc32($type . $data);
        return pack('N', strlen($data)) . $type . $data . pack('N', $crc);
    }

    protected function padBytes(int $n, ?string $randSource): string
    {
        if ($n <= 0) {
            return '';
        }
        if ($randSource !== null && $randSource !== '') {
            $repeat = (int) ceil($n / strlen($randSource));
            return substr(str_repeat($randSource, $repeat), 0, $n);
        }
        return random_bytes($n);
    }

    protected function minimalBigEndianHex(int $value): string
    {
        $hex = dechex(max(0, $value));
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }
        return $hex;
    }

    /**
     * @return array{int, int}
     */
    protected function computeGridDimensions(int $pixelsNeeded, ?int $width, ?int $height): array
    {
        if ($width === null && $height === null) {
            $w = max(1, (int) floor(sqrt($pixelsNeeded)));
            $h = max(1, (int) ceil($pixelsNeeded / $w));
            return [$w, $h];
        }
        if ($width === null) {
            $h = max(1, (int) $height);
            $w = max(1, (int) ceil($pixelsNeeded / $h));
            return [$w, $h];
        }
        if ($height === null) {
            $w = max(1, $width);
            $h = max(1, (int) ceil($pixelsNeeded / $w));
            return [$w, $h];
        }
        return [max(1, $width), max(1, $height)];
    }

    protected function bilinearUpscale(string $data, int $width, int $height, int $uw, int $uh): string
    {
        $result = str_repeat("\x00", $uw * $uh * self::BPP);

        $getPixel = function (int $x, int $y) use ($data, $width): array {
            $off = ($y * $width + $x) * self::BPP;
            return [ord($data[$off]), ord($data[$off + 1]), ord($data[$off + 2]), ord($data[$off + 3])];
        };

        for ($outY = 0; $outY < $uh; $outY++) {
            for ($outX = 0; $outX < $uw; $outX++) {
                $srcX = (($outX + 0.5) * $width) / $uw - 0.5;
                $srcY = (($outY + 0.5) * $height) / $uh - 0.5;

                $x0 = (int) $srcX;
                $y0 = (int) $srcY;
                $x1 = min($x0 + 1, $width - 1);
                $y1 = min($y0 + 1, $height - 1);
                $x0 = max(0, $x0);
                $y0 = max(0, $y0);

                $dx = $srcX - $x0;
                $dy = $srcY - $y0;

                $p00 = $getPixel($x0, $y0);
                $p10 = $getPixel($x1, $y0);
                $p01 = $getPixel($x0, $y1);
                $p11 = $getPixel($x1, $y1);

                $outOff = ($outY * $uw + $outX) * self::BPP;
                for ($c = 0; $c < self::BPP; $c++) {
                    $top = $p00[$c] * (1 - $dx) + $p10[$c] * $dx;
                    $bot = $p01[$c] * (1 - $dx) + $p11[$c] * $dx;
                    $val = (int) round($top * (1 - $dy) + $bot * $dy, 0, PHP_ROUND_HALF_EVEN);
                    $result[$outOff + $c] = chr(max(0, min(255, $val)));
                }
            }
        }

        return $result;
    }
}
