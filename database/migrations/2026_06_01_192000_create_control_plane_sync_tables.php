<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('control_plane_peers')) {
            Schema::create('control_plane_peers', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->string('name')->nullable();
                $table->string('endpoint_url')->nullable();
                $table->string('public_ip')->nullable();
                $table->string('region')->nullable();
                $table->string('role')->default('observer');
                $table->text('shared_secret')->nullable();
                $table->string('shared_secret_hash', 64)->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->string('status')->default('pending');
                $table->json('capabilities')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['team_id', 'status']);
                $table->index(['status', 'last_seen_at']);
                $table->index(['team_id', 'region']);
            });
        }

        if (! Schema::hasTable('control_plane_snapshots')) {
            Schema::create('control_plane_snapshots', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('control_plane_peer_id')->constrained()->cascadeOnDelete();
                $table->timestamp('observed_at');
                $table->json('servers_summary')->nullable();
                $table->json('dns_steering_readiness')->nullable();
                $table->json('edge_policy_versions')->nullable();
                $table->json('regional_readiness_summary')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['control_plane_peer_id', 'observed_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('control_plane_snapshots');
        Schema::dropIfExists('control_plane_peers');
    }
};
