<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AstroEnvFile;
use PHPUnit\Framework\TestCase;

class AstroEnvFileTest extends TestCase
{
    public function test_the_redis_host_is_the_lando_cache_service(): void
    {
        $this->assertSame('cache', AstroEnvFile::defaults(4321)['REDIS_HOST']);
        $this->assertStringContainsString("\nREDIS_HOST=cache\n", AstroEnvFile::render(4321));
    }

    public function test_the_public_url_points_at_the_site_port(): void
    {
        $this->assertSame('http://127.0.0.1:4327', AstroEnvFile::defaults(4327)['PUBLIC_API_URL']);
        $this->assertStringContainsString("PUBLIC_API_URL=http://127.0.0.1:4327\n", AstroEnvFile::render(4327));
    }

    public function test_secrets_are_written_blank_and_fuse_is_not_set(): void
    {
        $content = AstroEnvFile::render(4321);

        $this->assertStringContainsString("\nWP_API_USER=\n", $content);
        $this->assertStringContainsString("\nWP_API_PASSWORD=\n", $content);
        $this->assertStringNotContainsString('FUSE_', str_replace('# FUSE_* is not set for this project.', '', $content));
    }
}
