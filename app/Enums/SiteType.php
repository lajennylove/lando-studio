<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of local site a row is. A type can need columns that other types do not use (astro_port only matters
 * for Astro), so extraFields() lists them for the code that shows or checks them.
 *
 * To add a type: add a case, give it a label and its extra fields, and add its creation path. Existing rows keep
 * their stored value.
 */
enum SiteType: string
{
    case Vanilla = 'vanilla';
    case Sage = 'sage';
    case Astro = 'astro';

    public function label(): string
    {
        return match ($this) {
            self::Vanilla => 'WordPress (vanilla)',
            self::Sage => 'WordPress with Sage theme',
            self::Astro => 'WordPress headless with Astro',
        };
    }

    /**
     * Columns on the sites table that only this type uses.
     *
     * @return list<string>
     */
    public function extraFields(): array
    {
        return match ($this) {
            self::Astro => ['astro_port'],
            default => [],
        };
    }
}
