<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sl1_notification_envelopes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('envelope_version')->default('sl1.notification.v1');
            $table->string('notification_type');
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_invitation_artifact_id')
                ->nullable()
                ->constrained('team_invitation_artifacts')
                ->nullOnDelete();
            $table->json('recipient_hint');
            $table->json('artifact_ref');
            $table->string('issuer_entity_address')->nullable();
            $table->string('status')->default('pending');
            $table->json('delivery_channels')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->string('authority_effect')->default('none');
            $table->boolean('non_authoritative')->default(true);
            $table->boolean('consumes_artifact')->default(false);
            $table->boolean('mutates_authority')->default(false);
            $table->json('capabilities_granted')->nullable();
            $table->string('replay_key')->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['notification_type', 'status']);
            $table->index(['team_invitation_artifact_id', 'status'], 'sl1_notif_artifact_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sl1_notification_envelopes');
    }
};
