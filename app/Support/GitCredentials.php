<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Hands a git login to git without putting it in a command line or a step log. The login is written to a
 * private file, and a fixed askpass script reads it through GIT_ASKPASS. Callers remove the file when the
 * git command ends.
 */
final class GitCredentials
{
    /** Folder under storage/app holding the private credential files and the askpass script. */
    private const FOLDER = 'git-credentials';

    /**
     * Path of the credential file for a key such as "site-12". The folder is created with mode 0700.
     */
    public static function fileFor(string $key): string
    {
        return self::folder().DIRECTORY_SEPARATOR.$key.'.cred';
    }

    /**
     * Path of the askpass script. Written once, mode 0700. It prints the username or password line from the
     * file named in LANDO_STUDIO_GIT_CREDENTIALS, depending on which prompt git is answering.
     */
    public static function askpassPath(): string
    {
        $path = self::folder().DIRECTORY_SEPARATOR.'askpass.sh';

        if (! is_file($path)) {
            file_put_contents($path, <<<'SH'
#!/bin/sh
# Answers git's username and password prompts from the file named in LANDO_STUDIO_GIT_CREDENTIALS.
case "$1" in
  *sername*) sed -n 1p "$LANDO_STUDIO_GIT_CREDENTIALS" ;;
  *) sed -n 2p "$LANDO_STUDIO_GIT_CREDENTIALS" ;;
esac
SH);
            chmod($path, 0700);
        }

        return $path;
    }

    /**
     * Writes the username on line 1 and the password on line 2, mode 0600. Rejects values with line breaks,
     * because they would break the two-line format.
     */
    public static function write(string $file, string $username, string $password): void
    {
        if ($username === '' || $password === '' || preg_match('/[\r\n]/', $username.$password)) {
            throw new \InvalidArgumentException('Repository username and password must be non-empty single lines.');
        }

        file_put_contents($file, $username."\n".$password."\n", LOCK_EX);
        chmod($file, 0600);
    }

    /**
     * Environment prefix for a POSIX shell command that reads the credential file. Contains paths only.
     */
    public static function shellPrefix(string $credentialFile): string
    {
        return 'GIT_ASKPASS='.escapeshellarg(self::askpassPath()).' LANDO_STUDIO_GIT_CREDENTIALS='.escapeshellarg($credentialFile).' ';
    }

    public static function remove(string $file): void
    {
        if (is_file($file)) {
            unlink($file);
        }
    }

    private static function folder(): string
    {
        $folder = storage_path('app'.DIRECTORY_SEPARATOR.self::FOLDER);

        if (! is_dir($folder)) {
            mkdir($folder, 0700, true);
        }

        return $folder;
    }
}
