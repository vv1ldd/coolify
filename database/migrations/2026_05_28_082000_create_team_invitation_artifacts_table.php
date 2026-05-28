<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_invitation_artifacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('artifact_version')->default('team.invitation.v1');
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_decision_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('issued_by_entity_address')->nullable();
            $table->string('role_scope');
            $table->string('invited_principal_hint')->nullable();
            $table->string('delivery_email')->nullable();
            $table->string('delivery_email_hash')->nullable();
            $table->string('replay_key')->unique();
            $table->string('status')->default('issued');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('consumed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('consumed_by_entity_address')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['artifact_version', 'status']);
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->string('artifact_version')->nullable()->after('via');
            $table->foreignId('team_invitation_artifact_id')
                ->nullable()
                ->after('artifact_version')
                ->constrained('team_invitation_artifacts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('team_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_invitation_artifact_id');
            $table->dropColumn('artifact_version');
        });

        Schema::dropIfExists('team_invitation_artifacts');
    }
};
