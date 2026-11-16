<?php

use App\Filament\Resources\RestaurantRecords\Pages\ListRestaurantRecords;
use App\Models\RestaurantRecord;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** A platform admin who already set up the authenticator app (the panel requires it). */
function panelAdmin(array $attrs = []): User
{
    $admin = platformAdmin($attrs);
    $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $admin->saveAppAuthenticationRecoveryCodes(['aaaa-bbbb']);

    return $admin;
}

/** Filament's callAction data does not reach this modal's form here, so the data is set on the mounted action. */
function registerRestaurant(array $data)
{
    return Livewire::test(ListRestaurantRecords::class)->mountAction('register')->setActionData($data)->callMountedAction();
}

it('keeps restaurant staff, inactive admins and visitors out of the panel', function () {
    $this->get('/admin/restaurantes')->assertRedirect('/admin/login');

    $staff = User::factory()->create();
    $this->actingAs($staff)->get('/admin')->assertForbidden();

    $off = panelAdmin(['is_active' => false]);
    $this->actingAs($off)->get('/admin')->assertForbidden();
});

it('sends an admin without a second factor to set it up before anything else', function () {
    $admin = platformAdmin();

    $this->actingAs($admin)->get('/admin/restaurantes')->assertRedirect();
    expect($this->actingAs($admin)->get('/admin/restaurantes')->headers->get('Location'))->toContain('multi-factor-authentication/set-up');
});

it('lets a set-up admin see restaurants', function () {
    restaurant('01J00000000000000000000001', 'Alfa');

    $this->actingAs(panelAdmin())->get('/admin/restaurantes')->assertOk()->assertSee('Alfa');
});

it('registers, suspends and retries through the application services, each audited', function () {
    $admin = panelAdmin();
    $this->actingAs($admin);

    registerRestaurant(['name' => 'Gamma', 'slug' => 'gamma'])->assertNotified();
    $gamma = RestaurantRecord::where('slug', 'gamma')->firstOrFail();
    expect($gamma->status)->toBe('provisioning');

    registerRestaurant(['name' => 'Otra', 'slug' => 'gamma'])->assertNotified('Ese identificador ya está en uso.');
    expect(RestaurantRecord::where('slug', 'gamma')->count())->toBe(1);

    $active = restaurant('01J00000000000000000000002', 'Beta');
    $failed = restaurant('01J00000000000000000000003', 'Delta', 'provisioning_failed');
    $list = Livewire::test(ListRestaurantRecords::class);
    $activeRow = RestaurantRecord::find($active);
    $failedRow = RestaurantRecord::find($failed);
    $list->assertActionVisible(TestAction::make('suspend')->table($activeRow))->assertActionHidden(TestAction::make('retry')->table($activeRow))
        ->assertActionHidden(TestAction::make('suspend')->table($failedRow))->assertActionVisible(TestAction::make('retry')->table($failedRow));
    $list->callAction(TestAction::make('suspend')->table($activeRow))->assertNotified();
    $list->callAction(TestAction::make('retry')->table($failedRow))->assertNotified();

    expect(RestaurantRecord::find($active)->status)->toBe('suspended')
        ->and(RestaurantRecord::find($failed)->status)->toBe('provisioning')
        ->and(DB::table('audit_logs')->whereIn('action', ['restaurant.registered', 'restaurant.suspended', 'restaurant.provisioning_retried'])->where('actor_user_id', $admin->public_id)->count())->toBe(3);
});
