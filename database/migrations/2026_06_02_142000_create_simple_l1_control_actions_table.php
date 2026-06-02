<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('simple_l1_control_actions')) {
            Schema::create('simple_l1_control_actions', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('simple_l1_failover_decision_id')->constrained('simple_l1_failover_decisions')->cascadeOnDelete();
                $table->string('domain');
                $table->string('action_type');
                $table->string('adapter');
                $table->string('status');
                $table->json('request')->nullable();
                $table->json('outcome')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('executed_at');
                $table->timestamps();

                $table->index(['team_id', 'domain', 'executed_at']);
                $table->index(['team_id', 'action_type']);
                $table->index(['team_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('simple_l1_control_actions');
    }
};
