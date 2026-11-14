<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\SiteStatus;
use App\Models\CommandLog;
use App\Models\RepositoryCredential;
use App\Models\Site;
use App\Services\LandoService;
use App\Services\PlatformDetector;
use App\Services\SiteManager;
use App\Support\CommandLogErrorDetector;
use App\Support\GitCredentials;
use App\Support\LogContentUtf8;
use App\Support\StepLogActivity;
use Illuminate\Support\Facades\Log;
use Native\Laravel\Facades\ChildProcess;

trait WithCommandExecution
{
    /** Keep snapshot size bounded; full logs remain in storage/logs. */
    private const LIVEWIRE_OUTPUT_CAP_BYTES = 100_000;

    /** How many times one run may move the database port after Docker refuses it. */
    private const MAX_PORT_RETRIES = 5;

    public int $currentStep = 0;

    public int $totalSteps = 0;

    public array $steps = [];

    public string $terminalOutput = '';

    public string $completedOutput = '';

    public bool $isExecuting = false;

    public bool $executionFailed = false;

    public ?float $executionStartedAt = null;

    /** Database ports Docker refused in this run; each repair skips all of them. A stale block can span several ports. */
    public array $refusedPorts = [];

    /** The next step runs as `lando rebuild -y` because the database port changed. */
    public bool $rebuildThisStep = false;

    public function executeStepSequence(array $stepDefinitions, Site $site): void
    {
        $this->isExecuting = true;
        $this->executionFailed = false;
        $this->totalSteps = count($stepDefinitions);
        $this->currentStep = 0;
        $this->terminalOutput = '';
        $this->completedOutput = '';
        $this->executionStartedAt = microtime(true);
        $this->steps = array_map(fn ($s) => [
            'label' => $s['label'],
            'hint' => $s['hint'] ?? null,
            'group' => $s['group'] ?? null,
            'timeout' => $s['timeout'] ?? 600,
            'inactivity_timeout' => $s['inactivity_timeout'] ?? 30,
            'status' => 'pending',
            'output' => '',
        ], $stepDefinitions);

        $this->executeNextStep($stepDefinitions, $site);
    }

    private function executeNextStep(array $stepDefinitions, Site $site): void
    {
        if ($this->currentStep >= $this->totalSteps) {
            $this->isExecuting = false;
            $this->executionStartedAt = null;
            $this->onSequenceComplete($site);

            return;
        }

        $step = $stepDefinitions[$this->currentStep];
        $this->steps[$this->currentStep]['status'] = 'running';
        $this->executionStartedAt = microtime(true);

        $logFile = storage_path(
            'logs/lando_'.$site->id.'_step_'.$this->currentStep.'_'.time().'.log'
        );

        $logDir = dirname($logFile);
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Steps that need a saved repository login get it through a private file, never through the command text.
        if (! empty($step['git_credentials_host'])) {
            try {
                $credential = RepositoryCredential::where('host', $step['git_credentials_host'])->first();
                if (! $credential) {
                    $this->failCurrentStep($site, "No saved login for {$step['git_credentials_host']}.");

                    return;
                }
                GitCredentials::write(GitCredentials::fileFor('site-'.$site->id), $credential->username, $credential->password);
            } catch (\Throwable $e) {
                $this->failCurrentStep($site, 'Could not prepare the repository login: '.$e->getMessage());

                return;
            }
        }

        if ($this->rebuildThisStep) {
            // After a database port change the containers must be recreated, so the start step becomes a rebuild.
            $this->rebuildThisStep = false;
            $command = app(LandoService::class)->rebuild($site->path);
        } else {
            // Same rule as the Start button: never start on a database port something already listens on.
            if (($step['label'] ?? '') === 'Starting Lando environment') {
                try {
                    app(SiteManager::class)->ensurePortAvailable($site);
                } catch (\Throwable $e) {
                    Log::warning('WithCommandExecution::executeNextStep port check failed', ['site' => $site->id, 'error' => $e->getMessage()]);
                }
            }
            $command = $step['command'];
        }
        $alias = 'lando-site-'.$site->id.'-step-'.$this->currentStep.'-'.uniqid();

        CommandLog::create([
            'site_id' => $site->id,
            'command' => $command,
            'alias' => $alias,
            'status' => 'running',
            'step_number' => $this->currentStep,
            'step_label' => $step['label'],
            'log_file_path' => $logFile,
            'started_at' => now(),
        ]);

        $site->update(['log_file' => $logFile]);

        // Pre-write the command so the log file exists immediately and the terminal
        // shows what is being attempted even if the child process fails to start.
        file_put_contents($logFile, "[Lando Studio] Running: {$command}\n");

        $platform = app(PlatformDetector::class);
        $wrapped = $platform->wrapCommandWithLogRedirect($command, $logFile);

        if ($platform->isWindows()) {
            // Use PowerShell — lando on Windows runs via PS, not cmd.exe.
            $cmd = [...$platform->powershellArgs(), $wrapped];
        } else {
            $cmd = [$platform->shellWrapper(), $platform->shellFlag(), $wrapped];
        }

        try {
            ChildProcess::start(
                cmd: $cmd,
                alias: $alias,
            );
        } catch (\Throwable $e) {
            // NativePHP bridge (localhost:4002) unreachable — log and surface the error.
            $msg = 'ChildProcess::start() failed: '.$e->getMessage();
            file_put_contents($logFile, "\n[Lando Studio ERROR] {$msg}\n", FILE_APPEND);
            $this->failCurrentStep($site, $msg);
        }
    }

