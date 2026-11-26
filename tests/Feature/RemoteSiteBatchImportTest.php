<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Settings;
use App\Models\RemoteSite;
use App\Support\RemoteSiteCsv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class RemoteSiteBatchImportTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_ROW = 'https://www.example.test/,example-site,server-01.example.test,ssh-user,ssh-pass,/home/example/public_html/,example_db,example_db,db-pass,example-theme,https://git.example.test/team/theme.git,1,0';

    private const ASTRO_ROW = 'https://www.example.test/,example-astro,server-02.example.test,ssh-user,ssh-pass,/home/example/public_html/,example_db,example_db,db-pass,example-theme,https://git.example.test/team/theme.git,1,0,ssh://git@git.example.test/team/astro-site.git,,the-beach';

    private function upload(string $csv): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('remote-sites.csv', $csv);
    }

    public function test_the_current_header_has_the_three_astro_columns(): void
    {
        $this->assertSame(array_slice(RemoteSiteCsv::HEADERS, -3), ['astro_repo_url', 'astro_branch', 'astro_folder']);
        $this->assertSame(RemoteSiteCsv::LEGACY_HEADERS, array_slice(RemoteSiteCsv::HEADERS, 0, 13));
    }

    public function test_a_legacy_file_without_astro_columns_still_imports(): void
    {
        $csv = implode(',', RemoteSiteCsv::LEGACY_HEADERS)."\n".self::LEGACY_ROW."\n";

        Livewire::test(Settings::class)
            ->set('batchCsvFile', $this->upload($csv))
            ->assertSet('batchParseError', false)
            ->call('executeBatchImport');

        $remote = RemoteSite::firstOrFail();
        $this->assertSame('example-site', $remote->local_domain);
        $this->assertNull($remote->astro_repo_url);
        $this->assertNull($remote->astro_folder);
    }

    public function test_a_current_file_imports_the_astro_project_with_main_as_the_default_branch(): void
    {
        $csv = implode(',', RemoteSiteCsv::HEADERS)."\n".self::ASTRO_ROW."\n";

        Livewire::test(Settings::class)
            ->set('batchCsvFile', $this->upload($csv))
            ->assertSet('batchParseError', false)
            ->call('executeBatchImport');

        $remote = RemoteSite::firstOrFail();
        $this->assertSame('ssh://git@git.example.test/team/astro-site.git', $remote->astro_repo_url);
        $this->assertSame('main', $remote->astro_branch);
        $this->assertSame('the-beach', $remote->astro_folder);
    }

    public function test_a_row_with_an_invalid_astro_folder_is_skipped_and_counted(): void
    {
        $badRow = str_replace(',the-beach', ',../the-beach', self::ASTRO_ROW);
        $csv = implode(',', RemoteSiteCsv::HEADERS)."\n".$badRow."\n";

        Livewire::test(Settings::class)
            ->set('batchCsvFile', $this->upload($csv))
            ->call('executeBatchImport');

        $this->assertSame(0, RemoteSite::count());
    }

    public function test_a_file_with_an_unknown_header_is_refused(): void
    {
        $csv = "remote_domain,unknown_column\nhttps://x.example.test/,1\n";

        Livewire::test(Settings::class)
            ->set('batchCsvFile', $this->upload($csv))
            ->assertSet('batchParseError', true);
    }
}
