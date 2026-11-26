<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The columns of the remote-site inventory CSV (export, import and the template).
 *
 * The first 13 columns are the original format. The Astro columns come after them and are optional on import, so an
 * inventory written before headless sites existed still imports, with no Astro project.
 */
final class RemoteSiteCsv
{
    /** @var list<string> */
    public const LEGACY_HEADERS = [
        'remote_domain', 'local_domain', 'ssh_server_ip', 'ssh_user', 'ssh_password',
        'remote_path', 'db_name', 'db_user', 'db_password', 'theme_name', 'repo_url',
        'install_composer_dependencies', 'install_node_dependencies',
    ];

    /** @var list<string> */
    public const HEADERS = [
        'remote_domain', 'local_domain', 'ssh_server_ip', 'ssh_user', 'ssh_password',
        'remote_path', 'db_name', 'db_user', 'db_password', 'theme_name', 'repo_url',
        'install_composer_dependencies', 'install_node_dependencies',
        'astro_repo_url', 'astro_branch', 'astro_folder',
    ];

    /**
     * The column list a file's header row describes, or null when the header is neither the current nor the legacy
     * format. A legacy file gets the Astro columns as empty values.
     *
     * @param  list<string>  $headers
     * @return list<string>|null
     */
    public static function columnsFor(array $headers): ?array
    {
        if ($headers === self::HEADERS) {
            return self::HEADERS;
        }

        if ($headers === self::LEGACY_HEADERS) {
            return self::LEGACY_HEADERS;
        }

        return null;
    }
}
