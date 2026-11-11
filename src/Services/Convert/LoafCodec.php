<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services\Convert;

/**
 * Pure-PHP implementation of LoaF ("Linear Object Archive Format"):
 * single-line SHA-256 checksummed hex-encoded gzip USTAR archive.
 */
class LoafCodec
{
    private const HEADER_PATTERN = '/^SHA256\(-\)=([0-9A-Fa-f]{64}) ([0-9A-Fa-f]*)$/';
    private const BLOCK_SIZE = 512;

    /**
     * @param array<int, array{name: string, content?: string, mode?: int, mtime?: int, linkTarget?: string}> $entries
     */
    public function makeLoaf(array $entries): string
    {
        $tarBytes = $this->tar($entries);
        $gzipped = gzencode($tarBytes, 9);
        if (!is_string($gzipped)) {
            throw new \RuntimeException('loaf: failed gzip compression');
        }

        $hex = bin2hex($gzipped);
        $hash = hash('sha256', $hex);

        return "SHA256(-)=$hash $hex";
    }

    /**
     * @return array{ok: bool, expectedHash: string, actualHash: string}
     */
    public function verifyLoaf(string $loaf): array
    {
        ['hash' => $hash, 'hex' => $hex] = $this->parseLoaf($loaf);
        $actualHash = hash('sha256', $hex);

        return [
            'ok' => $actualHash === $hash,
            'expectedHash' => $hash,
            'actualHash' => $actualHash,
        ];
    }

    /**
     * @return array<int, array{name: string, content: string, isDirectory: bool, isSymlink: bool, linkTarget: ?string}>
     */
    public function extractLoaf(string $loaf, bool $verify = false): array
    {
        ['hash' => $hash, 'hex' => $hex] = $this->parseLoaf($loaf);
        if ($verify) {
            $actual = hash('sha256', $hex);
            if ($actual !== $hash) {
                throw new \InvalidArgumentException("loaf: checksum mismatch (expected {$hash}, got {$actual})");
            }
        }

        if (strlen($hex) % 2 !== 0) {
            throw new \InvalidArgumentException('loaf: hex payload has odd length');
        }

        $gzipped = @hex2bin($hex);
        if (!is_string($gzipped)) {
            throw new \InvalidArgumentException('loaf: invalid hex payload');
        }

        $tarBytes = @gzdecode($gzipped);
        if (!is_string($tarBytes)) {
            throw new \InvalidArgumentException('loaf: invalid gzip payload');
        }

        return $this->untar($tarBytes);
    }

    /**
     * @return array{hash: string, hex: string}
     */
    protected function parseLoaf(string $loaf): array
    {
        $trimmed = trim($loaf);
        if (str_contains($trimmed, "\n") || str_contains($trimmed, "\r")) {
            throw new \InvalidArgumentException('loaf: not a valid .loaf (must be a single line, found embedded newline(s))');
        }

        if (!preg_match(self::HEADER_PATTERN, $trimmed, $m)) {
            throw new \InvalidArgumentException("loaf: not a valid .loaf (expected 'SHA256(-)=<hash> <hex>')");
        }

        return ['hash' => strtolower($m[1]), 'hex' => $m[2]];
    }

    /**
     * @param array<int, array{name: string, content?: string, mode?: int, mtime?: int, linkTarget?: string}> $entries
     */
    protected function tar(array $entries): string
    {
        $out = '';

        foreach ($entries as $entry) {
            $linkTarget = $entry['linkTarget'] ?? null;
            $isSymlink = $linkTarget !== null;
            $rawName = (string) $entry['name'];
            $isDirectory = !$isSymlink && (!array_key_exists('content', $entry) || $entry['content'] === null || str_ends_with($rawName, '/'));
            $name = ($isDirectory && !str_ends_with($rawName, '/')) ? ($rawName . '/') : $rawName;
            $content = ($isDirectory || $isSymlink) ? '' : (string) ($entry['content'] ?? '');
            $typeflag = $isSymlink ? '2' : ($isDirectory ? '5' : '0');

            $lastSlash = strrpos($name, '/');
            $prefix = $lastSlash !== false ? substr($name, 0, $lastSlash) : '';
            $shortName = $lastSlash !== false ? substr($name, $lastSlash + 1) : $name;
            $fitsUstar = strlen($name) <= 100 || ($lastSlash !== false && strlen($shortName) <= 100 && strlen($prefix) <= 155);

            if (!$fitsUstar) {
                $out .= $this->buildLongNameBlock($name, 'L');
            }
            if ($isSymlink && strlen((string) $linkTarget) > 100) {
                $out .= $this->buildLongNameBlock((string) $linkTarget, 'K');
            }

            $out .= $this->buildHeader(
                $name,
                strlen($content),
                $entry['mode'] ?? ($isDirectory ? 0755 : 0644),
                $entry['mtime'] ?? time(),
                $typeflag,
                $linkTarget
            );

            if (!$isDirectory && !$isSymlink && $content !== '') {
                $out .= $this->padBlock($content);
            }
        }

        return $out . str_repeat("\0", self::BLOCK_SIZE * 2);
    }

