<?php

namespace App\Services\Finance;

use Illuminate\Validation\ValidationException;

/** Internal fixed event shapes. No HTTP endpoint accepts arbitrary entries. */
final readonly class Posting
{
    private function __construct(public string $type, public array $members, public array $money, public ?int $related = null, public ?int $openingMember = null, public ?int $openingValue = null) {}

    public static function opening(string $target, ?int $member, int $value, int $delta, ?int $previous): self
    {
        abort_unless(in_array($target, ['member', 'card', 'custody'], true), 422);
        abort_unless(($target === 'card') === ($member === null), 422);
        abort_if($target === 'custody' && $value < 0, 422, 'Непереданный остаток не может быть отрицательным.');

        return new self('opening_'.$target, $target === 'member' ? [$member => $delta] : [],
            $target === 'member' ? [] : [self::cash($target === 'card' ? null : $member, $delta)], $previous, $member, $value);
    }

    public static function charge(int $member, int $amount): self
    {
        self::positive($amount);

        return new self('charge', [$member => -$amount], []);
    }

    public static function receipt(int $member, int $amount, ?int $custodian = null): self
    {
        self::positive($amount);

        return new self($custodian ? 'cash_receipt' : 'card_receipt', [$member => $amount], [self::cash($custodian, $amount)]);
    }

    public static function transfer(int $custodian, int $amount): self
    {
        self::positive($amount);

        return new self('cash_transfer', [], [self::cash($custodian, -$amount), self::cash(null, $amount)]);
    }

    public static function expense(int $amount): self
    {
        self::positive($amount);

        return new self('expense', [], [self::cash(null, -$amount)]);
    }

    public static function reverseCharge(int $member, int $amount, int $event): self
    {
        self::positive($amount);

        return new self('charge_reversal', [$member => $amount], [], $event);
    }

    private static function positive(int $amount): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount_minor' => 'Сумма должна быть больше нуля.']);
        }
    }

    private static function cash(?int $member, int $amount): array
    {
        return ['location' => $member === null ? 'card' : 'custody', 'custodian_membership_id' => $member, 'amount_minor' => $amount];
    }
}
