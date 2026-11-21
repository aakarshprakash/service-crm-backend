<?php

namespace App\Rules;

use App\Support\IndianStates;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A real GSTIN: right shape, a known state code and a correct check digit. When the form also
 * picks a state, the GSTIN must belong to that state (its first two digits are the state code).
 */
class Gstin implements ValidationRule
{
    public function __construct(private ?string $state = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $gstin = (string) $value;
        if (! preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/', $gstin)) {
            $fail('Enter the 15-character GSTIN, e.g. 32ABCDE1234F1Z9.');

            return;
        }
        if (! IndianStates::isValidGstin($gstin)) {
            $fail('This GSTIN is not valid: one of its characters is wrong. Copy it again from the GST certificate.');

            return;
        }
        $picked = IndianStates::code($this->state);
        $own = IndianStates::code($gstin);
        if ($picked && $own !== $picked) {
            $fail(sprintf('This GSTIN is registered in %s (it starts with %s), but the state chosen is %s. %s GSTINs start with %s.',
                IndianStates::name($own), $own, IndianStates::name($picked), IndianStates::name($picked), $picked));
        }
    }
}
