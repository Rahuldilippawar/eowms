<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_foreign_origins_get_no_cors_permission(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/v1/health');

        $response->assertOk();
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }
}
