<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class LandoDefaultsTest extends TestCase
{
    public function test_redis_defaults_are_the_same_on_every_platform(): void
    {
        $this->assertSame('8.6', config('lando_dev.defaults.redis_version'));
        $this->assertSame('8.6', config('lando_dev.defaults.redis_versions')[0]);
        $this->assertContains('7.0', config('lando_dev.defaults.redis_versions'));
    }
}
