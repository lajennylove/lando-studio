<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Recognises the repository addresses a site can use: https://, ssh:// and the scp-style git@host:path form.
 *
 * Only these three are accepted. Local paths and bare names are refused, because the clone runs git with no
 * prompt and needs a host to check credentials and host keys against.
 */
final class RepositoryUrl
{
    private const HTTPS = '#^https://(?:[^@/\s]+@)?([A-Za-z0-9.-]+)(?::\d+)?/\S+$#i';

    private const SSH = '#^ssh://(?:[^@/\s]+@)?([A-Za-z0-9.-]+)(?::\d+)?/\S+$#i';

    // Host of two or more characters, so a Windows drive letter (C:\repos) is not read as a host. No backslashes in
    // the path, for the same reason.
    private const SCP = '#^(?:([^@/\s:]+)@)?([A-Za-z0-9.-]{2,}):([^/\\\\\s][^\s\\\\]*)$#';

    /** True for https://host/path, ssh://[user@]host/path and [user@]host:path. */
    public static function isSupported(string $url): bool
    {
        $url = trim($url);

        return preg_match(self::HTTPS, $url) === 1
            || preg_match(self::SSH, $url) === 1
            || preg_match(self::SCP, $url) === 1;
    }

    /** True when the address uses SSH (ssh:// or the scp-style form), so the clone relies on an SSH key. */
    public static function isSsh(string $url): bool
    {
        $url = trim($url);

        return str_starts_with(strtolower($url), 'ssh://') || (! str_contains($url, '://') && preg_match(self::SCP, $url) === 1);
    }

    /** Lower-cased host of the address, or null when the address is not one of the supported forms. */
    public static function host(string $url): ?string
    {
        $url = trim($url);

        foreach ([self::HTTPS, self::SSH] as $pattern) {
            if (preg_match($pattern, $url, $m) === 1) {
                return strtolower($m[1]);
            }
        }

        if (! str_contains($url, '://') && preg_match(self::SCP, $url, $m) === 1) {
            return strtolower($m[2]);
        }

        return null;
    }
}
