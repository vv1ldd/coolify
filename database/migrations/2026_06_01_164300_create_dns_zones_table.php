<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dns_zones')) {
            Schema::create('dns_zones', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->onDelete('cascade');
                $table->string('provider')->default('cloudflare');
                $table->string('name');
                $table->string('provider_zone_id')->nullable();
                $table->text('api_token');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['team_id', 'provider']);
                $table->index(['provider', 'provider_zone_id']);
                $table->unique(['team_id', 'provider', 'name']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_zones');
    }
};
