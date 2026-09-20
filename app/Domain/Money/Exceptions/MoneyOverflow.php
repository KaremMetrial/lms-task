<?php

declare(strict_types=1);

namespace App\Domain\Money\Exceptions;

use RuntimeException;

final class MoneyOverflow extends RuntimeException
{
    public static function forProduct(int $a, int $b): self
    {
        return new self(
            "Refusing to compute {$a} × {$b}: the result exceeds PHP_INT_MAX and would silently "
            .'become a float. Failing loudly is the only safe option in the money path.'
        );
    }
}