    public function checkCommandStatus(): void
    {
        if (! $this->isExecuting) {
            return;
        }

        $site = $this->getSite();
        if (! $site || ! $site->log_file) {
            return;
        }

        $logFile = $site->log_file;
        $elapsed = microtime(true) - ($this->executionStartedAt ?? microtime(true));

        // If log file doesn't exist yet, check for timeout
        if (! file_exists($logFile)) {
            if ($elapsed > 30) {
                $this->failCurrentStep($site, 'Command failed to start — log file was never created. Check that Lando is installed and in your PATH.');
            }

            return;
        }

        $rawContent = LogContentUtf8::forLivewire((string) file_get_contents($logFile));
        $marker = PlatformDetector::STEP_DONE_MARKER;
        $hasMarker = str_contains($rawContent, $marker);

        // Strip the internal marker from user-facing output.
        $content = str_replace($marker, '', $rawContent);
        $this->terminalOutput = $this->capLivewireOutput($this->completedOutput.$content);
        $this->dispatch('lando-studio-scroll-terminal');
        $this->steps[$this->currentStep]['output'] = $this->tailLines($content, 10);

        // ── 1. Completion marker present — process definitely exited ────────
        // Check this BEFORE the wall-clock timeout so a step that finishes at
        // 29:59 isn't falsely killed when the next poll happens at 30:01.
        if ($hasMarker) {
            $this->completeCurrentStep($site, $content);

            return;
        }

        // ── 2. Overall timeout — safety net for truly stuck processes ────────
        $stepTimeout = $this->steps[$this->currentStep]['timeout'] ?? 600;
        if ($elapsed > $stepTimeout) {
            $timeoutMinutes = (int) round($stepTimeout / 60);
            $this->failCurrentStep($site, "Command timed out after {$timeoutMinutes} minutes.");

            return;
        }

        // ── 3. Inactivity fallback — marker not written (crash/kill) ────────
        // Lando commands have long pauses during builds (apt-get, node install,
        // healthchecks, "Continuing in 10 seconds..." warnings).
        $inactivityTimeout = $this->steps[$this->currentStep]['inactivity_timeout'] ?? 30;
        $lastModified = StepLogActivity::lastActivity($logFile);
        if ((time() - $lastModified) > $inactivityTimeout) {
            $this->completeCurrentStep($site, $content);
        }
    }

