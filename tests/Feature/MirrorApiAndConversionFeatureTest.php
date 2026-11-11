<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Feature;

use Refatbd\LaravelChatGptMirror\Models\MirrorMessage;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\AssetTicketService;
use Refatbd\LaravelChatGptMirror\Services\ChatGptClient;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class MirrorApiAndConversionFeatureTest extends TestCase
{
    public function test_conversations_crud_search_route_ordering_branch_export_and_stop(): void
    {
        $headers = ['Authorization' => 'Bearer test-mirror-key-12345'];

        $created = $this->withHeaders($headers)->postJson('/api/conversations', [
            'model' => 'gpt-4o',
        ]);
        $created->assertStatus(201);
        $convId = (string) $created->json('id');
        $this->assertNotEmpty($convId);

        MirrorMessage::create([
            'id' => 'm-user-1',
            'conversation_public_id' => $convId,
            'role' => 'user',
            'content' => 'Explain Bangladeshi river delta ecology',
        ]);
        $assistantMsg = MirrorMessage::create([
            'id' => 'm-asst-1',
            'conversation_public_id' => $convId,
            'upstream_node_id' => 'up-node-1',
            'role' => 'assistant',
            'content' => 'The Bengal delta is formed by the Padma, Jamuna, and Meghna.',
            'status' => 'done',
        ]);

        // /api/conversations/search MUST match search route instead of {id}
        $search = $this->withHeaders($headers)->getJson('/api/conversations/search?q=Padma');
        $search->assertStatus(200);
        $this->assertNotEmpty($search->json('items'));
        $this->assertSame($convId, $search->json('items.0.id'));

        $show = $this->withHeaders($headers)->getJson("/api/conversations/{$convId}");
        $show->assertStatus(200);
        $this->assertCount(2, $show->json('messages'));

        $patch = $this->withHeaders($headers)->patchJson("/api/conversations/{$convId}", ['model' => 'o3-mini']);
        $patch->assertStatus(200);
        $patch->assertJsonPath('model', 'o3-mini');

        $branch = $this->withHeaders($headers)->postJson("/api/conversations/{$convId}/branch", [
            'messageId' => $assistantMsg->id,
            'title' => 'Delta Follow-up Branch',
        ]);
        $branch->assertStatus(201);
        $branch->assertJsonPath('isBranch', true);

        $branches = $this->withHeaders($headers)->getJson("/api/conversations/{$convId}/branches");
        $branches->assertStatus(200);
        $this->assertCount(1, $branches->json('branches'));

        $md = $this->withHeaders($headers)->get("/api/conversations/{$convId}/export?format=markdown", $headers);
        $md->assertStatus(200);
        $this->assertStringContainsString('text/markdown', (string) $md->headers->get('Content-Type'));
        $this->assertStringContainsString('Padma, Jamuna, and Meghna', (string) $md->getContent());

        $stop = $this->withHeaders($headers)->postJson("/api/conversations/{$convId}/stop");
        $stop->assertStatus(200);
        $stop->assertJsonPath('ok', true);

        $del = $this->withHeaders($headers)->deleteJson("/api/conversations/{$convId}");
        $del->assertStatus(200);
        $del->assertJsonPath('ok', true);
    }

    public function test_signed_asset_content_enforces_hmac_signature_and_rotation(): void
    {
        MirrorToken::create([
            'name' => 'Asset Token',
            'account_id' => 'acct-asset',
            'session_token' => 'asset-session-1234567890',
            'access_token' => 'asset-access-token',
            'status' => 'active',
        ]);

        $mockClient = $this->createMock(ChatGptClient::class);
        $mockClient->method('resolveAssetDownloadMetadata')->willReturn([
            'download_url' => 'https://files.oaiusercontent.com/signed-chart.png',
            'file_name' => 'chart.png',
        ]);
        $mockClient->method('fetchAssetBytes')->willReturn([
            'body' => 'FAKE-PNG-BYTES',
            'contentType' => 'image/png',
        ]);
        $this->app->instance(ChatGptClient::class, $mockClient);

        /** @var AssetTicketService $tickets */
        $tickets = $this->app->make(AssetTicketService::class);
        /** @var SecurityService $security */
        $security = $this->app->make(SecurityService::class);

        $links = $tickets->createSignedLinks('http://localhost', 'sediment://file_abc', null, null, 'acct-asset', 'chart.png', true);

        // Tampered sig rejected
        $this->get('/api/asset-content?ticket=' . urlencode($links['ticket']) . '&sig=bad')->assertStatus(403);

        // Valid ticket streams asset content
        $ok = $this->get('/api/asset-content?ticket=' . urlencode($links['ticket']) . '&sig=' . urlencode($links['sig']));
        $ok->assertStatus(200);
        $this->assertSame('FAKE-PNG-BYTES', $ok->getContent());

        // Rotating link generation invalidates ticket immediately
        $security->rotateAssetLinkGeneration();
        $this->get('/api/asset-content?ticket=' . urlencode($links['ticket']) . '&sig=' . urlencode($links['sig']))->assertStatus(403);
    }

    public function test_conversion_endpoints_and_decoder_challenge_lifecycle(): void
    {
        $headers = ['Authorization' => 'Bearer test-mirror-key-12345'];

        $this->withHeaders($headers)->getJson('/api/convert/formats')
            ->assertStatus(200)
            ->assertJsonCount(4, 'formats');

        $sample = "Bangladesh Mirror Test 2026!\n";
        $b64 = base64_encode($sample);

        $rt = $this->withHeaders($headers)->postJson('/api/convert/roundtrip', [
            'format' => 'pngspeak',
            'dataBase64' => $b64,
        ]);
        $rt->assertStatus(200);
        $rt->assertJsonPath('ok', true);

        $onionEnc = $this->withHeaders($headers)->postJson('/api/convert/onion/encode', [
            'chain' => ['loaf', 'pngspeak'],
            'dataBase64' => $b64,
        ]);
        $onionEnc->assertStatus(200);
        $onionEnc->assertJsonPath('format', 'pngspeak');

        $onionDec = $this->withHeaders($headers)->postJson('/api/convert/onion/decode', [
            'chain' => ['loaf', 'pngspeak'],
            'dataBase64' => (string) $onionEnc->json('dataBase64'),
        ]);
        $onionDec->assertStatus(200);
        $this->assertSame($b64, $onionDec->json('dataBase64'));

        $gauntlet = $this->withHeaders($headers)->postJson('/api/convert/gauntlet', ['dataBase64' => $b64]);
        $gauntlet->assertStatus(200);
        $resultsByFmt = [];
        foreach ((array) $gauntlet->json('results') as $row) {
            $resultsByFmt[$row['format']] = $row;
        }
        $this->assertTrue($resultsByFmt['loaf']['ok']);
        $this->assertTrue($resultsByFmt['pngspeak']['ok']);
        $this->assertTrue($resultsByFmt['gptgif-v4']['ok']);

        // Decoder challenge: create -> kit -> artifact -> verify
        $challenge = $this->withHeaders($headers)->postJson('/api/decoder-challenges', [
            'format' => 'loaf',
            'guidance' => 'guided',
            'payload' => 'text',
        ]);
        $challenge->assertStatus(200);
        $challengeId = (string) $challenge->json('id');
        $this->assertNotEmpty($challengeId);

        $artifact = $this->withHeaders($headers)->get("/api/decoder-challenges/{$challengeId}/artifact", $headers);
        $artifact->assertStatus(200);

        $kit = $this->withHeaders($headers)->post('/api/decoder-challenges/kit', ['ids' => [$challengeId]], $headers);
        $kit->assertStatus(200);
        $this->assertStringContainsString('application/gzip', (string) $kit->headers->get('Content-Type'));

        // Wrong answer fails verification
        $wrong = $this->withHeaders($headers)->postJson("/api/decoder-challenges/{$challengeId}/verify", [
            'hex' => 'deadbeef',
        ]);
        $wrong->assertStatus(200);
        $this->assertNotSame('exact', $wrong->json('verdict'));

        // Recover true answer via /api/convert/loaf/decode and submit to verify!
        $decLoaf = $this->withHeaders($headers)->postJson('/api/convert/loaf/decode', [
            'loaf' => (string) $artifact->getContent(),
            'verify' => true,
        ]);
        $decLoaf->assertStatus(200);
        $expectedB64 = (string) $decLoaf->json('entries.0.contentBase64');
        $expectedHex = bin2hex((string) base64_decode($expectedB64, true));

        $right = $this->withHeaders($headers)->postJson("/api/decoder-challenges/{$challengeId}/verify", [
            'hex' => $expectedHex,
        ]);
        $right->assertStatus(200);
        $right->assertJsonPath('verdict', 'exact');
    }
}
