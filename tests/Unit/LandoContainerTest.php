<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LandoContainer;
use PHPUnit\Framework\TestCase;

class LandoContainerTest extends TestCase
{
    public function test_hyphens_are_removed_the_way_lando_removes_them(): void
    {
        $this->assertSame('twgcasino_appserver_1', LandoContainer::appserver('twg-casino'));
    }

    public function test_plain_names_are_unchanged(): void
    {
        $this->assertSame('shop_appserver_1', LandoContainer::appserver('shop'));
    }

    public function test_names_are_lowercased_and_other_characters_dropped(): void
    {
        $this->assertSame('lockerroom2_appserver_1', LandoContainer::appserver('Locker Room_2!'));
    }

    public function test_the_app_name_drops_hyphens_the_way_lando_does(): void
    {
        $this->assertSame('twgcasino', LandoContainer::appName('twg-casino'));
        $this->assertSame('shop', LandoContainer::appName('shop'));
        $this->assertSame('lockerroom2', LandoContainer::appName('Locker Room_2!'));
    }
}