    private function completeCurrentStep(Site $site, string $content): void
    {
        $hasError = CommandLogErrorDetector::indicatesFailure($content);

        if ($hasError) {
            if ($this->recoverFromPortConflict($site, $content)) {
                return;
            }

            $this->failCurrentStep($site, $this->tailLines($content, 50));

            return;
        }

        $this->steps[$this->currentStep]['status'] = 'completed';

        // Accumulate this step's output for the full log
        $stepLabel = $this->steps[$this->currentStep]['label'] ?? '';
        $this->completedOutput .= "\n--- [{$stepLabel}] ---\n".$content."\n";
        $this->completedOutput = $this->capLivewireOutput($this->completedOutput);

        CommandLog::where('site_id', $site->id)
            ->where('step_number', $this->currentStep)
            ->where('status', 'running')
            ->update([
                'status' => 'completed',
                'output' => $this->tailLines($content, 100),
                'completed_at' => now(),
            ]);

        $this->currentStep++;
        $this->executeNextStep($this->getStepDefinitions(), $site);
    }

    /**
     * When Docker refuses the site's database port ("port is already allocated"), moves the site to a free port
     * and runs the failed step again as `lando rebuild -y`. Returns true when the step was restarted.
     * Docker can hold a stale reservation that nothing on the host listens on, so the earlier bound-port probe
     * cannot catch it, and stale reservations often come in blocks. Only the site's own database port is changed,
     * every refused port is remembered, and at most MAX_PORT_RETRIES repairs are made per run.
     */
    private function recoverFromPortConflict(Site $site, string $content): bool
    {
        if (count($this->refusedPorts) >= self::MAX_PORT_RETRIES) {
            return false;
        }

        $refused = CommandLogErrorDetector::conflictingPort($content);
        if ($refused === null) {
            return false;
        }

        try {
            $newPort = app(SiteManager::class)->reassignDatabasePortAfterConflict($site, $refused, $this->refusedPorts);
        } catch (\Throwable $e) {
            Log::warning('WithCommandExecution::recoverFromPortConflict could not reassign the port', ['site' => $site->id, 'error' => $e->getMessage()]);

            return false;
        }

        if ($newPort === null) {
            return false;
        }

        Log::info('WithCommandExecution::recoverFromPortConflict reassigned the database port', ['site' => $site->id, 'from' => $refused, 'to' => $newPort]);

        $this->refusedPorts[] = $refused;
        $this->rebuildThisStep = true;

        CommandLog::where('site_id', $site->id)
            ->where('step_number', $this->currentStep)
            ->where('status', 'running')
            ->update([
                'status' => 'failed',
                'error_output' => "Docker refused database port {$refused}. Using {$newPort} and rebuilding.",
                'completed_at' => now(),
            ]);

        $this->executeNextStep($this->getStepDefinitions(), $site);

        return true;
    }

    private function failCurrentStep(Site $site, string $errorMessage): void
    {
        $this->steps[$this->currentStep]['status'] = 'failed';
        $this->isExecuting = false;
        $this->executionFailed = true;
        $this->executionStartedAt = null;
        $site->update(['status' => SiteStatus::Error, 'last_error' => $errorMessage]);

        CommandLog::where('site_id', $site->id)
            ->where('step_number', $this->currentStep)
            ->where('status', 'running')
            ->update([
                'status' => 'failed',
                'error_output' => $errorMessage,
                'completed_at' => now(),
            ]);
    }

    public function retryFromFailedStep(): void
    {
        $site = $this->getSite();
        if (! $site) {
            return;
        }

        $this->executionFailed = false;
        $this->isExecuting = true;

        $stepDefinitions = $this->getStepDefinitions();
        $this->executeNextStep($stepDefinitions, $site);
    }

    private function tailLines(string $content, int $lines): string
    {
        $allLines = explode("\n", trim($content));
        $slice = array_slice($allLines, -$lines);

        return implode("\n", $slice);
    }

    private function capLivewireOutput(string $buffer): string
    {
        if (strlen($buffer) <= self::LIVEWIRE_OUTPUT_CAP_BYTES) {
            return $buffer;
        }

        return "[Earlier output truncated — see storage/logs for full step logs.]\n\n"
            .substr($buffer, -self::LIVEWIRE_OUTPUT_CAP_BYTES);
    }

    abstract protected function getSite(): ?Site;

    abstract protected function getStepDefinitions(): array;

    abstract protected function onSequenceComplete(Site $site): void;
}
