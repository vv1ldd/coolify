<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('security_observations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('trace_id', 128)->nullable()->index();
            $table->string('session_id', 128)->nullable()->index();
            $table->string('source_ip', 45)->nullable();
            $table->string('source_hash', 64)->index();
            $table->string('layer', 16)->default('L2')->index();
            $table->string('signal', 128)->index();
            $table->unsignedSmallInteger('score');
            $table->string('action', 32)->default('observe')->index();
            $table->string('method', 16)->nullable();
            $table->string('path', 2048)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('observed_at')->index();
            $table->timestamps();

            $table->index(['team_id', 'source_hash']);
            $table->index(['team_id', 'trace_id']);
            $table->index(['team_id', 'session_id']);
            $table->index(['team_id', 'signal']);
            $table->index(['team_id', 'observed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_observations');
    }
};
