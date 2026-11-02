<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WebAuthn\WebAuthn;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** One's own fingerprint / face / device-PIN keys (erp/docs/WEBAUTHN.md). Sign-in is in AuthController. */
class WebAuthnController extends Controller
{
    public function __construct(private readonly WebAuthn $webauthn) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->webauthnCredentials()->orderBy('id')->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'created_at' => $c->created_at?->toIso8601String(), 'last_used_at' => $c->last_used_at?->toIso8601String()])->values()]);
    }

    public function registerOptions(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->webauthn->registrationOptions($request->user())]);
    }

    /** Adding a key to an account needs the password too, so a left-open session cannot add one. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'name' => ['required', 'string', 'max:100'],
            'credential.id' => ['required', 'string', 'max:512'],
            'credential.response.clientDataJSON' => ['required', 'string', 'max:4096'],
            'credential.response.attestationObject' => ['required', 'string', 'max:16384'],
            'credential.response.transports' => ['sometimes', 'array'],
        ]);
        $user = $request->user();
        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => [__('webauthn.wrong_password')]]);
        }
        try {
            $credential = $this->webauthn->register($user, $request->input('credential'), $data['name']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['credential' => [__('webauthn.failed', ['reason' => $e->getMessage()])]]);
        }
        AuditLogger::record('auth', 'webauthn_added', $user, null, ['name' => $credential->name], userId: $user->id);

        return $this->index($request)->setStatusCode(201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $credential = $request->user()->webauthnCredentials()->findOrFail($id);
        $credential->delete();
        AuditLogger::record('auth', 'webauthn_removed', $request->user(), null, ['name' => $credential->name], userId: $request->user()->id);

        return $this->index($request);
    }

    /** A challenge for confirming one's identity with a key (e.g. signing a letter). */
    public function confirmOptions(Request $request): JsonResponse
    {
        abort_if($request->user()->webauthnCredentials()->doesntExist(), 422, __('webauthn.none'));

        return response()->json(['data' => $this->webauthn->requestOptions('confirm', $request->user())]);
    }
}
