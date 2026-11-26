<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Port the Astro server of a headless site listens on inside its appserver. Null for WordPress-only sites.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedInteger('astro_port')->nullable()->after('db_port');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('astro_port');
        });
    }
};
