<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\OptimizedMode;
use App\Services\PlatformDetector;
use Mockery;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use Tests\TestCase;

class OptimizedModeTest extends TestCase
{
    private string $file;

    private ?string $original = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = storage_path('lando_studio_defaults.json');
        $this->original = is_file($this->file) ? (string) file_get_contents($this->file) : null;
    }

    protected function tearDown(): void
    {
        if ($this->original === null) {
            @unlink($this->file);
        } else {
            file_put_contents($this->file, $this->original);
        }

        parent::tearDown();
    }

    private function mode(bool $windows): OptimizedMode
    {
        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('isWindows')->andReturn($windows);

        return new OptimizedMode($platform);
    }

    public function test_it_is_off_until_someone_turns_it_on(): void
    {
        @unlink($this->file);

        $this->assertFalse($this->mode(true)->enabledForNewSites());
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_it_follows_the_saved_toggle_on_windows(): void
    {
        file_put_contents($this->file, json_encode(['optimized_mode' => true]));
        $this->assertTrue($this->mode(true)->enabledForNewSites());

        file_put_contents($this->file, json_encode(['optimized_mode' => false]));
        $this->assertFalse($this->mode(true)->enabledForNewSites());
    }

    public function test_it_is_never_on_outside_windows(): void
    {
        file_put_contents($this->file, json_encode(['optimized_mode' => true]));

        $this->assertFalse($this->mode(false)->isAvailable());
        $this->assertFalse($this->mode(false)->enabledForNewSites());
    }
}
