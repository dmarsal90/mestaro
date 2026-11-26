<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// This restaurant's copy of catalog's categories, products, zones and tables, as their last events left them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_projection', function (Blueprint $table) {
            $table->string('kind', 16);
            $table->string('id', 26);
            $table->unsignedBigInteger('sequence');
            $table->jsonb('data');
            $table->primary(['kind', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_projection');
    }
};
