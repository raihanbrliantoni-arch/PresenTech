<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_respons_api_membawa_header_keamanan(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);

        // Data absensi/token tidak boleh tersimpan di cache browser/proxy.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_hsts_hanya_dikirim_lewat_https(): void
    {
        $this->getJson('/api/health')->assertHeaderMissing('Strict-Transport-Security');

        $this->getJson('https://localhost/api/health')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
