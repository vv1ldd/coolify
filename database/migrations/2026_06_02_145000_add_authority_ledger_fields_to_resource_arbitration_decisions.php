<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('resource_arbitration_decisions')) {
            return;
        }

        Schema::table('resource_arbitration_decisions', function (Blueprint $table) {
            if (! Schema::hasColumn('resource_arbitration_decisions', 'decision_hash')) {
                $table->string('decision_hash', 64)->nullable()->after('decision');
            }

            if (! Schema::hasColumn('resource_arbitration_decisions', 'authority_scope')) {
                $table->string('authority_scope')->default('resource_routing')->after('scope');
            }

            if (! Schema::hasColumn('resource_arbitration_decisions', 'authority_actor')) {
                $table->string('authority_actor')->nullable()->after('authority_scope');
            }

            if (! Schema::hasColumn('resource_arbitration_decisions', 'authority_basis')) {
                $table->string('authority_basis')->nullable()->after('authority_actor');
            }

            if (! Schema::hasColumn('resource_arbitration_decisions', 'supersedes_decision_id')) {
                $table->foreignId('supersedes_decision_id')
                    ->nullable()
                    ->after('authority_basis')
                    ->constrained('resource_arbitration_decisions')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('resource_arbitration_decisions', 'rationale')) {
                $table->json('rationale')->nullable()->after('assessment');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('resource_arbitration_decisions')) {
            return;
        }

        Schema::table('resource_arbitration_decisions', function (Blueprint $table) {
            if (Schema::hasColumn('resource_arbitration_decisions', 'supersedes_decision_id')) {
                $table->dropConstrainedForeignId('supersedes_decision_id');
            }

            foreach ([
                'decision_hash',
                'authority_scope',
                'authority_actor',
                'authority_basis',
                'rationale',
            ] as $column) {
                if (Schema::hasColumn('resource_arbitration_decisions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
