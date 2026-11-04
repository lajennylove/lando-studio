<?php

namespace App\Livewire;

use App\Enums\SiteStatus;
use App\Livewire\Concerns\WithNotifications;
use App\Models\Site;
use App\Services\DatabasePortAllocator;
use App\Services\LandoService;
use App\Services\LandoYamlPortEditor;
use App\Services\LocalSiteScanner;
use App\Services\PlatformDetector;
use App\Support\LogContentUtf8;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Native\Laravel\Facades\ChildProcess;

#[Layout('components.layouts.app')]
#[Title('Import Local Sites — Lando Studio')]
class ImportSites extends Component
{
    use WithNotifications;

    private const REBUILD_DONE_MARKER = '[Lando Studio] Port rebuild finished';

    /** @var array<int, array{name: string, path: string, php_version: string, db_type: string, db_version: string, redis_version: string|null, db_port: int|null}> */
    public array $candidates = [];

    /** Names currently being imported (for loading state). */
    public array $importing = [];

    public bool $rebuildRunning = false;

    public string $rebuildOutput = '';

    public ?string $rebuildLogFile = null;

    public ?float $rebuildStartedAt = null;

    public function mount(LocalSiteScanner $scanner): void
    {
        $this->candidates = $scanner->findUnimported();
    }

    public function import(string $siteName, LocalSiteScanner $scanner): void
    {
        // Find the candidate by name
        $candidate = collect($this->candidates)->firstWhere('name', $siteName);

        if (! $candidate) {
            $this->notifyError("Site \"{$siteName}\" not found in the import list.");

            return;
        }

        // Guard against double-import
        if (Site::where('name', $siteName)->exists()) {
            $this->notifyError("\"{$siteName}\" is already in Lando Studio.");
            $this->candidates = $scanner->findUnimported();

            return;
        }

        if ($this->rebuildRunning) {
            $this->notifyError('Wait for the current port rebuild to finish.');

            return;
        }

        $this->importing[] = $siteName;

        $standardized = $this->standardizePort($candidate, app(DatabasePortAllocator::class), app(LandoYamlPortEditor::class));
        $this->createSite($candidate, $standardized['port']);

        $this->importing = array_values(array_diff($this->importing, [$siteName]));

        if ($standardized['changed']) {
            $this->startRebuild([$candidate['path']]);
        }

        $this->notifySuccess("\"{$siteName}\" has been imported into Lando Studio.");
        $this->dispatch('site-created')->to(SiteList::class);

        // Refresh list — remove the just-imported site
        $this->candidates = $scanner->findUnimported();
    }

    public function importAll(LocalSiteScanner $scanner): void
    {
        if (empty($this->candidates)) {
            $this->notifyInfo('No sites to import.');

            return;
        }

        if ($this->rebuildRunning) {
            $this->notifyError('Wait for the current port rebuild to finish.');

            return;
        }

        $ports = app(DatabasePortAllocator::class);
        $editor = app(LandoYamlPortEditor::class);

        $count = 0;
        $rebuildPaths = [];

        foreach ($this->candidates as $candidate) {
            if (Site::where('name', $candidate['name'])->exists()) {
                continue;
            }

            $standardized = $this->standardizePort($candidate, $ports, $editor);
            $this->createSite($candidate, $standardized['port']);

            if ($standardized['changed']) {
                $rebuildPaths[] = $candidate['path'];
            }

            $count++;
        }

        $this->candidates = $scanner->findUnimported();

        if ($rebuildPaths !== []) {
            $this->startRebuild($rebuildPaths);
        }

        $label = $count === 1 ? '1 site' : "{$count} sites";
        $this->notifySuccess("{$label} imported into Lando Studio.");
        $this->dispatch('site-created')->to(SiteList::class);
    }

    /**
     * Poll the background rebuild log until the completion marker appears.
     */
    public function pollRebuild(): void
    {
        if (! $this->rebuildRunning || $this->rebuildLogFile === null) {
            return;
        }

        $elapsed = microtime(true) - ($this->rebuildStartedAt ?? microtime(true));

        if (! file_exists($this->rebuildLogFile)) {
            if ($elapsed > 30) {
                $this->finishRebuild();
                $this->notifyError('Port rebuild failed to start. Check that Lando is installed and in your PATH.');
            }

            return;
        }

        $content = LogContentUtf8::forLivewire((string) file_get_contents($this->rebuildLogFile));
        $this->rebuildOutput = $content;

        if (str_contains($content, self::REBUILD_DONE_MARKER)) {
            $this->finishRebuild();
            $this->notifySuccess('Rebuild finished. Check the output below for errors.');

            return;
        }

        if ($elapsed > 1800) {
            $this->finishRebuild();
            $this->notifyError('Port rebuild timed out after 30 minutes.');
        }
    }

    public function render(): View
    {
        return view('livewire.import-sites');
    }

    /**
     * Make the candidate's database port unique across projects. Keeps its current port when no
     * higher-ranked project claims it; otherwise assigns a free one and rewrites its .lando.yml.
     *
     * @param  array{name: string, path: string, db_port: int|null}  $candidate
     * @return array{port: int, changed: bool}
     */
    private function standardizePort(array $candidate, DatabasePortAllocator $ports, LandoYamlPortEditor $editor): array
    {
        $port = $ports->assign($candidate['path'], $candidate['db_port']);

        if ($port === $candidate['db_port']) {
            return ['port' => $port, 'changed' => false];
        }

        $editor->setDatabasePort($candidate['path'].DIRECTORY_SEPARATOR.'.lando.yml', $port);

        return ['port' => $port, 'changed' => true];
    }

    /**
     * @param  array{name: string, path: string, php_version: string, db_version: string, redis_version: string|null}  $candidate
     */
    private function createSite(array $candidate, int $dbPort): void
    {
        Site::create([
            'name' => $candidate['name'],
            'path' => $candidate['path'],
            'url' => "https://{$candidate['name']}.lndo.site",
            'admin_url' => "https://{$candidate['name']}.lndo.site/wp-admin",
            'status' => SiteStatus::Unknown,
            'php_version' => $candidate['php_version'],
            'db_version' => $candidate['db_version'],
            'redis_version' => $candidate['redis_version'],
            'db_port' => $dbPort,
        ]);
    }

    /**
     * Run `lando rebuild -y` for each project in one background shell, one after another, so the
     * containers pick up the new port. The completion marker lets pollRebuild() know when it finished.
     *
     * @param  list<string>  $projectPaths
     */
    private function startRebuild(array $projectPaths): void
    {
        $lando = app(LandoService::class);
        $platform = app(PlatformDetector::class);

        $commands = array_map(fn (string $path) => $lando->rebuild($path), $projectPaths);
        $command = implode('; ', $commands).'; echo \''.self::REBUILD_DONE_MARKER.'\'';

        $logFile = storage_path('logs/import_rebuild_'.time().'.log');
        $logDir = dirname($logFile);
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $wrapped = $platform->wrapCommandWithLogRedirect($command, $logFile);

        ChildProcess::start(
            cmd: [$platform->shellWrapper(), $platform->shellFlag(), $wrapped],
            alias: 'import-rebuild-'.uniqid(),
        );

        $this->rebuildLogFile = $logFile;
        $this->rebuildOutput = '';
        $this->rebuildStartedAt = microtime(true);
        $this->rebuildRunning = true;
    }

    private function finishRebuild(): void
    {
        $this->rebuildRunning = false;
        $this->rebuildStartedAt = null;
    }
}
