<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The .htaccess of a headless site's WordPress folder. Requests that are not WordPress's own paths go to the Astro
 * server, which decides what each path returns. No route is listed here: the Astro project defines its routes.
 */
final class AstroProxyRules
{
    /** WordPress paths that stay with WordPress. Everything else that is not a real file goes to Astro. */
    private const WORDPRESS_PATHS = '^/(wp-admin|wp-content|wp-includes|wp-json|wp-login\.php|wp-cron\.php|xmlrpc\.php|index\.php)';

    /**
     * @param  string  $wordpressBlock  The WordPress block the Studio already writes (image proxy and front controller)
     */
    public static function htaccess(string $wordpressBlock, int $astroPort): string
    {
        $wordpressPaths = self::WORDPRESS_PATHS;

        return <<<HTACCESS
# BEGIN Astro front end
<IfModule mod_proxy.c>
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_URI} !{$wordpressPaths}
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)\$ http://127.0.0.1:{$astroPort}/\$1 [P,L]
</IfModule>
</IfModule>
# END Astro front end

{$wordpressBlock}
HTACCESS;
    }
}
