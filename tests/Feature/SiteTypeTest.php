<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SiteTypeTest extends TestCase
{
    use RefreshDatabase;

    private function site(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'name' => 'site-'.uniqid(),
            'path' => sys_get_temp_dir(),
            'url' => 'https://x.lndo.site',
            'admin_url' => 'https://x.lndo.site/wp-admin',
            'status' => 'running',
            'php_version' => '8.3',
            'db_version' => '11.8',
            'redis_version' => '8.6',
            'db_port' => 32800,
        ], $overrides));
    }

    public function test_the_column_exists_and_defaults_to_vanilla(): void
    {
        $this->assertTrue(Schema::hasColumn('sites', 'site_type'));

        $this->assertSame(SiteType::Vanilla, $this->site()->fresh()->site_type);
    }

    public function test_the_type_is_cast_to_the_enum(): void
    {
        $site = $this->site(['site_type' => SiteType::Astro]);

        $this->assertSame(SiteType::Astro, $site->fresh()->site_type);
        $this->assertSame('astro', DB::table('sites')->where('id', $site->id)->value('site_type'));
    }
}
