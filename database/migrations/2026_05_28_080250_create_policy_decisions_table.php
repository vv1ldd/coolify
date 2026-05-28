<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_decisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('intent_type');
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
            $table->string('decision');
            $table->string('capability');
            $table->json('scope');
            $table->json('risk_context')->nullable();
            $table->json('reasons')->nullable();
            $table->timestamp('expires_at');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'intent_type', 'decision']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_decisions');
    }
};
