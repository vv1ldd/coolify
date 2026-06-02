<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('simple_l1_evidence_packages')) {
            Schema::create('simple_l1_evidence_packages', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->string('domain');
                $table->string('package_type')->default('failover_election');
                $table->string('evidence_hash', 64)->unique();
                $table->json('observations')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('sealed_at');
                $table->timestamps();

                $table->index(['team_id', 'domain', 'sealed_at']);
                $table->index(['team_id', 'package_type']);
            });
        }

        if (Schema::hasTable('simple_l1_failover_decisions') && ! Schema::hasColumn('simple_l1_failover_decisions', 'simple_l1_evidence_package_id')) {
            Schema::table('simple_l1_failover_decisions', function (Blueprint $table) {
                $table->foreignId('simple_l1_evidence_package_id')
                    ->nullable()
                    ->after('dns_steering_policy_id')
                    ->constrained('simple_l1_evidence_packages')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('simple_l1_failover_decisions') && Schema::hasColumn('simple_l1_failover_decisions', 'simple_l1_evidence_package_id')) {
            Schema::table('simple_l1_failover_decisions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('simple_l1_evidence_package_id');
            });
        }

        Schema::dropIfExists('simple_l1_evidence_packages');
    }
};
