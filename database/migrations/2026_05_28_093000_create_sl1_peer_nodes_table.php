<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sl1_peer_nodes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name')->nullable();
            $table->string('issuer')->unique();
            $table->string('status')->default('pending');
            $table->string('runtime')->nullable();
            $table->string('storage')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('last_status')->nullable();
            $table->json('last_issuer_document')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sl1_peer_nodes');
    }
};
