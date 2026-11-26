<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RemoteSite;
use App\Models\Site;
use App\Services\AstroPortAllocator;
use App\Services\SiteManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AstroCloneStepsTest extends TestCase
{
    use RefreshDatabase;

    private function preset(array $overrides = []): RemoteSite
    {
        return RemoteSite::create(array_merge([
            'remote_domain' => 'https://www.example.test/',
            'local_domain' => 'https://example.lndo.site',
            'ssh_server_ip' => 'server-01.example.test',
            'ssh_user' => 'ssh-user',
            'db_name' => 'example_db',
            'db_user' => 'example_db',
            'remote_path' => '/home/example/public_html/',
            'theme_name' => 'example-theme',
            'repo_url' => 'https://git.example.test/team/theme.git',
            'install_composer_dependencies' => false,
            'install_node_dependencies' => false,
        ], $overrides));
    }

    private function site(RemoteSite $remote, ?int $astroPort): Site
    {
        return Site::create([
            'name' => 'example',
            'path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'example-site',
            'url' => 'https://example.lndo.site',
            'admin_url' => 'https://example.lndo.site/wp-admin',
            'status' => 'creating',
            'php_version' => '8.3',
            'db_version' => '11.8',
            'redis_version' => '8.6',
            'db_port' => 32801,
            'astro_port' => $astroPort,
            'remote_site_id' => $remote->id,
        ]);
    }

    /** @return list<string> */
    private function labels(array $steps): array
    {
        return array_column($steps, 'label');
    }

    public function test_a_wordpress_only_preset_gets_no_astro_steps(): void
    {
        $remote = $this->preset();
        $site = $this->site($remote, null);

        $labels = $this->labels(app(SiteManager::class)->getCloneSiteSteps($site, $remote));

        $this->assertNotContains('Cloning Astro project', $labels);
        $this->assertNotContains('Starting Astro server', $labels);
    }

    public function test_a_headless_preset_gets_the_astro_steps_in_order_after_the_theme(): void
    {
        $remote = $this->preset([
            'astro_repo_url' => 'https://git.example.test/team/astro-site.git',
            'astro_branch' => 'main',
            'astro_folder' => 'the-beach',
        ]);
        $site = $this->site($remote, AstroPortAllocator::FIRST_PORT);

        $labels = $this->labels(app(SiteManager::class)->getCloneSiteSteps($site, $remote));

        $astro = [
            'Cloning Astro project',
            'Switching Astro project to main branch',
            'Writing Astro environment',
            'Installing Astro dependencies',
            'Building Astro project',
            'Starting Astro server',
        ];

        $start = array_search('Cloning Astro project', $labels, true);
        $this->assertNotFalse($start);
        $this->assertSame($astro, array_slice($labels, $start, count($astro)));
        $this->assertGreaterThan(array_search('Cloning theme repository', $labels, true), $start);
    }

    public function test_the_astro_steps_use_the_folder_and_the_port_of_the_site(): void
    {
        $remote = $this->preset([
            'astro_repo_url' => 'https://git.example.test/team/astro-site.git',
            'astro_branch' => 'release',
            'astro_folder' => 'the-beach',
        ]);
        $site = $this->site($remote, 4325);

        $steps = app(SiteManager::class)->getCloneSiteSteps($site, $remote);
        $byLabel = [];
        foreach ($steps as $step) {
            $byLabel[$step['label']] = $step;
        }

        $this->assertStringContainsString('the-beach', $byLabel['Cloning Astro project']['command']);
        $this->assertStringContainsString('the-beach', $byLabel['Installing Astro dependencies']['command']);
        $this->assertStringContainsString('wp/the-beach', $byLabel['Installing Astro dependencies']['command']);
        $this->assertStringContainsString('4325', $byLabel['Starting Astro server']['command']);
        $this->assertStringContainsString('4325', $byLabel['Writing Astro environment']['command']);
        $this->assertStringContainsString('release', $byLabel['Switching Astro project to release branch']['command']);
    }
}
