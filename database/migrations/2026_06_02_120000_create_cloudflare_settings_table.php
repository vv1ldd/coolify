<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cloudflare_settings')) {
            Schema::create('cloudflare_settings', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->unique()->constrained('teams')->onDelete('cascade');
                $table->text('api_token');
                $table->timestamp('last_validated_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cloudflare_settings');
    }
};
