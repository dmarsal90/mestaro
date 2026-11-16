<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An invitation is the only way to join a restaurant: nobody is attached to an account without that person's acceptance.
        Schema::create('staff_invitations', function (Blueprint $table) {
            $table->id();
            $table->char('restaurant_id', 26);
            $table->string('email', 254);
            $table->string('name', 120);
            $table->foreignId('role_id')->constrained('roles');
            $table->char('token_hash', 64)->unique(); // only the hash is stored, like a password
            $table->char('invited_by', 26);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('restaurant_id')->references('id')->on('restaurants')->cascadeOnDelete();
            $table->unique(['restaurant_id', 'email']); // inviting again replaces the pending one
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invitations');
    }
};
