<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\CommercialEmailDomains;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class NotCommercialEmailDomain implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && CommercialEmailDomains::matches($value)) {
            $fail('School email cannot use a personal email provider (e.g. Gmail, Yahoo, Outlook, iCloud). Please use an email address on your school\'s own domain.');
        }
    }
}
