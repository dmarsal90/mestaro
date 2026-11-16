<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No row is created at registration: a missing row means "defaults" (see RestaurantSettings).
        Schema::create('restaurant_settings', function (Blueprint $table) {
            $table->char('restaurant_id', 26)->primary();
            $table->char('currency', 3);
            $table->string('timezone', 64);
            $table->string('locale', 10);
            $table->jsonb('opening_hours')->nullable();
            $table->boolean('auto_accept_orders');
            $table->boolean('require_waiter_approval_for_session');
            $table->boolean('prices_include_tax');
            $table->unsignedSmallInteger('default_tax_bps');
            $table->unsignedSmallInteger('join_code_reuse_cooldown_minutes');
            $table->unsignedInteger('order_alert_threshold_seconds');
            $table->jsonb('preferences')->nullable();
            $table->timestamps();
            $table->foreign('restaurant_id')->references('id')->on('restaurants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_settings');
    }
};
