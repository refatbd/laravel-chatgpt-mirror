<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Unit;

use Refatbd\LaravelChatGptMirror\Services\TokenInputParser;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class TokenInputParserTest extends TestCase
{
    private function makeJwt(array $payload): string
    {
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256', 'typ' => 'JWT'])
            . '.'
            . $encode($payload)
            . '.sig1234567890';
    }

    public function test_extracts_full_chatgpt_api_auth_session_json_like_token_txt(): void
    {
        $parser = new TokenInputParser();
        $expTs = time() + 7200;
        $jwt = $this->makeJwt([
            'iss' => 'https://auth.openai.com/',
            'exp' => $expTs,
            'https://api.openai.com/auth' => ['user_id' => 'user-synthetic-1'],
        ]);

        $bundle = json_encode([
            'user' => [
                'id' => 'user-synthetic-1',
                'name' => 'Synthetic Tester',
                'email' => 'synthetic@example.com',
                'idp' => 'auth0',
                'iat' => time(),
                'mfa' => false,
            ],
            'expires' => '2027-02-08T02:07:02.327Z',
            'account' => [
                'id' => 'acct-synthetic-99',
                'planType' => 'Plus',
                'structure' => 'personal',
            ],
            'accessToken' => $jwt,
            'sessionToken' => 'eyJhbGciOiJkaXIifQ.part2.part3.part4.session_sig_' . str_repeat('x', 40),
            'authProvider' => 'openai',
            'statsigContext' => ['tier' => 'prod'],
            'marketingEligibility' => ['eligible' => false],
            'WARNING_BANNER' => 'Never share your session token',
        ], JSON_UNESCAPED_SLASHES);

        // Wrap in UTF-8 BOM + Markdown code fence to test noisy copy-paste resilience
        $noisyPaste = "\xEF\xBB\xBF```json\n{$bundle}\n```";
        $parsed = $parser->parse($noisyPaste);

        $this->assertNotNull($parsed);
        $this->assertSame('session_json', $parsed['source_format']);
        $this->assertStringStartsWith('eyJhbGciOiJkaXIifQ.', (string) $parsed['session_token']);
        $this->assertSame($jwt, $parsed['access_token']);
        $this->assertSame('synthetic@example.com', $parsed['email']);
        $this->assertSame('Synthetic Tester', $parsed['name']);
        $this->assertSame('acct-synthetic-99', $parsed['account_id']);
        $this->assertSame('plus', $parsed['plan_type']);
        $this->assertSame($expTs, $parsed['expires_at']?->getTimestamp());
    }

    public function test_extracts_cookie_headers_curl_netscape_and_browser_cookie_exports(): void
    {
        $parser = new TokenInputParser();
        $expectedToken = str_repeat('TokChunkAlpha_', 3);

        // 1. Raw Cookie header with chunked NextAuth cookies (.0 and .1) and Authorization header
        $jwt = $this->makeJwt([
            'iss' => 'https://auth.openai.com/',
            'exp' => time() + 3600,
            'https://api.openai.com/auth' => ['user_id' => 'u1'],
        ]);
        $chunk0 = substr($expectedToken, 0, 20);
        $chunk1 = substr($expectedToken, 20);
        $curl = "curl 'https://chatgpt.com/backend-api/me' -H 'authorization: Bearer {$jwt}' -H 'cookie: oai-did=did-1; __Secure-next-auth.session-token.0={$chunk0}; __Secure-next-auth.session-token.1={$chunk1}; __cf_bm=ignore'";
        $fromCurl = $parser->parse($curl);
        $this->assertNotNull($fromCurl);
        $this->assertSame('curl', $fromCurl['source_format']);
        $this->assertSame($expectedToken, $fromCurl['session_token']);
        $this->assertSame($jwt, $fromCurl['access_token']);

        // 2. Playwright / Cookie-Editor JSON export
        $cookieJson = json_encode([
            'cookies' => [
                ['name' => 'oai-did', 'value' => 'did-1'],
                ['name' => '__Secure-next-auth.session-token.0', 'value' => $chunk0, 'expirationDate' => time() + 5000],
                ['name' => '__Secure-next-auth.session-token.1', 'value' => $chunk1],
            ],
        ]);
        $fromCookieJson = $parser->parse((string) $cookieJson);
        $this->assertNotNull($fromCookieJson);
        $this->assertSame('cookie_json', $fromCookieJson['source_format']);
        $this->assertSame($expectedToken, $fromCookieJson['session_token']);

        // 3. Netscape cookies.txt tab-separated export
        $expTs = time() + 9000;
        $netscape = "# Netscape HTTP Cookie File\n.chatgpt.com\tTRUE\t/\tTRUE\t{$expTs}\t__Secure-next-auth.session-token\t{$expectedToken}\n";
        $fromNetscape = $parser->parse($netscape);
        $this->assertNotNull($fromNetscape);
        $this->assertSame('netscape_cookies', $fromNetscape['source_format']);
        $this->assertSame($expectedToken, $fromNetscape['session_token']);
        $this->assertSame($expTs, $fromNetscape['expires_at']?->getTimestamp());
    }

    public function test_parses_bulk_json_arrays_multiline_cookie_files_and_static_env_safely(): void
    {
        $parser = new TokenInputParser();

        $jsonArray = json_encode([
            ['sessionToken' => 'sess_bulk_one_' . str_repeat('a', 20), 'user' => ['email' => 'one@example.com']],
            ['sessionToken' => 'sess_bulk_two_' . str_repeat('b', 20), 'user' => ['email' => 'two@example.com']],
            ['sessionToken' => 'sess_bulk_one_' . str_repeat('a', 20)], // duplicate stripped automatically
        ]);

        $many = $parser->parseMany((string) $jsonArray);
        $this->assertCount(2, $many);
        $this->assertSame('one@example.com', $many[0]['email']);
        $this->assertSame('two@example.com', $many[1]['email']);

        // MIRROR_SESSION_TOKENS containing JSON commas must NOT get shredded
        $singleJsonEnv = json_encode([
            'sessionToken' => 'env_json_session_' . str_repeat('z', 20),
            'user' => ['name' => 'Comma, In Name', 'email' => 'env@example.com'],
        ]);
        $staticExtracted = $parser->parseStaticTokens((string) $singleJsonEnv);
        $this->assertSame(['env_json_session_' . str_repeat('z', 20)], $staticExtracted);

        // Classic comma-separated MIRROR_SESSION_TOKENS still splits cleanly
        $this->assertSame(['tok_alpha_12345', 'tok_beta_67890'], $parser->parseStaticTokens('tok_alpha_12345, tok_beta_67890'));
    }

    public function test_distinguishes_access_token_only_jwt_when_session_token_is_missing(): void
    {
        $parser = new TokenInputParser();
        $jwtOnly = $this->makeJwt([
            'iss' => 'https://auth.openai.com/',
            'exp' => time() + 1800,
            'https://api.openai.com/auth' => ['user_id' => 'only-access'],
        ]);

        $parsed = $parser->parse($jwtOnly);
        $this->assertNotNull($parsed);
        $this->assertNull($parsed['session_token']);
        $this->assertSame($jwtOnly, $parsed['access_token']);
        $this->assertSame('raw_access_token', $parsed['source_format']);
    }
}
