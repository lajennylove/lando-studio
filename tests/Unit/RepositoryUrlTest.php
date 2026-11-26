<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RepositoryAccess;
use App\Support\RepositoryUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RepositoryUrlTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function addresses(): array
    {
        return [
            'https' => ['https://git.example.test/team/site.git', true],
            'https with user' => ['https://user@git.example.test/team/site.git', true],
            'https with port' => ['https://git.example.test:8443/team/site.git', true],
            'ssh url' => ['ssh://git@git.example.test/team/site.git', true],
            'ssh url with port' => ['ssh://git@git.example.test:2222/team/site.git', true],
            'scp style' => ['git@git.example.test:team/site.git', true],
            'scp style without user' => ['git.example.test:team/site.git', true],
            'local path' => ['C:\\repos\\site', false],
            'unix path' => ['/home/git/site.git', false],
            'relative path' => ['../site', false],
            'bare name' => ['site', false],
            'empty' => ['', false],
            'scp with leading slash' => ['git@git.example.test:/team/site.git', false],
            'ftp' => ['ftp://git.example.test/site.git', false],
        ];
    }

    #[DataProvider('addresses')]
    public function test_only_https_ssh_and_scp_style_addresses_are_supported(string $address, bool $supported): void
    {
        $this->assertSame($supported, RepositoryUrl::isSupported($address));
    }

    /** @return array<string, array{string, ?string}> */
    public static function hosts(): array
    {
        return [
            'https' => ['https://Git.Example.Test/team/site.git', 'git.example.test'],
            'ssh url' => ['ssh://git@git.example.test:2222/team/site.git', 'git.example.test'],
            'scp style' => ['git@Git.Example.Test:team/site.git', 'git.example.test'],
            'scp style without user' => ['git.example.test:team/site.git', 'git.example.test'],
            'local path' => ['C:\\repos\\site', null],
        ];
    }

    #[DataProvider('hosts')]
    public function test_the_host_is_read_from_every_supported_form(string $address, ?string $host): void
    {
        $this->assertSame($host, RepositoryUrl::host($address));
        $this->assertSame($host, (new RepositoryAccess)->hostFor($address));
    }

    /** @return array<string, array{string, bool}> */
    public static function sshForms(): array
    {
        return [
            'ssh url' => ['ssh://git@git.example.test/team/site.git', true],
            'scp style' => ['git@git.example.test:team/site.git', true],
            'scp style without user' => ['git.example.test:team/site.git', true],
            'https' => ['https://git.example.test/team/site.git', false],
            'local path' => ['C:\\repos\\site', false],
        ];
    }

    #[DataProvider('sshForms')]
    public function test_ssh_addresses_are_recognised(string $address, bool $isSsh): void
    {
        $this->assertSame($isSsh, RepositoryUrl::isSsh($address));
    }
}
