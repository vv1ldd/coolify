<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('edge_projections')) {
            return;
        }

        Schema::table('edge_projections', function (Blueprint $table) {
            if (! Schema::hasColumn('edge_projections', 'observation_confidence')) {
                $table->unsignedTinyInteger('observation_confidence')->nullable()->after('observed_state_hash');
            }

            if (! Schema::hasColumn('edge_projections', 'observation_quorum_status')) {
                $table->string('observation_quorum_status')->default('unknown')->after('observation_confidence');
            }

            if (! Schema::hasColumn('edge_projections', 'observation_window_started_at')) {
                $table->timestamp('observation_window_started_at')->nullable()->after('observation_quorum_status');
            }

            if (! Schema::hasColumn('edge_projections', 'observation_window_ended_at')) {
                $table->timestamp('observation_window_ended_at')->nullable()->after('observation_window_started_at');
            }

            if (! Schema::hasColumn('edge_projections', 'capability_snapshot_hash')) {
                $table->string('capability_snapshot_hash', 64)->nullable()->after('required_capabilities');
            }

            if (! Schema::hasColumn('edge_projections', 'capability_snapshot_version')) {
                $table->unsignedInteger('capability_snapshot_version')->default(1)->after('capability_snapshot_hash');
            }

            if (! Schema::hasColumn('edge_projections', 'intent_drift_status')) {
                $table->string('intent_drift_status')->default('unknown')->after('status');
            }

            if (! Schema::hasColumn('edge_projections', 'conflict_policy')) {
                $table->string('conflict_policy')->default('append_only_no_rollback')->after('intent_drift_status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('edge_projections')) {
            return;
        }

        Schema::table('edge_projections', function (Blueprint $table) {
            foreach ([
                'observation_confidence',
                'observation_quorum_status',
                'observation_window_started_at',
                'observation_window_ended_at',
                'capability_snapshot_hash',
                'capability_snapshot_version',
                'intent_drift_status',
                'conflict_policy',
            ] as $column) {
                if (Schema::hasColumn('edge_projections', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
