<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Money is integer minor units. Names and prices are copied: later changes to the catalog never alter an order.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('table_session_id')->index()->constrained('table_sessions');
            $table->foreignId('participant_id')->nullable()->constrained('table_session_participants');
            $table->string('status', 20);
            $table->string('notes', 255)->nullable();
            $table->char('currency', 3);
            $table->bigInteger('subtotal');
            $table->bigInteger('tax_total');
            $table->bigInteger('discount_total')->default(0);
            $table->bigInteger('total');
            foreach (['accepted', 'rejected', 'cancelled'] as $step) {
                $table->timestampTz("{$step}_at")->nullable();
                $table->string("{$step}_by", 26)->nullable();
            }
            $table->string('reject_reason', 255)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->index(['status', 'id']);
        });
        DB::statement('alter table orders add constraint orders_amounts check (
            subtotal >= 0 and tax_total >= 0 and discount_total >= 0 and total >= 0 and total = subtotal + tax_total - discount_total)');

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('order_id')->index()->constrained('orders');
            $table->string('product_id', 26);
            $table->string('name_snapshot', 120);
            $table->string('variant_id', 26)->nullable();
            $table->string('variant_name_snapshot', 80)->nullable();
            $table->bigInteger('unit_price');
            $table->unsignedSmallInteger('tax_bps');
            $table->unsignedSmallInteger('quantity');
            $table->bigInteger('line_total');
            $table->string('destination', 20);
            $table->string('status', 20);
            $table->string('notes', 255)->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->index(['destination', 'status']);
        });
        DB::statement('alter table order_items add constraint order_items_amounts check (unit_price >= 0 and line_total >= 0 and quantity > 0)');

        Schema::create('order_item_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->index()->constrained('order_items');
            $table->string('addon_id', 26);
            $table->string('name_snapshot', 80);
            $table->bigInteger('unit_price');
            $table->unsignedSmallInteger('quantity');
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->index()->constrained('orders');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('actor_user_id', 26)->nullable();
            $table->foreignId('actor_participant_id')->nullable()->constrained('table_session_participants');
            $table->string('reason', 255)->nullable();
            $table->timestampTz('created_at');
        });
        DB::unprepared(<<<'SQL'
            create function order_status_history_is_insert_only() returns trigger language plpgsql as
                $$ begin raise exception 'order_status_history is insert only'; end $$;
            create trigger order_status_history_insert_only before update or delete on order_status_history
                for each row execute function order_status_history_is_insert_only();
            SQL);

        // shortcut: keys are kept for the life of the database; prune them once the table's size matters.
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->foreignId('participant_id')->constrained('table_session_participants');
            $table->string('key', 64);
            $table->char('request_hash', 64);
            $table->jsonb('response_body')->nullable(); // set in the same transaction as the order
            $table->timestampTz('created_at');
            $table->primary(['participant_id', 'key']);
        });
    }

    public function down(): void
    {
        foreach (['idempotency_keys', 'order_status_history', 'order_item_addons', 'order_items', 'orders'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('drop function if exists order_status_history_is_insert_only()');
    }
};
