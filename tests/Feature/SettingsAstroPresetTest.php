<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Settings;
use App\Models\RemoteSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SettingsAstroPresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_astro_project_is_saved_with_main_as_the_default_branch(): void
    {
        $astroRepo = 'https://git.example.test/team/astro-site.git';

        Livewire::test(Settings::class)
            ->call('addRemoteSite')
            ->set('remoteDomain', 'https://www.example.test/')
            ->set('sshServerIp', 'server-01.example.test')
            ->set('sshUser', 'ssh-user')
            ->set('remotePath', '/home/example/public_html/')
            ->set('dbName', 'example_db')
            ->set('dbUser', 'example_db')
            ->set('themeName', 'slotsparadise-wp-theme')
            ->set('repoUrl', 'https://git.example.test/team/theme.git')
            ->set('astroRepoUrl', $astroRepo)
            ->set('astroFolder', 'the-beach')
            ->call('saveRemoteSite')
            ->assertHasNoErrors();

        $saved = RemoteSite::firstOrFail();
        $this->assertSame($astroRepo, $saved->astro_repo_url);
        $this->assertSame('main', $saved->astro_branch);
        $this->assertSame('the-beach', $saved->astro_folder);
    }

    public function test_a_repo_url_without_a_folder_is_refused(): void
    {
        Livewire::test(Settings::class)
            ->call('addRemoteSite')
            ->set('remoteDomain', 'https://www.example.test/')
            ->set('sshServerIp', 'server-01.example.test')
            ->set('sshUser', 'ssh-user')
            ->set('remotePath', '/home/example/public_html/')
            ->set('dbName', 'example_db')
            ->set('dbUser', 'example_db')
            ->set('astroRepoUrl', 'https://git.example.test/team/astro-site.git')
            ->set('astroFolder', '')
            ->call('saveRemoteSite')
            ->assertHasErrors(['astroFolder']);

        $this->assertSame(0, RemoteSite::count());
    }

    public function test_a_folder_with_a_slash_is_refused(): void
    {
        Livewire::test(Settings::class)
            ->call('addRemoteSite')
            ->set('remoteDomain', 'https://www.example.test/')
            ->set('sshServerIp', 'server-01.example.test')
            ->set('sshUser', 'ssh-user')
            ->set('remotePath', '/home/example/public_html/')
            ->set('dbName', 'example_db')
            ->set('dbUser', 'example_db')
            ->set('astroRepoUrl', 'https://git.example.test/team/astro-site.git')
            ->set('astroFolder', '../the-beach')
            ->call('saveRemoteSite')
            ->assertHasErrors(['astroFolder']);
    }

    /** @return array<string, array{string}> */
    public static function sshAddresses(): array
    {
        return [
            'ssh url' => ['ssh://git@git.example.test/team/astro-site.git'],
            'scp style' => ['git@git.example.test:team/astro-site.git'],
        ];
    }

    #[DataProvider('sshAddresses')]
    public function test_ssh_addresses_are_saved_for_the_theme_and_the_astro_project(string $address): void
    {
        Livewire::test(Settings::class)
            ->call('addRemoteSite')
            ->set('remoteDomain', 'https://www.example.test/')
            ->set('sshServerIp', 'server-01.example.test')
            ->set('sshUser', 'ssh-user')
            ->set('remotePath', '/home/example/public_html/')
            ->set('dbName', 'example_db')
            ->set('dbUser', 'example_db')
            ->set('repoUrl', $address)
            ->set('astroRepoUrl', $address)
            ->set('astroFolder', 'the-beach')
            ->call('saveRemoteSite')
            ->assertHasNoErrors();

        $saved = RemoteSite::firstOrFail();
        $this->assertSame($address, $saved->repo_url);
        $this->assertSame($address, $saved->astro_repo_url);
    }

    public function test_a_local_path_is_refused_for_the_astro_project(): void
    {
        Livewire::test(Settings::class)
            ->call('addRemoteSite')
            ->set('remoteDomain', 'https://www.example.test/')
            ->set('sshServerIp', 'server-01.example.test')
            ->set('sshUser', 'ssh-user')
            ->set('remotePath', '/home/example/public_html/')
            ->set('dbName', 'example_db')
            ->set('dbUser', 'example_db')
            ->set('astroRepoUrl', 'C:\\repos\\astro-site')
            ->set('astroFolder', 'the-beach')
            ->call('saveRemoteSite')
            ->assertHasErrors(['astroRepoUrl']);

        $this->assertSame(0, RemoteSite::count());
    }

    public function test_clearing_the_astro_repo_clears_its_branch_and_folder(): void
    {
        $remote = RemoteSite::create([
            'remote_domain' => 'https://www.example.test/',
            'local_domain' => 'https://slotsparadise.lndo.site',
            'ssh_server_ip' => 'server-01.example.test',
            'ssh_user' => 'ssh-user',
            'db_name' => 'example_db',
            'db_user' => 'example_db',
            'remote_path' => '/home/example/public_html/',
            'astro_repo_url' => 'https://git.example.test/team/astro-site.git',
            'astro_branch' => 'main',
            'astro_folder' => 'the-beach',
        ]);

        Livewire::test(Settings::class)
            ->call('editRemoteSite', $remote->id)
            ->assertSet('astroFolder', 'the-beach')
            ->set('astroRepoUrl', '')
            ->call('saveRemoteSite')
            ->assertHasNoErrors();

        $saved = $remote->fresh();
        $this->assertNull($saved->astro_repo_url);
        $this->assertNull($saved->astro_branch);
        $this->assertNull($saved->astro_folder);
    }
}
