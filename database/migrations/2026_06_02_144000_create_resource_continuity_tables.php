<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('resource_routing_policies')) {
            Schema::create('resource_routing_policies', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->string('resource_type');
                $table->string('resource_uuid')->nullable();
                $table->string('domain')->nullable();
                $table->string('routing_layer')->default('l7');
                $table->string('strategy')->default('active_passive');
                $table->boolean('enabled')->default(false);
                $table->json('candidate_backends')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['team_id', 'resource_type', 'resource_uuid'], 'resource_routing_policy_resource_idx');
                $table->index(['team_id', 'domain']);
            });
        }

        if (! Schema::hasTable('resource_observations')) {
            Schema::create('resource_observations', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('resource_routing_policy_id')->nullable()->constrained('resource_routing_policies')->nullOnDelete();
                $table->string('resource_type');
                $table->string('resource_uuid')->nullable();
                $table->string('backend')->nullable();
                $table->string('status');
                $table->unsignedInteger('latency_ms')->nullable();
                $table->unsignedTinyInteger('confidence')->default(100);
                $table->json('evidence')->nullable();
                $table->timestamp('observed_at');
                $table->timestamps();

                $table->index(['team_id', 'resource_type', 'observed_at'], 'resource_observation_resource_idx');
                $table->index(['team_id', 'status']);
            });
        }

        if (! Schema::hasTable('resource_arbitration_decisions')) {
            Schema::create('resource_arbitration_decisions', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('resource_routing_policy_id')->nullable()->constrained('resource_routing_policies')->nullOnDelete();
                $table->foreignId('edge_projection_id')->nullable()->constrained('edge_projections')->nullOnDelete();
                $table->string('scope')->default('resource_routing');
                $table->string('decision');
                $table->string('reason');
                $table->json('assessment')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('decided_at');
                $table->timestamps();

                $table->index(['team_id', 'scope', 'decided_at']);
            });
        }

        if (! Schema::hasTable('resource_control_actions')) {
            Schema::create('resource_control_actions', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('resource_arbitration_decision_id')->nullable()->constrained('resource_arbitration_decisions')->nullOnDelete();
                $table->foreignId('edge_control_action_id')->nullable()->constrained('edge_control_actions')->nullOnDelete();
                $table->string('action_type');
                $table->string('adapter');
                $table->string('status');
                $table->json('request')->nullable();
                $table->json('outcome')->nullable();
                $table->timestamp('executed_at');
                $table->timestamps();

                $table->index(['team_id', 'adapter', 'status']);
                $table->index(['team_id', 'executed_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_control_actions');
        Schema::dropIfExists('resource_arbitration_decisions');
        Schema::dropIfExists('resource_observations');
        Schema::dropIfExists('resource_routing_policies');
    }
};
