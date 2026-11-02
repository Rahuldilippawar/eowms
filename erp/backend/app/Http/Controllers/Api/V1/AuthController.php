<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetAccessContext;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\CurrentUserResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\WebAuthn\WebAuthn;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $user = User::where('email', $credentials['email'])->first();

        // Same message for unknown email, wrong password and inactive account,
        // so the response never reveals which accounts exist.
        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            if ($user) {
                AuditLogger::record('auth', 'login_failed', $user, userId: $user->id);
            }
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        Auth::guard('web')->login($user, (bool) ($credentials['remember'] ?? false));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();
        AuditLogger::record('auth', 'login', $user, userId: $user->id);

        return $this->currentUser($request, $user);
    }

    /** Sign-in with a fingerprint / face / device PIN (erp/docs/WEBAUTHN.md): first the challenge… */
    public function webauthnOptions(WebAuthn $webauthn): JsonResponse
    {
        return response()->json(['data' => $webauthn->requestOptions('login')]);
    }

    /** …then the signed answer. Same outcome as a password sign-in. */
    public function webauthnLogin(Request $request, WebAuthn $webauthn): JsonResponse
    {
        $request->validate([
            'credential.id' => ['required', 'string', 'max:512'],
            'credential.response.clientDataJSON' => ['required', 'string', 'max:4096'],
            'credential.response.authenticatorData' => ['required', 'string', 'max:4096'],
            'credential.response.signature' => ['required', 'string', 'max:1024'],
        ]);
        try {
            $user = $webauthn->verify('login', $request->input('credential'))->user;
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['credential' => [__('webauthn.login_failed')]]);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['credential' => [__('webauthn.login_failed')]]);
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLogger::record('auth', 'login', $user, null, ['method' => 'webauthn'], userId: $user->id);

        return $this->currentUser($request, $user);
    }

    public function me(Request $request): CurrentUserResource
    {
        return new CurrentUserResource($request->user());
    }

    public function switchOrganization(Request $request): JsonResponse
    {
        $data = $request->validate(['organization_id' => ['required', 'integer']]);
        $user = $request->user();

        // Only organizations the user belongs to (any active one for a super admin).
        $organization = AccessContext::organizationsFor($user)->firstWhere('id', (int) $data['organization_id']);
        if ($organization === null) {
            abort(403, __('auth.forbidden'));
        }

        $request->session()->put(SetAccessContext::SESSION_KEY, $organization->id);
        AccessContext::set(new AccessContext($user, $organization));
        AuditLogger::record('auth', 'switch_organization', $organization);

        return (new CurrentUserResource($user))->response();
    }

    /**
     * Change one's own password (also clears a forced change). Other sessions
     * of this user end; this one stays signed in.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', PasswordRule::min(8)->letters()->numbers()],
        ]);
        $user = $request->user();
        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        AuditLogger::record('auth', 'password_changed', $user, userId: $user->id);

        return (new CurrentUserResource($user->fresh()))->response();
    }

    public function logout(Request $request): JsonResponse
    {
        AuditLogger::record('auth', 'logout', $request->user());

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    /** After login the context middleware has not run yet, so build it here. */
    private function currentUser(Request $request, User $user): JsonResponse
    {
        $organizations = AccessContext::organizationsFor($user);
        $organization = $organizations->first();
        $organization instanceof Organization
            ? $request->session()->put(SetAccessContext::SESSION_KEY, $organization->id)
            : $request->session()->forget(SetAccessContext::SESSION_KEY);
        AccessContext::set(new AccessContext($user, $organization));

        return (new CurrentUserResource($user))->response();
    }
}
