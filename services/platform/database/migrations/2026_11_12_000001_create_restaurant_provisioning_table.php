<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per service that reported success; the composite key makes duplicate reports harmless.
        Schema::create('restaurant_provisioning', function (Blueprint $table) {
            $table->char('restaurant_id', 26);
            $table->string('service', 30);
            $table->timestamp('created_at');
            $table->primary(['restaurant_id', 'service']);
            $table->foreign('restaurant_id')->references('id')->on('restaurants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_provisioning');
    }
};
