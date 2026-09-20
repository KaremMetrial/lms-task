<?php

declare(strict_types=1);

namespace App\Domain\Money\Exceptions;

use DomainException;

final class CurrencyMismatch extends DomainException
{
    public static function between(string $left, string $right): self
    {
        return new self(
            "Refusing to combine {$left} with {$right}. Cross-currency arithmetic needs an "
            .'explicit exchange rate and an audit trail, not an implicit assumption.'
        );
    }
}
