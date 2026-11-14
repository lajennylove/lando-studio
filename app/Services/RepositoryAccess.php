<?php

namespace App\Services;

use App\Support\GitCredentials;
use Illuminate\Support\Facades\Process;

/**
 * Checks whether a theme repository can be read, and whether it asks for a login.
 */
class RepositoryAccess
{
    /**
     * Host part of a repository URL (for example devops.cltbcanada.net), lower-cased, or null when the URL
     * has no host (SSH-style or local paths).
     */
    public function hostFor(string $repoUrl): ?string
    {
        $host = parse_url(trim($repoUrl), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * Runs `git ls-remote` against the repository with a 60s limit. Never prompts: when no login is given
     * and the repository asks for one, the result says so instead of hanging. With a login, the credentials go
     * through a private file read by the askpass script; the file is removed before this returns.
     *
     * @return array{ok: bool, needsLogin: bool, message: string}
     */
    public function probe(string $repoUrl, ?string $username = null, ?string $password = null): array
    {
        $file = null;
        $env = ['GIT_TERMINAL_PROMPT' => '0'];

        if ($username !== null && $password !== null) {
            $file = GitCredentials::fileFor('probe-'.bin2hex(random_bytes(6)));
            GitCredentials::write($file, $username, $password);
            $env += ['GIT_ASKPASS' => GitCredentials::askpassPath(), 'LANDO_STUDIO_GIT_CREDENTIALS' => $file];
        }

        try {
            $result = Process::timeout(60)->env($env)->run(['git', 'ls-remote', '--heads', $repoUrl]);

            return self::classify($result->exitCode() ?? 1, $result->output().$result->errorOutput(), $file !== null);
        } finally {
            if ($file !== null) {
                GitCredentials::remove($file);
            }
        }
    }

    /**
     * Turns the result of `git ls-remote` into an outcome. A login prompt, or a rejected login, is reported as
     * needsLogin so the clone can pause and ask for one. Any other failure (DNS, VPN, unknown host) is reported
     * with git's message.
     *
     * @return array{ok: bool, needsLogin: bool, message: string}
     */
    public static function classify(int $exitCode, string $output, bool $loginWasSent): array
    {
        if ($exitCode === 0) {
            return ['ok' => true, 'needsLogin' => false, 'message' => ''];
        }

        $asksForLogin = preg_match(
            '/could not read (Username|Password)|terminal prompts disabled|Authentication failed|HTTP[^\n]*40[13]|returned error: 40[13]/i',
            $output
        ) === 1;

        if ($asksForLogin) {
            return [
                'ok' => false,
                'needsLogin' => true,
                'message' => $loginWasSent
                    ? 'The repository rejected the username or password. Azure DevOps and some other hosts need a personal access token as the password.'
                    : 'The repository asks for a username and password.',
            ];
        }

        return [
            'ok' => false,
            'needsLogin' => false,
            'message' => trim(mb_substr(preg_replace('/\s+/', ' ', $output) ?? '', 0, 300)),
        ];
    }
}
