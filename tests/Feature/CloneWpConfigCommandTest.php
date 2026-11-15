<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\WpConfigGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class CloneWpConfigCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'clone-wpcfg-'.uniqid();
        mkdir($this->root.DIRECTORY_SEPARATOR.'wp', 0755, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->root.DIRECTORY_SEPARATOR.'wp');
        @rmdir($this->root);

        parent::tearDown();
    }

    public function test_wp_config_create_is_followed_by_the_local_only_constants(): void
    {
        Process::fake(['*' => Process::result('ok')]);
        // Port 1 refuses the connection at once, so the prefix falls back to wp_ without waiting.
        $site = Site::create(['name' => 'betus2', 'path' => $this->root, 'status' => SiteStatus::Creating, 'db_port' => 1]);

        $exit = Artisan::call('lando-dev:clone-wp-config', ['path' => $this->root, 'siteId' => $site->id]);

        $this->assertSame(0, $exit);
        foreach (WpConfigGenerator::LOCAL_ONLY_CONSTANTS as $name => $value) {
            Process::assertRan(fn (PendingProcess $process): bool => str_contains((string) $process->command, "wp config set {$name} {$value} --raw --path=wp"));
        }
    }

    public function test_a_clone_can_never_update_itself(): void
    {
        $this->assertSame('true', WpConfigGenerator::LOCAL_ONLY_CONSTANTS['AUTOMATIC_UPDATER_DISABLED']);
        $this->assertSame('false', WpConfigGenerator::LOCAL_ONLY_CONSTANTS['WP_AUTO_UPDATE_CORE']);
    }
}
