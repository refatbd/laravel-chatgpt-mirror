<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Unit;

use Refatbd\LaravelChatGptMirror\Services\BrowserPatchManager;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class BrowserPatchManagerTest extends TestCase
{
    public function test_it_strips_content_security_policy_headers_and_preserves_sameorigin_contract(): void
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

    public function test_it_injects_early_patch_script_without_settings_overlay(): void
    {
        $manager = new BrowserPatchManager();

        $html = '<!DOCTYPE html><html><head><title>ChatGPT</title></head><body><h1>Welcome</h1></body></html>';
        $patched = $manager->patchHtml($html, 'http://127.0.0.1:8000');

        $this->assertStringContainsString('<script data-mirror-patch="early">', $patched);
        $this->assertStringContainsString('window.fetch', $patched);
        $this->assertStringContainsString('/__codex-api/', $patched);
        $this->assertStringContainsString('currentScript.parentNode.removeChild', $patched);
        $this->assertStringNotContainsString('Mirror Settings & Pool', $patched);
        $this->assertStringContainsString('</head>', $patched);
    }
}
