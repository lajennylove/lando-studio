<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RepositoryAccess;
use PHPUnit\Framework\TestCase;

class RepositoryAccessTest extends TestCase
{
    public function test_host_is_taken_from_https_urls_in_lower_case(): void
    {
        $access = new RepositoryAccess;

        $this->assertSame('devops.cltbcanada.net', $access->hostFor('https://DevOps.CLTBCanada.net/Projects/Websites/_git/wp-lockerroom/'));
        $this->assertSame('github.com', $access->hostFor('https://github.com/acme/theme.git'));
    }

    public function test_host_is_null_for_urls_without_one(): void
    {
        $this->assertNull((new RepositoryAccess)->hostFor('/local/path/repo'));
    }

    public function test_a_successful_probe_is_ok(): void
    {
        $result = RepositoryAccess::classify(0, 'abc123 refs/heads/main', false);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['needsLogin']);
    }

    public function test_a_login_prompt_asks_for_a_login(): void
    {
        $result = RepositoryAccess::classify(128, "fatal: could not read Username for 'https://devops.example': terminal prompts disabled", false);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['needsLogin']);
        $this->assertSame('The repository asks for a username and password.', $result['message']);
    }

    public function test_a_rejected_login_says_so(): void
    {
        $result = RepositoryAccess::classify(128, 'fatal: Authentication failed for https://github.com/acme/theme.git/', true);

        $this->assertTrue($result['needsLogin']);
        $this->assertStringStartsWith('The repository rejected the username or password.', $result['message']);
    }

    public function test_network_failures_are_not_login_problems(): void
    {
        $result = RepositoryAccess::classify(128, "fatal: unable to access 'https://devops.example/': Could not resolve host: devops.example", false);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['needsLogin']);
        $this->assertStringContainsString('Could not resolve host', $result['message']);
    }
}
