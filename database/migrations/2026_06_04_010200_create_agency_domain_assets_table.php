<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agency_domain_assets')) {
            Schema::create('agency_domain_assets', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->onDelete('cascade');
                $table->foreignId('agency_engagement_id')->nullable()->constrained('agency_engagements')->nullOnDelete();
                $table->foreignId('dns_zone_id')->nullable()->constrained('dns_zones')->nullOnDelete();
                $table->string('domain');
                $table->string('registrar')->nullable();
                $table->date('expires_at')->nullable();
                $table->string('status')->default('active');
                $table->text('ownership_notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['team_id', 'status']);
                $table->index(['team_id', 'expires_at']);
                $table->unique(['team_id', 'domain']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_domain_assets');
    }
};
