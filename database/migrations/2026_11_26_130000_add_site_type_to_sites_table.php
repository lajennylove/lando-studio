<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Site type (vanilla, sage, astro). Existing rows are filled in from what they already store: an Astro port means
     * astro; a local site with a theme and no remote preset was created with Sage; everything else is vanilla.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('site_type', 20)->default('vanilla')->after('name');
        });

        DB::table('sites')->whereNotNull('astro_port')->update(['site_type' => 'astro']);
        DB::table('sites')
            ->whereNull('astro_port')
            ->whereNull('remote_site_id')
            ->whereNotNull('theme_name')
            ->update(['site_type' => 'sage']);
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('site_type');
        });
    }
};
