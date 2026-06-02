<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('execution_authority_leases')) {
            return;
        }

        Schema::create('execution_authority_leases', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 64);
            $table->string('scope_key', 191);
            $table->string('active_lease_key', 64)->nullable()->unique();
            $table->string('holder_peer_uuid');
            $table->string('lease_token', 128)->unique();
            $table->timestamp('acquired_at');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'scope_type', 'scope_key']);
            $table->index(['holder_peer_uuid', 'expires_at']);
            $table->index(['expires_at', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_authority_leases');
    }
};
