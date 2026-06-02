<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('simple_l1_decision_evidence_links')) {
            Schema::create('simple_l1_decision_evidence_links', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('simple_l1_failover_decision_id')->constrained('simple_l1_failover_decisions')->cascadeOnDelete();
                $table->foreignId('simple_l1_evidence_package_id')->constrained('simple_l1_evidence_packages')->restrictOnDelete();
                $table->string('link_type')->default('primary');
                $table->json('metadata')->nullable();
                $table->timestamp('linked_at');
                $table->timestamps();

                $table->unique(['simple_l1_failover_decision_id', 'simple_l1_evidence_package_id', 'link_type'], 'sl1_decision_evidence_unique');
                $table->index(['team_id', 'link_type']);
                $table->index(['team_id', 'linked_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('simple_l1_decision_evidence_links');
    }
};
