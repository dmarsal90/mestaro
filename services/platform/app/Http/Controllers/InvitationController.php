<?php

namespace App\Http\Controllers;

use App\Application\Audit\AuditTrail;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Mestaro\Kernel\Http\ApiError;

/** Accepting is the person's own consent: the link proves the mailbox, and an existing account also proves its password. */
final class InvitationController
{
    public function __construct(private AuditTrail $audit) {}

    public function accept(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:1024']]);
        $invalid = fn () => ApiError::response('INVALID_INVITATION', 'The invitation is invalid or has expired.', 422);

        $invitation = DB::table('staff_invitations')->where('token_hash', hash('sha256', $request->string('token')->toString()))->where('expires_at', '>', now())->first();
        if ($invitation === null) {
            return $invalid();
        }

        $password = $request->string('password')->toString();
        $user = User::where('email', $invitation->email)->first();
        if ($user !== null) {
            if (! $user->is_active) {
                return $invalid();
            }
            if (! Hash::check($password, $user->password)) {
                return ApiError::response('INVALID_CREDENTIALS', 'The password does not match the existing account.', 422);
            }
        } else {
            $request->validate(['password' => [PasswordRule::min(12)]]);
        }

        try {
            DB::transaction(function () use ($invitation, $password, &$user) {
                // The row is the single-use guard: whoever deletes it first wins.
                if (DB::table('staff_invitations')->where('id', $invitation->id)->delete() === 0) {
                    throw new \DomainException('used');
                }
                $user ??= tap(User::create(['name' => $invitation->name, 'email' => $invitation->email, 'password' => $password]), fn (User $u) => $u->forceFill(['email_verified_at' => now()])->save());
                DB::table('restaurant_user')->insert([
                    'restaurant_id' => $invitation->restaurant_id, 'user_id' => $user->id, 'role_id' => $invitation->role_id,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        } catch (\DomainException) {
            return $invalid();
        } catch (UniqueConstraintViolationException) {
            return ApiError::response('ALREADY_MEMBER', 'This person already belongs to the restaurant.', 409);
        }
        $this->audit->record('staff.invitation_accepted', $user->public_id, 'restaurant', $invitation->restaurant_id);

        return response()->json(null, 204);
    }
}
