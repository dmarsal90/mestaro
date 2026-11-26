<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Central index: which restaurant and table a scanned QR belongs to. Only the token's hash is kept.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_tokens', function (Blueprint $table) {
            $table->char('tenant_id', 26);
            $table->string('table_id', 26);
            $table->unsignedBigInteger('sequence');
            $table->char('token_hash', 64)->unique();
            $table->primary(['tenant_id', 'table_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_tokens');
    }
};
