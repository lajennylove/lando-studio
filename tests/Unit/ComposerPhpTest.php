<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ComposerPhp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ComposerPhpTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'composer-php-'.uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $data */
    private function write(string $name, array $data): string
    {
        $path = $this->dir.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, json_encode($data));

        return $path;
    }

    public function test_the_lock_file_sets_the_highest_lowest_requirement(): void
    {
        $lock = $this->write('composer.lock', [
            'packages' => [
                ['name' => 'a/a', 'require' => ['php' => '>=8.2']],
                ['name' => 'b/b', 'require' => ['php' => '^8.3']],
            ],
            'packages-dev' => [
                ['name' => 'c/c', 'require' => ['php' => '>=8.4']],
            ],
        ]);
        $manifest = $this->write('composer.json', ['require' => ['php' => '>=8.3']]);

        $this->assertSame('8.4', ComposerPhp::requiredVersion($lock, $manifest));
    }

    public function test_the_manifest_alone_is_enough(): void
    {
        $manifest = $this->write('composer.json', ['require' => ['php' => '>=8.3']]);

        $this->assertSame('8.3', ComposerPhp::requiredVersion(null, $manifest));
    }

    public function test_no_declared_version_gives_null(): void
    {
        $manifest = $this->write('composer.json', ['require' => ['ext-json' => '*']]);

        $this->assertNull(ComposerPhp::requiredVersion(null, $manifest));
    }

    public function test_a_php_platform_entry_in_the_lock_counts(): void
    {
        $lock = $this->write('composer.lock', ['packages' => [], 'platform' => ['php' => '>=8.4']]);

        $this->assertSame('8.4', ComposerPhp::requiredVersion($lock, $this->dir.'/missing.json'));
    }

    #[DataProvider('constraints')]
    public function test_a_constraint_is_read_by_its_lowest_allowed_version(string $constraint, ?string $expected): void
    {
        $this->assertSame($expected, ComposerPhp::lowestOf($constraint));
    }

    /** @return array<string, array{string, ?string}> */
    public static function constraints(): array
    {
        return [
            'minimum' => ['>=8.3', '8.3'],
            'caret' => ['^8.2', '8.2'],
            'wildcard' => ['8.3.*', '8.3'],
            'alternatives take the lowest' => ['^8.4 || ^8.2', '8.2'],
            'upper bound only' => ['<8.5', null],
            'exclusion only' => ['!=8.0', null],
            'any version' => ['*', null],
        ];
    }

    public function test_pick_returns_the_lowest_version_that_fits(): void
    {
        $this->assertSame('8.4', ComposerPhp::pick('8.4', ['8.5', '8.4', '8.3']));
        $this->assertSame('8.5', ComposerPhp::pick('8.4.5', ['8.5', '8.3']));
        $this->assertNull(ComposerPhp::pick('8.6', ['8.5', '8.4']));
    }
}
