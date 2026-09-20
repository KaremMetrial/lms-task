<?php

declare(strict_types=1);

namespace App\Casts;

use App\Domain\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a BIGINT minor-unit column to a Money value object.
 *
 * The currency is read from a sibling column so an amount is never separated from
 * its unit. Models declare it as:
 *
 *     'amount_minor' => MoneyCast::class,
 *
 * Reading always yields Money.
 *
 * Writing is declared as `mixed` rather than `Money|int`, because that is the
 * truth: Eloquent hands set() whatever the caller assigned, and a float or a
 * numeric string really can arrive. Narrowing the template to the intended inputs
 * would make the runtime guard below statically unreachable — and a guard that the
 * type system has optimised away is no guard at all. The accepted inputs are
 * enforced in code, not asserted in a docblock.
 *
 * @implements CastsAttributes<Money, mixed>
 */
final class MoneyCast implements CastsAttributes
{
    public function __construct(private string $currencyColumn = 'currency') {}

    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::of(
            (int) $value,
            (string) ($attributes[$this->currencyColumn] ?? config('ledger.currency')),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, int|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if ($value instanceof Money) {
            return [$key => $value->minor];
        }

        // A plain integer is accepted: the column is named *_minor and its currency
        // lives in the sibling column, so nothing is ambiguous. Factories, seeders
        // and migrations write this way.
        if (is_int($value)) {
            return [$key => $value];
        }

        // A float is refused, and this is the guard that earns its place. Casting
        // 29.99 to int silently yields 29 — the amount is wrong, nothing throws, and
        // the mistake surfaces as an unexplained balance months later. A string is
        // refused for the same reason: '29.99' truncates just as quietly.
        throw new \InvalidArgumentException(sprintf(
            'Attribute %s accepts %s or an integer number of minor units, got %s. Floats and '
            .'numeric strings are refused because truncating them loses money silently.',
            $key,
            Money::class,
            get_debug_type($value),
        ));
    }
}
