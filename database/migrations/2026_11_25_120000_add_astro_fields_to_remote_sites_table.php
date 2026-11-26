<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Astro (headless) projects keep their own repository, branch and folder, next to the WordPress theme.
     * Null means the preset has no Astro project. The folder is relative to the site's wp/ folder.
     */
    public function up(): void
    {
        Schema::table('remote_sites', function (Blueprint $table) {
            $table->string('astro_repo_url')->nullable()->after('install_node_dependencies');
            $table->string('astro_branch', 100)->nullable()->after('astro_repo_url');
            $table->string('astro_folder', 100)->nullable()->after('astro_branch');
        });
    }

    public function down(): void
    {
        Schema::table('remote_sites', function (Blueprint $table) {
            $table->dropColumn(['astro_repo_url', 'astro_branch', 'astro_folder']);
        });
    }
};
