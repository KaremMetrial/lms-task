<?php

declare(strict_types=1);

namespace App\Domain\Payouts\Provider;

/**
 * A fixed queue of outcomes, so every failure branch is exercised on purpose.
 *
 * Once the script runs out, it repeats the last entry — so a test that scripts one
 * timeout and then calls status() ten times does not fall off the end.
 */
final class ScriptedOutcomes implements OutcomeDecider
{
    /** @var list<MockOutcome> */
    private array $remaining;

    private MockOutcome $last;

    public function __construct(MockOutcome ...$outcomes)
    {
        $this->remaining = $outcomes;
        $this->last = $outcomes[array_key_last($outcomes)] ?? MockOutcome::Succeed;
    }

    public function decide(PayoutRequest $request): MockOutcome
    {
        if ($this->remaining === []) {
            return $this->last;
        }

        return array_shift($this->remaining);
    }
}
