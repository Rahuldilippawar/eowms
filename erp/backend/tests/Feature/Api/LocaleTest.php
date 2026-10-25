<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_follow_accept_language(): void
    {
        $body = ['email' => 'nobody@example.com', 'password' => 'x'];

        $en = $this->withHeaders(['Accept-Language' => 'en'])->postJson('/api/v1/auth/login', $body)->json('errors.email.0');
        $mr = $this->withHeaders(['Accept-Language' => 'mr'])->postJson('/api/v1/auth/login', $body)->json('errors.email.0');

        $this->assertSame(__('auth.failed', [], 'en'), $en);
        $this->assertSame(__('auth.failed', [], 'mr'), $mr);
        $this->assertNotSame($en, $mr);
    }
}
