<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kernel_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kernel_partner_id')->nullable()->constrained('kernel_partners')->nullOnDelete();
            $table->string('provider');
            $table->string('reference');
            $table->string('service_sku');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('accepted');
            $table->json('cards')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'reference']);
            $table->index(['kernel_partner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kernel_orders');
    }
};
