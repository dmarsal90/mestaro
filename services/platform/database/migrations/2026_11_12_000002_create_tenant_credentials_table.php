<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Password of the per-tenant database role of each service, encrypted with APP_KEY (rotating the key needs re-encryption).
        Schema::create('tenant_credentials', function (Blueprint $table) {
            $table->char('restaurant_id', 26);
            $table->string('service', 30);
            $table->text('password');
            $table->timestamp('created_at');
            $table->primary(['restaurant_id', 'service']);
            $table->foreign('restaurant_id')->references('id')->on('restaurants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_credentials');
    }
};
