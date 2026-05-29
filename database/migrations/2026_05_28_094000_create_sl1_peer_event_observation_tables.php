<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sl1_node_identities', function (Blueprint $table) {
            $table->id();
            $table->string('node_id')->unique();
            $table->string('issuer')->nullable();
            $table->string('signature_algorithm')->default('ed25519');
            $table->text('public_key');
            $table->text('private_key_ciphertext');
            $table->string('status')->default('active');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_seen_at']);
        });

        Schema::create('sl1_peer_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sl1_peer_node_id')->constrained()->cascadeOnDelete();
            $table->string('peer_node_id');
            $table->string('signature_algorithm')->default('ed25519');
            $table->text('peer_public_key');
            $table->string('trust_state')->default('observed');
            $table->json('metadata')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['sl1_peer_node_id', 'peer_node_id']);
            $table->index(['trust_state', 'last_seen_at']);
        });

        Schema::create('sl1_peer_sync_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sl1_peer_node_id')->constrained()->cascadeOnDelete();
            $table->string('cursor_type')->default('identity_events');
            $table->string('remote_cursor')->nullable();
            $table->string('status')->default('idle');
            $table->string('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['sl1_peer_node_id', 'cursor_type']);
            $table->index(['status', 'last_synced_at']);
        });

        Schema::create('sl1_peer_observed_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sl1_peer_node_id')->constrained()->cascadeOnDelete();
            $table->string('remote_event_id')->nullable();
            $table->uuid('remote_event_uuid')->nullable();
            $table->string('remote_event_hash');
            $table->string('event_type');
            $table->string('entity_address')->nullable();
            $table->string('controller_address')->nullable();
            $table->string('source')->nullable();
            $table->json('remote_payload')->nullable();
            $table->json('remote_envelope');
            $table->string('admissibility_status')->default('candidate');
            $table->json('admissibility_report')->nullable();
            $table->timestamp('admissibility_evaluated_at')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(['sl1_peer_node_id', 'remote_event_hash']);
            $table->index(['sl1_peer_node_id', 'admissibility_status']);
            $table->index(['admissibility_status', 'admissibility_evaluated_at']);
            $table->index(['entity_address', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sl1_peer_observed_events');
        Schema::dropIfExists('sl1_peer_sync_cursors');
        Schema::dropIfExists('sl1_peer_identities');
        Schema::dropIfExists('sl1_node_identities');
    }
};
