<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('edge_projections')) {
            Schema::create('edge_projections', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->string('domain')->nullable();
                $table->string('intent_type');
                $table->string('intent_uuid')->nullable();
                $table->unsignedInteger('intent_version')->default(1);
                $table->string('intent_hash', 64);
                $table->string('projection_type');
                $table->string('adapter');
                $table->unsignedInteger('projection_version')->default(1);
                $table->string('projection_hash', 64);
                $table->json('payload');
                $table->json('required_capabilities')->nullable();
                $table->string('status')->default('generated');
                $table->timestamp('generated_at');
                $table->timestamp('applied_at')->nullable();
                $table->string('applied_projection_hash', 64)->nullable();
                $table->timestamp('observed_at')->nullable();
                $table->string('observed_state_hash', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['team_id', 'domain']);
                $table->index(['team_id', 'adapter', 'status']);
                $table->index(['intent_type', 'intent_uuid']);
                $table->index(['projection_type', 'adapter']);
                $table->index(['projection_hash']);
            });
        }

        if (! Schema::hasTable('edge_projection_versions')) {
            Schema::create('edge_projection_versions', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('edge_projection_id')->constrained('edge_projections')->cascadeOnDelete();
                $table->unsignedInteger('projection_version');
                $table->string('intent_hash', 64);
                $table->string('projection_hash', 64);
                $table->json('payload');
                $table->json('metadata')->nullable();
                $table->timestamp('generated_at');
                $table->timestamps();

                $table->unique(['edge_projection_id', 'projection_version'], 'edge_projection_version_unique');
                $table->index(['team_id', 'projection_hash']);
            });
        }

        if (! Schema::hasTable('edge_control_actions')) {
            Schema::create('edge_control_actions', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('edge_projection_id')->nullable()->constrained('edge_projections')->nullOnDelete();
                $table->string('domain')->nullable();
                $table->string('action_type');
                $table->string('adapter');
                $table->string('status');
                $table->json('request')->nullable();
                $table->json('outcome')->nullable();
                $table->string('projection_hash', 64)->nullable();
                $table->string('applied_projection_hash', 64)->nullable();
                $table->string('observed_state_hash', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('executed_at');
                $table->timestamps();

                $table->index(['team_id', 'domain', 'executed_at']);
                $table->index(['team_id', 'adapter', 'status']);
                $table->index(['edge_projection_id', 'executed_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_control_actions');
        Schema::dropIfExists('edge_projection_versions');
        Schema::dropIfExists('edge_projections');
    }
};
