<?php

declare(strict_types=1);

namespace App\Domain\Allocation\Exceptions;

use InvalidArgumentException;

final class UnknownAllocationStrategy extends InvalidArgumentException
{
    /** @param list<string> $known */
    public static function named(string $name, array $known): self
    {
        return new self(sprintf(
            'No allocation strategy registered as "%s". Known strategies: %s. A historical '
            .'allocation referencing an unregistered strategy cannot be re-explained, so this '
            .'fails loudly rather than falling back to a default.',
            $name,
            implode(', ', $known),
        ));
    }
}
