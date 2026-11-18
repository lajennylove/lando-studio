<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Names the Docker containers that Lando creates for a project, so the app can address them with docker exec.
 */
final class LandoContainer
{
    /**
     * Appserver container of a project, as Lando names it: the project name lowercased with every character
     * other than a letter or digit removed, so "twg-casino" becomes "twgcasino_appserver_1". Docker Compose
     * project names are lowercase, and Lando drops the hyphens from them.
     */
    public static function appserver(string $project): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $project)).'_appserver_1';
    }
}
