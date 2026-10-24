<?php

namespace App\Services\Finance;

use Illuminate\Validation\ValidationException;
use NumberFormatter;

final class Money
{
    public static function minor(mixed $value): int
    {
        if (! is_string($value) || ! preg_match('/^-?(0|[1-9][0-9]{0,18})$/D', $value) || $value === '-0') {
            throw ValidationException::withMessages(['amount_minor' => 'Передайте сумму строкой в минимальных денежных единицах.']);
        }
        $absolute = ltrim($value, '-');
        $maximum = (string) PHP_INT_MAX;
        if (strlen($absolute) > strlen($maximum) || (strlen($absolute) === strlen($maximum) && strcmp($absolute, $maximum) > 0)) {
            throw ValidationException::withMessages(['amount_minor' => 'Сумма превышает техническую точность учета.']);
        }

        return (int) $value;
    }

    public static function add(int $a, int $b): int
    {
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < -PHP_INT_MAX - $b)) {
            throw ValidationException::withMessages(['amount_minor' => 'Итоговая сумма превышает техническую точность учета.']);
        }

        return $a + $b;
    }

    public static function scale(string $currency): int
    {
        $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency);

        return (int) $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
    }
}
