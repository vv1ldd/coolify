<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('edge_policies')) {
            Schema::create('edge_policies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained()->onDelete('cascade');
                $table->string('uuid')->unique();
                $table->string('name');
                $table->string('mode')->default('normal');
                $table->string('scope_type')->default('domain');
                $table->string('scope_value')->nullable();
                $table->string('ruleset')->nullable()->default('default_v1');
                $table->boolean('challenge_enabled')->default(false);
                $table->boolean('silent_drop_enabled')->default(false);
                $table->unsignedInteger('rate_limit_average')->nullable();
                $table->unsignedInteger('rate_limit_burst')->nullable();
                $table->unsignedInteger('in_flight_limit')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['team_id', 'scope_type']);
                $table->index(['scope_type', 'scope_value']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_policies');
    }
};
