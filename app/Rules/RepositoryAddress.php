<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\RepositoryUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts https://, ssh:// and git@host:path repository addresses, for the theme and Astro repository fields.
 */
class RepositoryAddress implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! RepositoryUrl::isSupported($value)) {
            $fail('Use an https:// address, an ssh:// address, or the git@host:path form.');
        }
    }
}
