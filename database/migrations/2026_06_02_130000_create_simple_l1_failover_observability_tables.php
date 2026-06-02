<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('simple_l1_node_observations')) {
            Schema::create('simple_l1_node_observations', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->string('domain');
                $table->timestamp('observed_at');
                $table->string('observer_node')->nullable();
                $table->string('target_node')->nullable();
                $table->string('target_ip')->nullable();
                $table->string('status');
                $table->unsignedInteger('latency_ms')->nullable();
                $table->unsignedSmallInteger('http_code')->nullable();
                $table->string('health_source')->nullable();
                $table->string('health_url')->nullable();
                $table->json('evidence')->nullable();
                $table->timestamps();

                $table->index(['team_id', 'domain', 'observed_at']);
                $table->index(['team_id', 'target_ip', 'observed_at']);
                $table->index(['team_id', 'status']);
            });
        }

        if (! Schema::hasTable('simple_l1_failover_decisions')) {
            Schema::create('simple_l1_failover_decisions', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('dns_steering_policy_id')->nullable()->constrained('dns_steering_policies')->nullOnDelete();
                $table->string('domain');
                $table->timestamp('decided_at');
                $table->string('previous_target')->nullable();
                $table->string('new_target')->nullable();
                $table->string('recommendation');
                $table->string('reason');
                $table->string('evidence_hash', 64);
                $table->json('evidence')->nullable();
                $table->json('applied_result')->nullable();
                $table->string('applied_by')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();

                $table->index(['team_id', 'domain', 'decided_at']);
                $table->index(['team_id', 'recommendation']);
                $table->index(['evidence_hash']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('simple_l1_failover_decisions');
        Schema::dropIfExists('simple_l1_node_observations');
    }
};