    protected function buildLongNameBlock(string $value, string $typeflag): string
    {
        $content = $value . "\0";
        $header = $this->buildHeader('././@LongLink', strlen($content), 0644, 0, $typeflag, null);
        return $header . $this->padBlock($content);
    }

    protected function buildHeader(string $name, int $size, int $mode, int $mtime, string $typeflag, ?string $linkname): string
    {
        $header = str_repeat("\0", self::BLOCK_SIZE);

        if (strlen($name) > 100) {
            $lastSlash = strrpos($name, '/');
            $prefix = $lastSlash !== false ? substr($name, 0, $lastSlash) : '';
            $shortName = $lastSlash !== false ? substr($name, $lastSlash + 1) : $name;
            if ($lastSlash !== false && strlen($shortName) <= 100 && strlen($prefix) <= 155) {
                $header = substr_replace($header, str_pad(substr($prefix, 0, 155), 155, "\0"), 345, 155);
                $header = substr_replace($header, str_pad(substr($shortName, 0, 100), 100, "\0"), 0, 100);
            } else {
                $header = substr_replace($header, substr($name, 0, 100), 0, 100);
            }
        } else {
            $header = substr_replace($header, str_pad($name, 100, "\0"), 0, 100);
        }

        $header = substr_replace($header, $this->octal($mode, 8), 100, 8);
        $header = substr_replace($header, $this->octal(0, 8), 108, 8);
        $header = substr_replace($header, $this->octal(0, 8), 116, 8);
        $header = substr_replace($header, $this->octal($size, 12), 124, 12);
        $header = substr_replace($header, $this->octal(max(0, $mtime), 12), 136, 12);
        $header = substr_replace($header, '        ', 148, 8);
        $header = substr_replace($header, $typeflag, 156, 1);

        if ($linkname !== null) {
            $truncated = substr($linkname, 0, 100);
            $header = substr_replace($header, str_pad($truncated, 100, "\0"), 157, 100);
        }

        $header = substr_replace($header, "ustar\0", 257, 6);
        $header = substr_replace($header, '00', 263, 2);

        $checksum = 0;
        for ($i = 0; $i < self::BLOCK_SIZE; $i++) {
            $checksum += ord($header[$i]);
        }

        $header = substr_replace($header, $this->octal($checksum, 8), 148, 8);
        return $header;
    }

    /**
     * @return array<int, array{name: string, content: string, isDirectory: bool, isSymlink: bool, linkTarget: ?string}>
     */
    protected function untar(string $buffer): array
    {
        $entries = [];
        $offset = 0;
        $len = strlen($buffer);
        $pendingLongName = null;
        $pendingLongLink = null;
        $zeroBlock = str_repeat("\0", self::BLOCK_SIZE);

        while ($offset + self::BLOCK_SIZE <= $len) {
            $header = substr($buffer, $offset, self::BLOCK_SIZE);
            if ($header === $zeroBlock) {
                break;
            }

            $typeflag = $header[156];
            $size = $this->readOctal(substr($header, 124, 12));
            $offset += self::BLOCK_SIZE;

            $content = substr($buffer, $offset, $size);
            $paddedSize = (int) (ceil($size / self::BLOCK_SIZE) * self::BLOCK_SIZE);
            $offset += $paddedSize;

            if ($typeflag === 'L') {
                $pendingLongName = rtrim($content, "\0");
                continue;
            }
            if ($typeflag === 'K') {
                $pendingLongLink = rtrim($content, "\0");
                continue;
            }

            $prefix = explode("\0", substr($header, 345, 155), 2)[0];
            $shortName = explode("\0", substr($header, 0, 100), 2)[0];
            $name = $pendingLongName ?? ($prefix !== '' ? "{$prefix}/{$shortName}" : $shortName);
            $isSymlink = $typeflag === '2';
            $linkTarget = $isSymlink
                ? ($pendingLongLink ?? explode("\0", substr($header, 157, 100), 2)[0])
                : null;

            $pendingLongName = null;
            $pendingLongLink = null;

            $entries[] = [
                'name' => $name,
                'content' => $content,
                'isDirectory' => $typeflag === '5',
                'isSymlink' => $isSymlink,
                'linkTarget' => $linkTarget,
            ];
        }

        return $entries;
    }

    protected function padBlock(string $data): string
    {
        $rem = strlen($data) % self::BLOCK_SIZE;
        return $rem === 0 ? $data : $data . str_repeat("\0", self::BLOCK_SIZE - $rem);
    }

    protected function octal(int $value, int $width): string
    {
        return str_pad(decoct($value), $width - 1, '0', STR_PAD_LEFT) . "\0";
    }

    protected function readOctal(string $field): int
    {
        $clean = trim(explode("\0", $field, 2)[0]);
        return $clean === '' ? 0 : (int) octdec($clean);
    }
}
