<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sovereign Infrastructure Intent Ledger
     *
     * Stage 1: Co-located with operational DB for fast integration.
     * Stage 2: Migrate to a dedicated append-only ledger DB.
     * Stage 3: Anchor Merkle roots to Simple L1.
     *
     * This table stores STATE TRANSITIONS, not stdout logs.
     * Each row is a verifiable Document of Intent.
     */
    public function up(): void
    {
        if (Schema::hasTable('infra_ledger')) {
            return;
        }

        Schema::create('infra_ledger', function (Blueprint $table) {
            $table->id();

            // --- Identity ---
            $table->unsignedBigInteger('team_id')->nullable()->index();
            // DID:SYS format — can be user, node, service, or automation job
            // e.g. DID:SYS|USER:#42, DID:SYS|NODE:#validator-eu-west, DID:SYS|SERVICE:#deployment-engine
            $table->string('trigger_source', 512)->nullable();

            // --- Intent ---
            // The type of state transition (not a log line)
            // e.g. application.deploy, server.restart, server.validate, container.stop
            $table->string('event_type', 128)->index();
            $table->string('entity_type', 256)->nullable();
            $table->string('entity_id', 128)->nullable()->index();

            // --- State Transition Document ---
            // Structured intent payload — sanitized, no PII/secrets
            $table->json('payload')->nullable();
            // Captured input state before execution
            $table->json('input_state')->nullable();
            // Captured output state after execution (result: success/failed, exit_code, etc.)
            $table->json('output_state')->nullable();

            // --- Cryptographic Chain ---
            // SHA-256 of CanonicalJson(this document)
            $table->string('fingerprint', 64)->unique();
            // SHA-256 of the previous record's fingerprint (hash chaining)
            $table->string('previous_fingerprint', 64)->nullable()->index();

            // --- Kernel Proof ---
            // Stores kernel_fingerprint and determinism mode
            $table->json('meta')->nullable();

            $table->timestamp('created_at')->useCurrent();
            // Ledger is append-only — no updated_at, no soft deletes
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_ledger');
    }
};
