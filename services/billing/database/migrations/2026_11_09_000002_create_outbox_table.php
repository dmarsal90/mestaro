<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox', function (Blueprint $table) {
            $table->id();
            $table->char('event_id', 26)->unique();
            $table->string('type');
            $table->jsonb('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('published_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
    }
};
