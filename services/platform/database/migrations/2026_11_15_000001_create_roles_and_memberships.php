<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** System roles and the permissions each one holds; `manager` is everything except user management (D-05). */
    private const ROLES = [
        'restaurant_admin' => ['Administrador', ['orders.view', 'orders.accept', 'items.prepare', 'calls.view', 'floor.manage', 'menu.manage', 'inventory.manage', 'reports.view', 'restaurant.manage', 'users.manage']],
        'manager' => ['Gerente', ['orders.view', 'orders.accept', 'items.prepare', 'calls.view', 'floor.manage', 'menu.manage', 'inventory.manage', 'reports.view', 'restaurant.manage']],
        'waiter' => ['Camarero', ['orders.view', 'orders.accept', 'calls.view']],
        'kitchen' => ['Cocina', ['orders.view', 'items.prepare']],
        'bar' => ['Barra', ['orders.view', 'items.prepare']],
    ];

    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->char('restaurant_id', 26)->nullable(); // NULL = system role
            $table->string('key', 40);
            $table->string('name', 80);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['restaurant_id', 'key']);
        });
        // A plain unique index treats NULLs as distinct, so system role keys need their own.
        DB::statement('CREATE UNIQUE INDEX roles_system_key_unique ON roles (key) WHERE restaurant_id IS NULL');

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('restaurant_user', function (Blueprint $table) {
            $table->char('restaurant_id', 26);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreign('restaurant_id')->references('id')->on('restaurants')->cascadeOnDelete();
            $table->primary(['restaurant_id', 'user_id']);
            $table->index('user_id');
        });

        $now = now();
        $permissions = array_values(array_unique(array_merge(...array_column(self::ROLES, 1))));
        DB::table('permissions')->insert(array_map(fn ($key) => ['key' => $key], $permissions));
        $permissionIds = DB::table('permissions')->pluck('id', 'key');

        foreach (self::ROLES as $key => [$name, $keys]) {
            $roleId = DB::table('roles')->insertGetId(['key' => $key, 'name' => $name, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('permission_role')->insert(array_map(fn ($k) => ['role_id' => $roleId, 'permission_id' => $permissionIds[$k]], $keys));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
