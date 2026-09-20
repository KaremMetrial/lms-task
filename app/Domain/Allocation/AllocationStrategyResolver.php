<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use App\Domain\Allocation\Exceptions\UnknownAllocationStrategy;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves allocation strategies by name.
 *
 * Two distinct jobs, and keeping both here is the point:
 *
 *   active()      — the strategy new allocations should use.
 *   byName($name) — the strategy a PAST allocation recorded, so a historical
 *                   split can still be re-derived and explained after the
 *                   platform has switched rules.
 *
 * An unregistered name throws. Silently falling back to the current default would
 * mean re-explaining an old split with a rule that never touched it, which is a
 * worse outcome than an error.
 */
final readonly class AllocationStrategyResolver
{
    public function __construct(
        private Container $container,
        /** @var array<string, class-string<RevenueAllocationStrategy>> */
        private array $strategies,
        private string $activeName,
    ) {}

    public function active(): RevenueAllocationStrategy
    {
        return $this->byName($this->activeName);
    }

    public function activeName(): string
    {
        return $this->activeName;
    }

    public function byName(string $name): RevenueAllocationStrategy
    {
        if (! isset($this->strategies[$name])) {
            throw UnknownAllocationStrategy::named($name, array_keys($this->strategies));
        }

        return $this->container->make($this->strategies[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->strategies);
    }
}
