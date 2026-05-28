<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infra_authorizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('policy_decision_id')->constrained()->cascadeOnDelete();
            $table->string('capability');
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
            $table->json('scope');
            $table->json('policy_decision')->nullable();
            $table->json('risk_context')->nullable();
            $table->string('replay_key')->unique();
            $table->string('status')->default('issued');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('consumed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'capability', 'status']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_authorizations');
    }
};
