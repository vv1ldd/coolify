<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pending_intents', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('event_type'); // e.g. server.removed, application.deploy

            // Target entity morphs
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();

            $table->json('payload');       // The exact payload to execute
            $table->json('signatures');    // Cryptographic signatures map: {"DID:SYS|USER:#1": "sig_hex"}
            $table->json('timeline');      // Timeline log entries for the authority view

            $table->string('status')->default('pending'); // pending, approved, rejected, executed
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_intents');
    }
};
