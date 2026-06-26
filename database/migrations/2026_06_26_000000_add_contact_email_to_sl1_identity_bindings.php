<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sl1_identity_bindings', function (Blueprint $table) {
            // ADR-0056: email is a non-authoritative contact claim, not an identity key.
            // entity_address remains the only identity key; these are contact attributes only.
            $table->string('contact_email')->nullable()->after('display_alias');
            $table->string('contact_email_hash')->nullable()->after('contact_email');
        });
    }

    public function down(): void
    {
        Schema::table('sl1_identity_bindings', function (Blueprint $table) {
            $table->dropColumn(['contact_email', 'contact_email_hash']);
        });
    }
};
