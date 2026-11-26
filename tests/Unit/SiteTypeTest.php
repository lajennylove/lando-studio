<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\SiteType;
use PHPUnit\Framework\TestCase;

class SiteTypeTest extends TestCase
{
    public function test_the_stored_values_are_stable(): void
    {
        $this->assertSame('vanilla', SiteType::Vanilla->value);
        $this->assertSame('sage', SiteType::Sage->value);
        $this->assertSame('astro', SiteType::Astro->value);
    }

    public function test_only_astro_needs_an_astro_port(): void
    {
        $this->assertSame(['astro_port'], SiteType::Astro->extraFields());
        $this->assertSame([], SiteType::Vanilla->extraFields());
        $this->assertSame([], SiteType::Sage->extraFields());
    }

    public function test_every_type_has_a_label(): void
    {
        foreach (SiteType::cases() as $type) {
            $this->assertNotSame('', $type->label());
        }
    }
}
