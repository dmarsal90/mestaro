<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/** An active platform admin row: admin routes re-check the account, a signed token alone is not enough. */
function platformAdmin(array $attrs = []): User
{
    $user = User::factory()->create($attrs);
    $user->forceFill(['is_platform_admin' => true, ...array_intersect_key($attrs, ['is_platform_admin' => 1])])->save();

    return $user;
}

function restaurant(string $id, string $name, string $status = 'active'): string
{
    DB::table('restaurants')->insert(['id' => $id, 'name' => $name, 'slug' => strtolower($name), 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function member(User $user, string $restaurantId, string $role, bool $active = true): void
{
    DB::table('restaurant_user')->insert([
        'restaurant_id' => $restaurantId, 'user_id' => $user->id, 'is_active' => $active, 'created_at' => now(), 'updated_at' => now(),
        'role_id' => DB::table('roles')->whereNull('restaurant_id')->where('key', $role)->value('id'),
    ]);
}
