<?php

namespace App\Http\Controllers;

use App\Application\Audit\AuditTrail;
use App\Http\Requests\StaffRequest;
use App\Notifications\StaffInvitation;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mestaro\Kernel\Auth\AccessClaims;
use Mestaro\Kernel\Auth\TokenDenylist;
use Mestaro\Kernel\Http\ApiError;
use Throwable;

/** Staff of the restaurant in the token. A user is only ever reached through a membership of that restaurant. */
final class StaffController
{
    private const INVITATION_DAYS = 7;

    public function __construct(private AuditTrail $audit, private TokenDenylist $denylist) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->members($this->claims($request)->tenantId)->orderBy('u.name')->get()->map($this->present(...))]);
    }

    public function store(StaffRequest $request): JsonResponse
    {
        $claims = $this->claims($request);
        $email = strtolower($request->string('email')->toString());
        $name = $request->string('name')->toString();

        $alreadyMember = DB::table('restaurant_user as ru')->join('users as u', 'u.id', '=', 'ru.user_id')
            ->where('ru.restaurant_id', $claims->tenantId)->where('u.email', $email)->exists();
        if ($alreadyMember) {
            return ApiError::response('ALREADY_MEMBER', 'This person already belongs to the restaurant.', 409);
        }

        // Nothing is attached to any account here: the person accepts through the link, so an administrator cannot add (or probe) accounts.
        $token = Str::random(48);
        DB::table('staff_invitations')->upsert([[
            'restaurant_id' => $claims->tenantId, 'email' => $email, 'name' => $name, 'role_id' => $this->roleId($request->string('role')->toString()),
            'token_hash' => hash('sha256', $token), 'invited_by' => $claims->userId, 'expires_at' => now()->addDays(self::INVITATION_DAYS), 'created_at' => now(),
        ]], ['restaurant_id', 'email'], ['name', 'role_id', 'token_hash', 'invited_by', 'expires_at', 'created_at']);
        $this->audit->record('staff.invited', $claims->userId, 'restaurant', $claims->tenantId, ['role' => $request->string('role')->toString()]);

        try {
            $restaurant = DB::table('restaurants')->where('id', $claims->tenantId)->value('name');
            Notification::route('mail', $email)->notify(new StaffInvitation($restaurant, $token, self::INVITATION_DAYS));
        } catch (Throwable $e) {
            report($e); // the invitation exists; inviting again resends it
        }

        return response()->json(null, 201);
    }

    public function update(StaffRequest $request, string $id): JsonResponse
    {
        $claims = $this->claims($request);
        $member = $this->members($claims->tenantId)->where('u.public_id', $id)->first();
        if ($member === null) {
            return ApiError::response('NOT_FOUND', 'Staff member not found.', 404);
        }
        if ($id === $claims->userId) {
            return ApiError::response('CANNOT_MODIFY_SELF', 'You cannot change your own role or access.', 422);
        }

        $changes = [];
        if ($request->has('role')) {
            $changes['role_id'] = $this->roleId($request->string('role')->toString());
        }
        if ($request->has('is_active')) {
            $changes['is_active'] = $request->boolean('is_active');
        }
        if ($changes !== []) {
            DB::table('restaurant_user')->where('restaurant_id', $claims->tenantId)->where('user_id', $member->user_id)->update([...$changes, 'updated_at' => now()]);
            // Tokens carry the old role and permissions for up to 10 minutes; cut off this restaurant's, not the person's sessions elsewhere.
            $this->denylist->revokeUser($id, $claims->tenantId);
            $this->audit->record('staff.updated', $claims->userId, 'user', $id, ['restaurant_id' => $claims->tenantId, 'fields' => array_keys($request->validated())]);
        }

        return response()->json(['data' => $this->present($this->members($claims->tenantId)->where('u.public_id', $id)->first())]);
    }

    private function roleId(string $key): int
    {
        return DB::table('roles')->whereNull('restaurant_id')->where('key', $key)->value('id');
    }

    private function members(string $restaurantId): Builder
    {
        return DB::table('restaurant_user as ru')
            ->join('users as u', 'u.id', '=', 'ru.user_id')
            ->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->where('ru.restaurant_id', $restaurantId)
            ->select('u.id as user_id', 'u.public_id', 'u.name', 'u.email', 'r.key as role', 'ru.is_active');
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return ['id' => $row->public_id, 'name' => $row->name, 'email' => $row->email, 'role' => $row->role, 'is_active' => (bool) $row->is_active];
    }

    private function claims(Request $request): AccessClaims
    {
        return $request->attributes->get('claims');
    }
}
