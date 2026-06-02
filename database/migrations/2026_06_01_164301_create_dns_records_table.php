<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dns_records')) {
            Schema::create('dns_records', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('dns_zone_id')->constrained('dns_zones')->onDelete('cascade');
                $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
                $table->string('provider_record_id')->nullable();
                $table->string('type', 16);
                $table->string('name');
                $table->text('content');
                $table->unsignedInteger('ttl')->default(1);
                $table->boolean('proxied')->default(false);
                $table->text('comment')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['dns_zone_id', 'type', 'name']);
                $table->index(['application_id']);
                $table->index(['provider_record_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_records');
    }
};
