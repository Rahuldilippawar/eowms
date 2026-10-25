<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    /**
     * Always answers the same way, whether or not the email exists, so the
     * endpoint cannot be used to discover accounts. Inactive users get no link.
     */
    public function sendLink(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        $user = User::where('email', $data['email'])->first();
        if ($user && $user->is_active) {
            $status = Password::sendResetLink(['email' => $user->email]);
            if ($status === Password::RESET_LINK_SENT) {
                AuditLogger::record('auth', 'password_reset_requested', $user, userId: $user->id);
            }
        }

        return response()->json(['message' => __('passwords.sent_generic')]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'must_change_password' => false,
            ])->save();

            // Sign the user out everywhere: any session opened before the reset ends.
            DB::table('sessions')->where('user_id', $user->id)->delete();
            AuditLogger::record('auth', 'password_reset', $user, userId: $user->id);
        });

        if ($status !== Password::PASSWORD_RESET) {
            // An unknown email reports the same as a bad token (no account discovery).
            $message = $status === Password::INVALID_USER ? __('passwords.token') : __($status);
            throw ValidationException::withMessages(['email' => [$message]]);
        }

        return response()->json(['message' => __($status)]);
    }
}
