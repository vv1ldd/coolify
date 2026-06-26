<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->string('sku');
            $table->string('market_sku')->nullable();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('canonical_category')->nullable();
            $table->string('reward_type')->nullable();
            $table->decimal('purchase_price', 12, 2)->default(0);
            $table->decimal('retail_price', 12, 2)->default(0);
            $table->decimal('min_price', 12, 2)->default(0);
            $table->decimal('max_price', 12, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('image')->nullable();
            $table->string('activation_url')->nullable();
            $table->text('redemption_instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('data')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'sku']);
            $table->index(['provider_id', 'is_active']);
            $table->index(['market_sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_products');
    }
};
