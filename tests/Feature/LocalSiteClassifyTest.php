<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Services\LocalSiteScanner;
use Tests\TestCase;

class LocalSiteClassifyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'classify-'.uniqid();
        mkdir($this->root.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'wp-content', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function test_a_wordpress_project_is_classified_as_vanilla(): void
    {
        $result = app(LocalSiteScanner::class)->classify($this->root);

        $this->assertSame(SiteType::Vanilla, $result['site_type']);
        $this->assertNull($result['astro_folder']);
        $this->assertNull($result['astro_port']);
    }

    public function test_an_astro_project_is_classified_with_its_folder_and_configured_port(): void
    {
        $folder = $this->root.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'the-beach';
        mkdir($folder, 0755, true);
        file_put_contents($folder.DIRECTORY_SEPARATOR.'astro.config.mjs', "export default defineConfig({ port: 4324, base: \"/the-beach\" });\n");

        $result = app(LocalSiteScanner::class)->classify($this->root);

        $this->assertSame(SiteType::Astro, $result['site_type']);
        $this->assertSame('the-beach', $result['astro_folder']);
        $this->assertSame(4324, $result['astro_port']);
    }

    public function test_an_astro_project_without_a_port_in_its_config_has_no_configured_port(): void
    {
        $folder = $this->root.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'vegas-astro';
        mkdir($folder, 0755, true);
        file_put_contents($folder.DIRECTORY_SEPARATOR.'astro.config.mjs', "export default defineConfig({ base: \"/lobby\" });\n");

        $result = app(LocalSiteScanner::class)->classify($this->root);

        $this->assertSame(SiteType::Astro, $result['site_type']);
        $this->assertSame('vegas-astro', $result['astro_folder']);
        $this->assertNull($result['astro_port']);
    }
}
