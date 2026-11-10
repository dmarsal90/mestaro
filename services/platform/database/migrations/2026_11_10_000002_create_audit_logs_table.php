<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_user_id', 26)->nullable()->index(); // users.public_id; no FK so the trail outlives users
            $table->string('action', 64)->index();
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent()->index();
            $table->index(['subject_type', 'subject_id']);
        });

        // Append-only: a bug (or a careless query) cannot rewrite history. Retention jobs must disable this trigger deliberately.
        DB::unprepared(<<<'SQL'
            create or replace function audit_logs_append_only() returns trigger language plpgsql as $$
            begin
                raise exception 'audit_logs is append-only';
            end $$;
            create trigger audit_logs_no_update_delete before update or delete on audit_logs
                for each row execute function audit_logs_append_only();
            create trigger audit_logs_no_truncate before truncate on audit_logs
                for each statement execute function audit_logs_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::unprepared('drop function if exists audit_logs_append_only()');
    }
};
