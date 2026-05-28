<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sl1_identity_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('entity_address')->unique();
            $table->string('controller_address')->nullable();
            $table->string('alias')->nullable();
            $table->string('display_alias')->nullable();
            $table->string('proof_id')->nullable();
            $table->json('last_proof')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sl1_identity_bindings');
    }
};
