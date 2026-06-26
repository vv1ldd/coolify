<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agency_tool_subscriptions')) {
            Schema::create('agency_tool_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->onDelete('cascade');
                $table->foreignId('agency_engagement_id')->nullable()->constrained('agency_engagements')->nullOnDelete();
                $table->string('vendor');
                $table->string('tool_name');
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('currency', 3)->default('USD');
                $table->string('interval')->default('monthly');
                $table->date('renews_at')->nullable();
                $table->string('status')->default('active');
                $table->text('owner_notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['team_id', 'status']);
                $table->index(['team_id', 'renews_at']);
                $table->unique(['team_id', 'vendor', 'tool_name']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_tool_subscriptions');
    }
};
