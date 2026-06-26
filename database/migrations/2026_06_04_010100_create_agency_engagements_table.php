<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agency_engagements')) {
            Schema::create('agency_engagements', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->onDelete('cascade');
                $table->foreignId('agency_client_id')->constrained('agency_clients')->onDelete('cascade');
                $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
                $table->string('name');
                $table->string('status')->default('pending');
                $table->string('priority')->default('normal');
                $table->date('starts_at')->nullable();
                $table->date('due_at')->nullable();
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['team_id', 'status']);
                $table->index(['team_id', 'due_at']);
                $table->unique(['team_id', 'agency_client_id', 'name']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_engagements');
    }
};
