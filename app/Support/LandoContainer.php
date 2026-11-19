<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Names the Docker containers that Lando creates for a project, so the app can address them with docker exec.
 */
final class LandoContainer
{
    /**
     * Lando's app name for a project: the name lowercased with every character other than a letter or digit
     * removed, so "twg-casino" becomes "twgcasino". `lando list` reports apps under this name, and Docker container
     * and network names start with it.
     */
    public static function appName(string $project): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $project));
    }

    /**
     * Appserver container of a project, as Lando names it: "twg-casino" becomes "twgcasino_appserver_1".
     */
    public static function appserver(string $project): string
    {
        return self::appName($project).'_appserver_1';
    }
}
