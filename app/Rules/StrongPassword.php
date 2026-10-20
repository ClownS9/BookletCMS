<?php

namespace App\Rules;

use App\Support\PasswordStrength;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A new password that's hard to guess: long enough, not a well-known one,
 * not built from the person's own name or email or the site's name, and at
 * least "Fair" on the strength meter (the same reckoning as the browser's).
 */
class StrongPassword implements ValidationRule
{
    /** @param  array<int, ?string>  $personal */
    public function __construct(protected array $personal = []) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Type a password.');

            return;
        }

        $site = rescue(fn () => app(\App\Booklet\Site::class)->name(), '', false);

        if ($problem = PasswordStrength::problem($value, array_merge($this->personal, [$site]))) {
            $fail($problem);
        }
    }
}
