<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Refatbd\LaravelChatGptMirror\Services\ProofOfWorkSolver;

class ProofOfWorkSolverTest extends TestCase
{
    public function test_decode_proof_config_extracts_tuple(): void
    {
        $solver = new ProofOfWorkSolver();
        $tuple = [1920, 'Tue, 11 Nov 2026 00:00:00 GMT', null, 0, 'Mozilla/5.0'];
        $header = 'gAAAAAB' . base64_encode((string) json_encode($tuple)) . '~trailing-sig';

        $decoded = $solver->decodeProofConfig($header);

        $this->assertSame($tuple, $decoded);
        $this->assertNull($solver->decodeProofConfig(null));
        $this->assertNull($solver->decodeProofConfig('invalid-proof'));
    }

    public function test_generate_proof_token_returns_null_when_not_required(): void
    {
        $solver = new ProofOfWorkSolver();
        $this->assertNull($solver->generateProofToken(false, 'seed-123', '0fff'));
    }

    public function test_generate_proof_token_solves_sha3_512_challenge_within_difficulty(): void
    {
        $solver = new ProofOfWorkSolver();
        $seed = '0.918273645';
        $difficulty = '0fff';
        $token = $solver->generateProofToken(true, $seed, $difficulty, 'MirrorTestAgent/1.0');

        $this->assertNotNull($token);
        $this->assertStringStartsWith('gAAAAAB', $token);

        $base = substr($token, 7);
        $hash = hash('sha3-512', $seed . $base);
        $this->assertLessThanOrEqual(0, strncmp($hash, $difficulty, strlen($difficulty)));

        $decoded = json_decode((string) base64_decode($base, true), true);
        $this->assertIsArray($decoded);
        $this->assertSame('MirrorTestAgent/1.0', $decoded[4]);
    }

    public function test_generate_proof_token_throws_on_empty_seed_when_required(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProofOfWorkSolver())->generateProofToken(true, '', '0fff');
    }
}
