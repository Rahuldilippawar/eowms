<?php

namespace Tests\Unit;

use App\Http\Resources\CurrentUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

class CurrentUserResourceTest extends TestCase
{
    public function test_it_exposes_only_public_profile_fields(): void
    {
        $user = new User;
        $user->forceFill([
            'id' => 7,
            'name' => 'A',
            'email' => 'a@example.com',
            'password' => 'not-a-real-hash',
            'remember_token' => 'secret-token',
            'is_active' => true,
        ]);

        $data = (new CurrentUserResource($user))->resolve(Request::create('/'));

        $this->assertSame(['id', 'name', 'email', 'is_super_admin', 'last_login_at', 'must_change_password'], array_keys($data));
        $this->assertNull($data['last_login_at']);
    }
}
