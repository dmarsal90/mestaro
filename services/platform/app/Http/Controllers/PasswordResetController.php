<?php

namespace App\Http\Controllers;

use App\Application\Audit\AuditTrail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Mestaro\Kernel\Auth\TokenDenylist;
use Mestaro\Kernel\Http\ApiError;
use Throwable;

final class PasswordResetController
{
    public function __construct(private AuditTrail $audit, private TokenDenylist $denylist) {}

    public function forgot(Request $request): JsonResponse
    {
        $email = $this->email($request);

        try {
            // Only active accounts get a link; the answer is the same for everyone so accounts cannot be enumerated.
            Password::sendResetLink(['email' => $email, 'is_active' => true]);
        } catch (Throwable $e) {
            report($e); // a mail outage must not turn into a different answer for existing accounts
        }
        $this->audit->record('auth.password_reset_requested', metadata: ['email' => $email]);

        return response()->json(null, 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024', 'confirmed', PasswordRule::min(12)],
        ]);
        $email = $this->email($request);
        $actor = null;

        $status = Password::reset(
            ['email' => $email, 'is_active' => true, 'token' => $request->string('token')->toString(),
                'password' => $request->string('password')->toString(), 'password_confirmation' => $request->string('password_confirmation')->toString()],
            function (User $user, string $password) use (&$actor) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete(); // every open session ends: the reset may follow a compromise
                $this->denylist->revokeUser($user->public_id);
                $actor = $user->public_id;
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->audit->record('auth.password_reset_failed', metadata: ['email' => $email]);

            return ApiError::response('INVALID_RESET_TOKEN', 'The reset link is invalid or has expired.', 422);
        }
        $this->audit->record('auth.password_reset', $actor);

        return response()->json(null, 204);
    }

    private function email(Request $request): string
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:254']]);

        return strtolower($request->string('email')->toString());
    }
}
