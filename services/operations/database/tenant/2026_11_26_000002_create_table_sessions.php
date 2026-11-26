<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE = "status in ('pending_approval', 'open', 'bill_requested')";

    public function up(): void
    {
        Schema::create('table_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('table_id', 26)->index();
            $table->string('status', 20);
            $table->char('join_code', 6)->index();
            $table->timestampTz('opened_at')->nullable();
            $table->string('approved_by', 26)->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->string('closed_by', 26)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });
        // One live session per table and no two live sessions with the same code, whatever the requests race.
        DB::statement('create unique index table_sessions_live_table on table_sessions (table_id) where '.self::ACTIVE);
        DB::statement('create unique index table_sessions_live_code on table_sessions (join_code) where '.self::ACTIVE);

        Schema::create('table_session_participants', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('table_session_id')->constrained('table_sessions');
            $table->string('display_name', 40)->nullable();
            $table->char('token_hash', 64)->unique();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_session_participants');
        Schema::dropIfExists('table_sessions');
    }
};
