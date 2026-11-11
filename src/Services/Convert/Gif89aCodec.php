<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services\Convert;

/**
 * Pure-PHP non-interlaced multi-frame GIF89a reader/writer with GIF LZW codec.
 */
class Gif89aCodec
{
    /**
     * @param array<int, array{r: int, g: int, b: int}> $globalColorTable
     * @param array<int, array{pixels: array<int, int>|string, delayCs?: int}> $frames
     */
    public function writeGif(int $width, int $height, array $globalColorTable, array $frames): string
    {
        $tableCount = max(2, count($globalColorTable));
        $colorBits = 1;
        while ((1 << $colorBits) < $tableCount) {
            $colorBits++;
        }
        $paddedSize = 1 << $colorBits;

        $out = 'GIF89a';
        $packed = 0x80 | (($colorBits - 1) << 4) | ($colorBits - 1);
        $out .= pack('vvCCC', $width, $height, $packed, 0, 0);

        for ($i = 0; $i < $paddedSize; $i++) {
            $c = $globalColorTable[$i] ?? ['r' => 0, 'g' => 0, 'b' => 0];
            $out .= chr($c['r'] & 0xff) . chr($c['g'] & 0xff) . chr($c['b'] & 0xff);
        }

        // NETSCAPE2.0 infinite loop application block
        $out .= "\x21\xff\x0bNETSCAPE2.0\x03\x01\x00\x00\x00";

        $minCodeSize = max(2, $colorBits);

        foreach ($frames as $frame) {
            $delay = (int) ($frame['delayCs'] ?? 0);
            $out .= "\x21\xf9\x04\x00" . pack('v', $delay) . "\x00\x00";
            $out .= "\x2c" . pack('vvvvC', 0, 0, $width, $height, 0);
            $out .= chr($minCodeSize);
            $compressed = $this->lzwEncode($frame['pixels'], $minCodeSize);
            $out .= $this->writeSubBlocks($compressed);
        }

        return $out . "\x3b";
    }

    /**
     * @return array{
     *   width: int,
     *   height: int,
     *   globalColorTable: array<int, array{r: int, g: int, b: int}>,
     *   frames: array<int, array{pixels: array<int, int>, delayCs: int}>
     * }
     */
    public function readGif(string $buf): array
    {
        if (substr($buf, 0, 3) !== 'GIF') {
            throw new \InvalidArgumentException('gif: not a GIF file (bad signature)');
        }

        $header = unpack('vwidth/vheight/Cpacked', substr($buf, 6, 5));
        $width = $header['width'];
        $height = $header['height'];
        $packed = $header['packed'];
        $hasGct = ($packed & 0x80) !== 0;
        $colorBits = ($packed & 0x07) + 1;
        $gctSize = $hasGct ? (1 << $colorBits) : 0;

        $offset = 13;
        $gct = [];
        for ($i = 0; $i < $gctSize; $i++) {
            $gct[] = [
                'r' => ord($buf[$offset]),
                'g' => ord($buf[$offset + 1]),
                'b' => ord($buf[$offset + 2]),
            ];
            $offset += 3;
        }

        $frames = [];
        $pendingDelay = 0;
        $len = strlen($buf);

        while ($offset < $len) {
            $marker = ord($buf[$offset]);
            if ($marker === 0x3b) {
                break;
            }
            if ($marker === 0x21) {
                $label = ord($buf[$offset + 1]);
                if ($label === 0xf9) {
                    $pendingDelay = unpack('v', substr($buf, $offset + 4, 2))[1];
                    $offset += 8;
                } else {
                    $offset += 2;
                    [, $offset] = $this->readSubBlocks($buf, $offset);
                }
                continue;
            }
            if ($marker === 0x2c) {
                $desc = unpack('vleft/vtop/vw/vh/Cp', substr($buf, $offset + 1, 9));
                $offset += 10;
                if (($desc['p'] & 0x80) !== 0) {
                    $localBits = ($desc['p'] & 0x07) + 1;
                    $offset += (1 << $localBits) * 3;
                }
                $minCodeSize = ord($buf[$offset]);
                $offset += 1;
                [$subData, $offset] = $this->readSubBlocks($buf, $offset);
                $pixels = $this->lzwDecode($subData, $minCodeSize, $desc['w'] * $desc['h']);
                $frames[] = ['pixels' => $pixels, 'delayCs' => $pendingDelay];
                $pendingDelay = 0;
                continue;
            }

            throw new \InvalidArgumentException(sprintf('gif: unexpected block marker 0x%x at offset %d', $marker, $offset));
        }

        return [
            'width' => $width,
            'height' => $height,
            'globalColorTable' => $gct,
            'frames' => $frames,
        ];
    }

