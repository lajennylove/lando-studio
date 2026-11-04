<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Sets services.database.portforward in an existing .lando.yml by editing the matching line in place.
 *
 * Works on text rather than re-dumping the YAML so comments, key order and the user's other edits are kept.
 * Only the database block is touched; a `portforward: true` (dynamic) value is replaced with the fixed port.
 */
final class LandoYamlPortEditor
{
    public function setDatabasePort(string $filePath, int $port): void
    {
        $content = file_exists($filePath) ? (string) file_get_contents($filePath) : '';

        file_put_contents($filePath, $this->withDatabasePort($content, $port));
    }

    /**
     * Return the YAML content with the database portforward set to $port, adding the keys if they are missing.
     */
    public function withDatabasePort(string $content, int $port): string
    {
        $eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $lines = $content === '' ? [] : (preg_split('/\r?\n/', rtrim($content, "\r\n")) ?: []);

        $servicesIndex = $this->findTopLevelKey($lines, 'services');

        if ($servicesIndex === null) {
            array_push($lines, 'services:', '  database:', "    portforward: {$port}");

            return implode($eol, $lines).$eol;
        }

        $servicesEnd = $this->blockEnd($lines, $servicesIndex);
        $databaseIndex = $this->findChildKey($lines, $servicesIndex, $servicesEnd, 'database');

        if ($databaseIndex === null) {
            array_splice($lines, $servicesIndex + 1, 0, ['  database:', "    portforward: {$port}"]);

            return implode($eol, $lines).$eol;
        }

        $databaseEnd = $this->blockEnd($lines, $databaseIndex);

        for ($i = $databaseIndex + 1; $i < $databaseEnd; $i++) {
            if (preg_match('/^(\s{4}portforward:\s*)\S+/', $lines[$i])) {
                $lines[$i] = preg_replace('/^(\s{4}portforward:\s*)\S+/', '${1}'.$port, $lines[$i]);

                return implode($eol, $lines).$eol;
            }
        }

        array_splice($lines, $databaseIndex + 1, 0, ["    portforward: {$port}"]);

        return implode($eol, $lines).$eol;
    }

    /**
     * @param  list<string>  $lines
     */
    private function findTopLevelKey(array $lines, string $key): ?int
    {
        foreach ($lines as $index => $line) {
            if (preg_match('/^'.preg_quote($key, '/').':\s*(#.*)?$/', $line)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function findChildKey(array $lines, int $parentIndex, int $parentEnd, string $key): ?int
    {
        for ($i = $parentIndex + 1; $i < $parentEnd; $i++) {
            if (preg_match('/^  '.preg_quote($key, '/').':\s*(#.*)?$/', $lines[$i])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Index of the first line after $startIndex that is back at indent 0 or 2 (i.e. the next sibling key).
     *
     * @param  list<string>  $lines
     */
    private function blockEnd(array $lines, int $startIndex): int
    {
        $indent = strlen($lines[$startIndex]) - strlen(ltrim($lines[$startIndex], ' '));
        $count = count($lines);

        for ($i = $startIndex + 1; $i < $count; $i++) {
            $line = $lines[$i];

            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if (strlen($line) - strlen(ltrim($line, ' ')) <= $indent) {
                return $i;
            }
        }

        return $count;
    }
}
