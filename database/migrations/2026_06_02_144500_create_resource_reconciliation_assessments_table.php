<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('resource_reconciliation_assessments')) {
            Schema::create('resource_reconciliation_assessments', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('resource_routing_policy_id')->nullable()->constrained('resource_routing_policies')->nullOnDelete();
                $table->string('resource_type');
                $table->string('resource_uuid')->nullable();
                $table->string('scope')->default('resource_routing');
                $table->string('assessment_hash', 64);
                $table->json('observation_refs')->nullable();
                $table->json('conflicts')->nullable();
                $table->json('candidates')->nullable();
                $table->json('assessment');
                $table->unsignedTinyInteger('severity')->default(0);
                $table->timestamp('assessed_at');
                $table->timestamps();

                $table->index(['team_id', 'scope', 'assessed_at']);
                $table->index(['team_id', 'resource_type', 'resource_uuid'], 'resource_assessment_resource_idx');
                $table->index(['assessment_hash']);
            });
        }

        if (Schema::hasTable('resource_arbitration_decisions') && ! Schema::hasColumn('resource_arbitration_decisions', 'resource_reconciliation_assessment_id')) {
            Schema::table('resource_arbitration_decisions', function (Blueprint $table) {
                $table->foreignId('resource_reconciliation_assessment_id')
                    ->nullable()
                    ->after('resource_routing_policy_id')
                    ->constrained('resource_reconciliation_assessments')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('resource_arbitration_decisions') && Schema::hasColumn('resource_arbitration_decisions', 'resource_reconciliation_assessment_id')) {
            Schema::table('resource_arbitration_decisions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('resource_reconciliation_assessment_id');
            });
        }

        Schema::dropIfExists('resource_reconciliation_assessments');
    }
};