    /**
     * @param array<int, int>|string $pixels
     */
    protected function lzwEncode(array|string $pixels, int $minCodeSize): string
    {
        $clearCode = 1 << $minCodeSize;
        $endCode = $clearCode + 1;
        $maxCodeBits = 12;
        $maxDictSize = 1 << $maxCodeBits;

        $bytes = '';
        $bitBuffer = 0;
        $bitCount = 0;

        $writeCode = function (int $code, int $width) use (&$bytes, &$bitBuffer, &$bitCount): void {
            $bitBuffer |= ($code << $bitCount);
            $bitCount += $width;
            while ($bitCount >= 8) {
                $bytes .= chr($bitBuffer & 0xff);
                $bitBuffer >>= 8;
                $bitCount -= 8;
            }
        };

        $codeSize = $minCodeSize + 1;
        $nextCode = $endCode + 1;
        $writeCode($clearCode, $codeSize);

        $firstSinceReset = true;
        $count = is_string($pixels) ? strlen($pixels) : count($pixels);
        for ($i = 0; $i < $count; $i++) {
            $val = is_string($pixels) ? ord($pixels[$i]) : (int) $pixels[$i];
            $writeCode($val, $codeSize);

            if (!$firstSinceReset) {
                if ($nextCode < $maxDictSize) {
                    $nextCode++;
                    if ($nextCode >= (1 << $codeSize) && $codeSize < $maxCodeBits) {
                        $codeSize++;
                    }
                } else {
                    $writeCode($clearCode, $codeSize);
                    $nextCode = $endCode + 1;
                    $codeSize = $minCodeSize + 1;
                    $firstSinceReset = true;
                    continue;
                }
            }
            $firstSinceReset = false;
        }

        $writeCode($endCode, $codeSize);
        if ($bitCount > 0) {
            $bytes .= chr($bitBuffer & 0xff);
        }

        return $bytes;
    }

    /**
     * @return array<int, int>
     */
    protected function lzwDecode(string $data, int $minCodeSize, int $expectedPixelCount): array
    {
        $clearCode = 1 << $minCodeSize;
        $endCode = $clearCode + 1;
        $maxCodeBits = 12;
        $maxDictSize = 1 << $maxCodeBits;

        $pos = 0;
        $len = strlen($data);
        $bitBuffer = 0;
        $bitCount = 0;

        $readCode = function (int $width) use ($data, $len, &$pos, &$bitBuffer, &$bitCount): ?int {
            $ranOut = false;
            while ($bitCount < $width) {
                if ($pos >= $len) {
                    if ($bitCount === 0) {
                        return null;
                    }
                    $ranOut = true;
                    break;
                }
                $bitBuffer |= (ord($data[$pos]) << $bitCount);
                $bitCount += 8;
                $pos++;
            }
            $mask = (1 << $width) - 1;
            $code = $bitBuffer & $mask;
            $bitBuffer >>= $width;
            $bitCount = $ranOut ? 0 : ($bitCount - $width);
            return $code;
        };

        $out = [];
        $dict = [];
        $codeSize = $minCodeSize + 1;
        $nextCode = $endCode + 1;

        $reset = function () use ($clearCode, $endCode, $minCodeSize, &$dict, &$nextCode, &$codeSize): void {
            $dict = [];
            for ($i = 0; $i < $clearCode; $i++) {
                $dict[] = [$i];
            }
            $dict[] = [];
            $dict[] = [];
            $nextCode = $endCode + 1;
            $codeSize = $minCodeSize + 1;
        };

        $reset();
        $previous = null;

        while (true) {
            $code = $readCode($codeSize);
            if ($code === null || $code === $endCode) {
                break;
            }
            if ($code === $clearCode) {
                $reset();
                $previous = null;
                continue;
            }
            if ($code < count($dict) && $dict[$code] !== []) {
                $entry = $dict[$code];
            } elseif ($code === $nextCode && $previous !== null) {
                $entry = $previous;
                $entry[] = $previous[0];
            } else {
                throw new \InvalidArgumentException("gif: invalid LZW code {$code}");
            }

            foreach ($entry as $val) {
                if (count($out) < $expectedPixelCount) {
                    $out[] = $val;
                }
            }

            if ($previous !== null && $nextCode < $maxDictSize) {
                $newEntry = $previous;
                $newEntry[] = $entry[0];
                $dict[$nextCode] = $newEntry;
                $nextCode++;
                if ($nextCode >= (1 << $codeSize) && $codeSize < $maxCodeBits) {
                    $codeSize++;
                }
            }
            $previous = $entry;
        }

        while (count($out) < $expectedPixelCount) {
            $out[] = 0;
        }

        return $out;
    }

    protected function writeSubBlocks(string $data): string
    {
        $out = '';
        $len = strlen($data);
        $offset = 0;
        while ($offset < $len) {
            $size = min(255, $len - $offset);
            $out .= chr($size) . substr($data, $offset, $size);
            $offset += $size;
        }
        return $out . "\x00";
    }

    /**
     * @return array{string, int}
     */
    protected function readSubBlocks(string $buf, int $offset): array
    {
        $data = '';
        $pos = $offset;
        $total = strlen($buf);
        while ($pos < $total) {
            $size = ord($buf[$pos]);
            $pos++;
            if ($size === 0) {
                break;
            }
            $data .= substr($buf, $pos, $size);
            $pos += $size;
        }
        return [$data, $pos];
    }
}
