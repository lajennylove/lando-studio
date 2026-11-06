<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Keeps the app key in the user's storage folder, so builds do not change it. Encrypted columns (SSH and
 * database passwords) stay readable after an update. Builds ship with an empty APP_KEY, and the key is
 * created on first use.
 */
final class PersistentAppKey
{
    /**
     * Returns the key stored at $path, creating it (mode 0600) when the file is missing or empty.
     * Writes to disk on first use only.
     */
    public static function resolve(string $path): string
    {
        $stored = is_file($path) ? trim((string) file_get_contents($path)) : '';

        if ($stored !== '') {
            return $stored;
        }

        $key = 'base64:'.base64_encode(random_bytes(32));

        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        file_put_contents($path, $key);
        chmod($path, 0600);

        return $key;
    }
}
