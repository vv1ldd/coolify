<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dns_steering_policies')) {
            Schema::create('dns_steering_policies', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->onDelete('cascade');
                $table->foreignId('dns_zone_id')->nullable()->constrained('dns_zones')->nullOnDelete();
                $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
                $table->string('domain');
                $table->string('record_name')->nullable();
                $table->string('resource_type')->nullable();
                $table->string('resource_uuid')->nullable();
                $table->string('strategy')->default('static');
                $table->boolean('enabled')->default(false);
                $table->json('candidate_nodes')->nullable();
                $table->json('desired_records')->nullable();
                $table->timestamp('last_applied_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['team_id', 'domain']);
                $table->index(['dns_zone_id', 'record_name']);
                $table->index(['resource_type', 'resource_uuid']);
                $table->index(['enabled', 'strategy']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_steering_policies');
    }
};
