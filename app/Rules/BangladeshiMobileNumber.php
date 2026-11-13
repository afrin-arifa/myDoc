<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Bangladeshi mobile number in any common local or international form, e.g. 01712345678, 017-1234-5678,
 * +8801712345678, 8801712345678 or 008801712345678. Numbers are stored and sent as 8801XXXXXXXXX.
 */
class BangladeshiMobileNumber implements ValidationRule
{
    /**
     * Valid operator prefixes are 013 to 019, followed by eight digits.
     */
    private const PATTERN = '/^(?:\+?88|0088)?(01[3-9]\d{8})$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::normalize(is_string($value) ? $value : null) === null) {
            $fail('Enter a Bangladeshi mobile number, for example 01712345678 or +8801712345678.');
        }
    }

    /**
     * The number as 8801XXXXXXXXX, or null when it is empty or not a valid Bangladeshi mobile number. Spaces,
     * dashes, dots and brackets are ignored.
     */
    public static function normalize(?string $phone): ?string
    {
        $compact = preg_replace('/[\s\-().]/', '', (string) $phone);

        return preg_match(self::PATTERN, (string) $compact, $matches) === 1 ? '88'.$matches[1] : null;
    }
}
