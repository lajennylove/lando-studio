<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RemoteSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RemoteSiteAstroFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_adds_the_astro_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('remote_sites', ['astro_repo_url', 'astro_branch', 'astro_folder']));
    }

    public function test_a_preset_without_an_astro_project_leaves_the_fields_empty(): void
    {
        $remote = $this->presetFor('slots-paradise');

        $this->assertNull($remote->fresh()->astro_repo_url);
        $this->assertNull($remote->fresh()->astro_branch);
        $this->assertNull($remote->fresh()->astro_folder);
    }

    public function test_a_preset_keeps_its_astro_repository_branch_and_folder(): void
    {
        $remote = $this->presetFor('slots-paradise', [
            'astro_repo_url' => 'https://git.example.test/team/astro-site.git',
            'astro_branch' => 'main',
            'astro_folder' => 'the-beach',
        ]);

        $saved = $remote->fresh();
        $this->assertSame('https://git.example.test/team/astro-site.git', $saved->astro_repo_url);
        $this->assertSame('main', $saved->astro_branch);
        $this->assertSame('the-beach', $saved->astro_folder);
    }

    /** @param  array<string, mixed>  $extra */
    private function presetFor(string $name, array $extra = []): RemoteSite
    {
        return RemoteSite::create(array_merge([
            'remote_domain' => "https://{$name}.example/",
            'local_domain' => $name,
            'ssh_server_ip' => 'wordpress-0-0.cloudwaysapps.com',
            'ssh_user' => 'ssh-user',
            'ssh_password' => 'x',
            'db_name' => 'db_test',
            'db_user' => 'db_test',
            'db_password' => 'x',
            'theme_name' => 'test-theme',
            'remote_path' => '/home/master/applications/db_test/public_html/',
        ], $extra));
    }
}
