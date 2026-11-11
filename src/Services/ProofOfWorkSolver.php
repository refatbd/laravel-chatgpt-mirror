<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

/**
 * ChatGPT Web Sentinel proof-of-work solver using native PHP SHA3-512.
 *
 * Solves `sha3_512(seed + base64(JSON(config)))` until its hex prefix <= difficulty.
 */
class ProofOfWorkSolver
{
    /** @var int[] */
    private const SCREEN_BASES = [3008, 4010, 6000];

    /** @var int[] */
    private const SCREEN_MULTS = [1, 2, 4];

    /** @var string[] */
    private const REACT_PROPS = [
        '_reactListeningcfilawjnerp',
        '_reactListening9ne2dfo1i47',
        '_reactListening410nzwhan2a',
    ];

    /** @var string[] */
    private const DOM_EVENTS = ['alert', 'ontransitionend', 'onprogress'];

    /**
     * Decode an optional `gAAAAAB...~...` config hint sent by Sentinel.
     *
     * @return array<int, mixed>|null
     */
    public function decodeProofConfig(?string $proofHeader): ?array
    {
        if (!$proofHeader || !str_contains($proofHeader, 'gAAAAAB')) {
            return null;
        }

        $parts = explode('gAAAAAB', $proofHeader, 2);
        $afterMarker = $parts[1] ?? '';
        if ($afterMarker === '') {
            return null;
        }

        $encoded = explode('~', $afterMarker, 2)[0];
        $pad = (4 - (strlen($encoded) % 4)) % 4;
        if ($pad > 0) {
            $encoded .= str_repeat('=', $pad);
        }

        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded) || $decoded === '') {
            return null;
        }

        $parsed = json_decode($decoded, true);
        return is_array($parsed) && array_is_list($parsed) ? $parsed : null;
    }

    /**
     * Build a synthetic Sentinel browser fingerprint config tuple matching JS proof.ts.
     *
     * @return array<int, mixed>
     */
    public function defaultProofConfig(?string $userAgent = null): array
    {
        $screen = self::SCREEN_BASES[array_rand(self::SCREEN_BASES)] * self::SCREEN_MULTS[array_rand(self::SCREEN_MULTS)];
        $parseTime = gmdate('D, d M Y H:i:s') . ' GMT';

        return [
            $screen,
            $parseTime,
            null,
            0,
            $userAgent,
            'https://tcr9i.chat.openai.com/v2/35536E1E-65B4-4D96-9D97-6ADB7EFF8147/api.js',
            'dpl=1440a687921de39ff5ee56b92807faaadce73f13',
            'en',
            'en-US',
            null,
            "plugins\u{2212}[object PluginArray]",
            self::REACT_PROPS[array_rand(self::REACT_PROPS)],
            self::DOM_EVENTS[array_rand(self::DOM_EVENTS)],
        ];
    }

    /**
     * Solve Sentinel PoW challenge and return `gAAAAAB...` token or null when not required.
     *
     * @param array<int, mixed>|null $proofConfig
     */
    public function generateProofToken(
        bool $required,
        string $seed = '',
        string $difficulty = '',
        ?string $userAgent = null,
        ?array $proofConfig = null,
        int $maxAttempts = 100000
    ): ?string {
        if (!$required) {
            return null;
        }

        $difficulty = strtolower(trim($difficulty));
        if ($seed === '' || $difficulty === '' || !preg_match('/^[0-9a-f]+$/', $difficulty)) {
            throw new \InvalidArgumentException('Invalid required proof-of-work challenge');
        }

        $proof = $proofConfig !== null ? array_values($proofConfig) : $this->defaultProofConfig($userAgent);
        $difficultyLen = strlen($difficulty);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $proof[3] = $attempt;
            // Match JavaScript JSON.stringify exactly (no \/ or \u2212 escaping).
            $proofJson = json_encode($proof, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $proofBase = base64_encode((string) $proofJson);
            $hashValue = hash('sha3-512', $seed . $proofBase);

            if (strncmp($hashValue, $difficulty, $difficultyLen) <= 0) {
                return 'gAAAAAB' . $proofBase;
            }
        }

        $fallbackBase = base64_encode(json_encode($seed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return 'gAAAAABwQ8Lk5FbGpA2NcR9dShT6gYjU7VxZ4D' . $fallbackBase;
    }
}
