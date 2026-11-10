<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Unit;

use Refatbd\LaravelChatGptMirror\Services\BrowserPatchManager;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class BrowserPatchManagerTest extends TestCase
{
    public function test_it_strips_content_security_policy_headers(): void
    {
        $manager = new BrowserPatchManager();

        $headers = [
            'Content-Type' => ['text/html; charset=utf-8'],
            'Content-Security-Policy' => ["default-src 'self' https://chatgpt.com"],
            'content-security-policy-report-only' => ['report-uri /csp'],
            'X-Frame-Options' => ['DENY'],
        ];

        $cleaned = $manager->cleanHeaders($headers);

        $this->assertArrayHasKey('Content-Type', $cleaned);
        $this->assertArrayHasKey('X-Frame-Options', $cleaned);
        $this->assertArrayNotHasKey('Content-Security-Policy', $cleaned);
        $this->assertArrayNotHasKey('content-security-policy-report-only', $cleaned);
    }

    public function test_it_injects_codex_api_base_url_into_html(): void
    {
        $manager = new BrowserPatchManager();

        $html = '<!DOCTYPE html><html><head><title>ChatGPT</title></head><body><h1>Welcome</h1></body></html>';
        $patched = $manager->patchHtml($html);

        $this->assertStringContainsString('localStorage.setItem("codex.browser.apiBaseUrl"', $patched);
        $this->assertStringContainsString('window.fetch', $patched);
        $this->assertStringContainsString('</head>', $patched);
    }
}
