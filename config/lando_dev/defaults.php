<?php

/*
|--------------------------------------------------------------------------
| Lando / container defaults (WordPress projects)
|--------------------------------------------------------------------------
| These versions are written into .lando.yml and apply to PHP, DB, and Redis *inside* Lando.
| They are not the Homebrew PHP used to run this Laravel + NativePHP desktop app on the host.
|--------------------------------------------------------------------------
*/

return [
    'php_version' => '8.3',
    // Newest PHP Lando 3.26 supports is 8.5 (see plugins/@lando/php builders/php.js).
    'php_versions' => ['8.5', '8.4', '8.3', '8.2', '8.1', '8.0'],
    'db_type' => 'mariadb',
    // Newest MariaDB Lando 3.26 supports (see plugins/@lando/mariadb builders/mariadb.js).
    'db_version' => '11.8',
    'db_versions' => ['11.8', '11.7', '11.4', '10.11', '10.6', '10.5'],
    // Lando v3 on Windows does not support Redis 7.2 or 7.4. macOS/Linux support up to 8.6 (Lando 3.26).
    'redis_version' => PHP_OS_FAMILY === 'Windows' ? '7.0' : '8.6',
    'redis_versions' => PHP_OS_FAMILY === 'Windows'
        ? ['7.0', '6.2', '6.0']
        : ['8.6', '8.4', '8.2', '8.0', '7.4', '7.2', '7.0', '6.2', '6.0'],
    // Host path for new Lando sites; default ~/code/sites/ is resolved in PlatformDetector (cross-platform).
    'code_path' => env('LANDODEV_CODE_PATH', null),
    'db_name' => 'wordpress',
    'db_user' => 'wordpress',
    'db_password' => 'wordpress',
    'db_host' => 'database',
    // Host port published for MariaDB (each Lando site must use a unique value).
    'database_forward_port_start' => (int) env('LANDO_DATABASE_FORWARD_PORT_START', 32_787),
];
