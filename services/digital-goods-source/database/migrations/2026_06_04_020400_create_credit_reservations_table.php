<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kernel_partner_id')->constrained('kernel_partners')->cascadeOnDelete();
            $table->string('reference');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('granted');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['kernel_partner_id', 'reference']);
            $table->index(['kernel_partner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_reservations');
    }
};
