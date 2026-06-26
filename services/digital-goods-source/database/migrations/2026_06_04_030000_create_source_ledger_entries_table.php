<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('scope')->default('source:global')->index();
            $table->string('event_type', 128)->index();
            $table->string('entity_type', 256)->nullable();
            $table->string('entity_id', 128)->nullable()->index();
            $table->string('provider', 128)->nullable()->index();
            $table->string('partner_external_id', 128)->nullable()->index();
            $table->string('reference', 160)->nullable()->index();
            $table->json('payload')->nullable();
            $table->json('input_state')->nullable();
            $table->json('output_state')->nullable();
            $table->string('fingerprint', 64)->unique();
            $table->string('previous_fingerprint', 64)->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_ledger_entries');
    }
};
