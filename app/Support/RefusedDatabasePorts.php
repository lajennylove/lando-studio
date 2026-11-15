<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Remembers host ports Docker refused ("port is already allocated") although nothing on the host listens on them.
 *
 * Docker Desktop can hold stale reservations, often a run of neighbouring ports, that no listener probe can see.
 * Once the step runner learns of one, every later allocation skips it. Entries expire (a Docker restart clears
 * the reservations) so a port is not lost for good. The list is a small JSON file under storage/app.
 */
final class RefusedDatabasePorts
{
    /** Seconds a refused port stays skipped: seven days. */
    public const TTL_SECONDS = 604_800;

    /**
     * Ports currently remembered as refused.
     *
     * @return list<int>
     */
    public function all(): array
    {
        $now = time();
        $ports = [];

        foreach ($this->read() as $port => $refusedAt) {
            if (($now - (int) $refusedAt) < self::TTL_SECONDS) {
                $ports[] = (int) $port;
            }
        }

        return $ports;
    }

    /**
     * Records a refused port. Writes the file, creating its folder if needed; a write failure is ignored
     * because skipping the port is an optimisation, not a requirement.
     */
    public function add(int $port): void
    {
        $entries = $this->read();
        $entries[$port] = time();

        $file = $this->file();
        $dir = dirname($file);

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($file, json_encode($entries), LOCK_EX);
    }

    /**
     * @return array<int|string, int>
     */
    private function read(): array
    {
        $file = $this->file();

        if (! is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function file(): string
    {
        return (string) (config('lando_dev.refused_ports_file') ?: storage_path('app/refused-db-ports.json'));
    }
}
