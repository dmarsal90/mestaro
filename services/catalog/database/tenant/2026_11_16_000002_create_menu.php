<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Prices are integers in the currency's minor unit (cents): never floats.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 80)->unique();
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('category_id')->constrained('categories');
            $table->string('name', 120);
            $table->string('description', 1000)->nullable();
            $table->string('image_path', 200)->nullable();
            $table->unsignedBigInteger('price');
            $table->unsignedSmallInteger('tax_bps')->nullable(); // NULL = the restaurant's default
            $table->string('destination', 20)->default('kitchen');
            $table->jsonb('allergens')->default('[]');
            $table->jsonb('ingredients')->default('[]');
            $table->time('available_from')->nullable(); // schedule, in the restaurant's timezone
            $table->time('available_until')->nullable();
            $table->boolean('is_available')->default(true);
            $table->boolean('is_sold_out')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['category_id', 'sort_order']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedBigInteger('price_delta')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_available')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'name']);
        });

        Schema::create('product_addons', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedSmallInteger('max_quantity')->default(1);
            $table->boolean('is_available')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_addons');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
