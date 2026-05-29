<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sl1_entities', function (Blueprint $table) {
            $table->id();
            $table->string('entity_address')->unique();
            $table->string('alias')->nullable();
            $table->string('display_alias')->nullable();
            $table->string('status')->default('active');
            $table->string('current_event_hash')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_verified_at']);
        });

        Schema::create('sl1_controllers', function (Blueprint $table) {
            $table->id();
            $table->string('entity_address');
            $table->string('controller_address')->nullable();
            $table->string('credential_hash')->nullable()->unique();
            $table->text('credential_id')->nullable();
            $table->text('credential_public_key')->nullable();
            $table->json('transports')->nullable();
            $table->string('rp_id')->nullable();
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('status')->default('active');
            $table->timestamp('added_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('entity_address')
                ->references('entity_address')
                ->on('sl1_entities')
                ->cascadeOnDelete();
            $table->unique(['entity_address', 'controller_address']);
            $table->index(['entity_address', 'status']);
            $table->index(['controller_address', 'status']);
        });

        Schema::create('sl1_identity_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('event_type');
            $table->string('entity_address');
            $table->string('controller_address')->nullable();
            $table->string('proof_id')->nullable();
            $table->string('source')->default('embedded-runtime');
            $table->string('event_hash')->unique();
            $table->string('previous_event_hash')->nullable();
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('entity_address')
                ->references('entity_address')
                ->on('sl1_entities')
                ->cascadeOnDelete();
            $table->index(['entity_address', 'occurred_at']);
            $table->index(['event_type', 'source']);
            $table->index(['proof_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sl1_identity_events');
        Schema::dropIfExists('sl1_controllers');
        Schema::dropIfExists('sl1_entities');
    }
};
