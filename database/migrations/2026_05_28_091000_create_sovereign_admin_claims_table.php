<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sovereign_admin_claims')) {
            return;
        }

        Schema::create('sovereign_admin_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('claim_token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->string('claimed_entity_address')->nullable()->index();
            $table->json('last_proof')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'claimed_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sovereign_admin_claims');
    }
};
