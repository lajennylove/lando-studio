<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use App\Services\AstroPortAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AstroPortAllocatorTest extends TestCase
{
    use RefreshDatabase;

    private function siteWithAstroPort(?int $port): void
    {
        Site::create([
            'name' => 'site-'.uniqid(),
            'path' => sys_get_temp_dir(),
            'url' => 'https://x.lndo.site',
            'admin_url' => 'https://x.lndo.site/wp-admin',
            'status' => 'running',
            'php_version' => '8.3',
            'db_version' => '11.8',
            'redis_version' => '8.6',
            'db_port' => 32800,
            'astro_port' => $port,
        ]);
    }

    public function test_the_first_site_gets_the_first_port(): void
    {
        $this->assertSame(AstroPortAllocator::FIRST_PORT, AstroPortAllocator::next());
    }

    public function test_a_port_already_given_to_a_site_is_skipped(): void
    {
        $this->siteWithAstroPort(AstroPortAllocator::FIRST_PORT);
        $this->siteWithAstroPort(AstroPortAllocator::FIRST_PORT + 2);

        $this->assertSame(AstroPortAllocator::FIRST_PORT + 1, AstroPortAllocator::next());
    }

    public function test_sites_without_an_astro_port_do_not_take_one(): void
    {
        $this->siteWithAstroPort(null);

        $this->assertSame(AstroPortAllocator::FIRST_PORT, AstroPortAllocator::next());
    }
}
