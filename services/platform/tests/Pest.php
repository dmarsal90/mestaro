<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/** An active platform admin row: admin routes re-check the account, a signed token alone is not enough. */
function platformAdmin(array $attrs = []): User
{
    $user = User::factory()->create($attrs);
    $user->forceFill(['is_platform_admin' => true, ...array_intersect_key($attrs, ['is_platform_admin' => 1])])->save();

    return $user;
}
