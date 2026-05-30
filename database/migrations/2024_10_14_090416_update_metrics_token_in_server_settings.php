<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            if (Schema::hasColumn('server_settings', 'metrics_token')) {
                $table->dropColumn('metrics_token');
            }
            if (Schema::hasColumn('server_settings', 'metrics_refresh_rate_seconds')) {
                $table->dropColumn('metrics_refresh_rate_seconds');
            }
            if (Schema::hasColumn('server_settings', 'metrics_history_days')) {
                $table->dropColumn('metrics_history_days');
            }
            if (Schema::hasColumn('server_settings', 'is_server_api_enabled')) {
                $table->dropColumn('is_server_api_enabled');
            }

            if (! Schema::hasColumn('server_settings', 'is_sentinel_enabled')) {
                $table->boolean('is_sentinel_enabled')->default(false);
            }
            if (! Schema::hasColumn('server_settings', 'sentinel_token')) {
                $table->text('sentinel_token')->nullable();
            }
            if (! Schema::hasColumn('server_settings', 'sentinel_metrics_refresh_rate_seconds')) {
                $table->integer('sentinel_metrics_refresh_rate_seconds')->default(10);
            }
            if (! Schema::hasColumn('server_settings', 'sentinel_metrics_history_days')) {
                $table->integer('sentinel_metrics_history_days')->default(7);
            }
            if (! Schema::hasColumn('server_settings', 'sentinel_push_interval_seconds')) {
                $table->integer('sentinel_push_interval_seconds')->default(60);
            }
            if (! Schema::hasColumn('server_settings', 'sentinel_custom_url')) {
                $table->string('sentinel_custom_url')->nullable();
            }
        });
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'sentinel_updated_at')) {
                $table->dateTime('sentinel_updated_at')->default(now());
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('server_settings', 'metrics_token')) {
                $table->string('metrics_token')->nullable();
            }
            if (! Schema::hasColumn('server_settings', 'metrics_refresh_rate_seconds')) {
                $table->integer('metrics_refresh_rate_seconds')->default(5);
            }
            if (! Schema::hasColumn('server_settings', 'metrics_history_days')) {
                $table->integer('metrics_history_days')->default(30);
            }
            if (! Schema::hasColumn('server_settings', 'is_server_api_enabled')) {
                $table->boolean('is_server_api_enabled')->default(false);
            }

            if (Schema::hasColumn('server_settings', 'is_sentinel_enabled')) {
                $table->dropColumn('is_sentinel_enabled');
            }
            if (Schema::hasColumn('server_settings', 'sentinel_token')) {
                $table->dropColumn('sentinel_token');
            }
            if (Schema::hasColumn('server_settings', 'sentinel_metrics_refresh_rate_seconds')) {
                $table->dropColumn('sentinel_metrics_refresh_rate_seconds');
            }
            if (Schema::hasColumn('server_settings', 'sentinel_metrics_history_days')) {
                $table->dropColumn('sentinel_metrics_history_days');
            }
            if (Schema::hasColumn('server_settings', 'sentinel_push_interval_seconds')) {
                $table->dropColumn('sentinel_push_interval_seconds');
            }
            if (Schema::hasColumn('server_settings', 'sentinel_custom_url')) {
                $table->dropColumn('sentinel_custom_url');
            }
        });
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'sentinel_updated_at')) {
                $table->dropColumn('sentinel_updated_at');
            }
        });
    }
};
