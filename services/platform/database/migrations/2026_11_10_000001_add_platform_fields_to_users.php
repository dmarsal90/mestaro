<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->ulid('public_id')->unique()->after('id');
            $table->boolean('is_platform_admin')->default(false)->after('password');
            $table->boolean('is_active')->default(true)->after('is_platform_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['public_id', 'is_platform_admin', 'is_active']);
        });
    }
};
