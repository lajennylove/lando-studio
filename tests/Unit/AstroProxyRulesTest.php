<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AstroProxyRules;
use PHPUnit\Framework\TestCase;

class AstroProxyRulesTest extends TestCase
{
    private const WORDPRESS_BLOCK = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n";

    public function test_every_other_request_goes_to_the_astro_port(): void
    {
        $htaccess = AstroProxyRules::htaccess(self::WORDPRESS_BLOCK, 4321);

        $this->assertStringContainsString('RewriteRule ^(.*)$ http://127.0.0.1:4321/$1 [P,L]', $htaccess);
    }

    public function test_wordpress_paths_are_left_to_wordpress(): void
    {
        $htaccess = AstroProxyRules::htaccess(self::WORDPRESS_BLOCK, 4321);

        foreach (['wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login\.php'] as $path) {
            $this->assertStringContainsString($path, $htaccess);
        }
    }

    public function test_no_site_route_is_listed(): void
    {
        $htaccess = AstroProxyRules::htaccess(self::WORDPRESS_BLOCK, 4321);

        $this->assertStringNotContainsString('insider', $htaccess);
        $this->assertStringNotContainsString('casino', $htaccess);
    }

    public function test_the_wordpress_block_is_kept_after_the_proxy_block(): void
    {
        $htaccess = AstroProxyRules::htaccess(self::WORDPRESS_BLOCK, 4321);

        $this->assertStringContainsString(self::WORDPRESS_BLOCK, $htaccess);
        $this->assertGreaterThan(strpos($htaccess, '# END Astro front end'), strpos($htaccess, '# BEGIN WordPress'));
    }
}
