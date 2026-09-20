<?php

declare(strict_types=1);

namespace App\Domain\Payouts\Provider;

/**
 * Randomised outcomes for manual runs and demos, weighted from config.
 *
 * Never used in tests. A test that depends on randomness proves nothing when it
 * passes and cannot be debugged when it fails — tests use ScriptedOutcomes.
 */
final class WeightedRandomOutcomes implements OutcomeDecider
{
    /** @var array<string, int> */
    private readonly array $weights;

    /**
     * @param  array<string, int>  $weights  keyed by MockOutcome values
     */
    public function __construct(array $weights)
    {
        // Validate at construction, not at the moment of a payout.
        //
        // The config previously carried keys that were not MockOutcome values, and
        // the mismatch surfaced as an exception mid-run — after a payout item had
        // already been moved to 'submitted'. Failing here instead means a typo is a
        // boot-time error rather than a stranded payment.
        foreach (array_keys($weights) as $key) {
            if (MockOutcome::tryFrom((string) $key) === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown mock provider outcome "%s". Valid values: %s.',
                    $key,
                    implode(', ', array_column(MockOutcome::cases(), 'value')),
                ));
            }
        }

        $this->weights = $weights;
    }

    public function decide(PayoutRequest $request): MockOutcome
    {
        $total = array_sum($this->weights);

        if ($total <= 0) {
            return MockOutcome::Succeed;
        }

        $roll = random_int(1, $total);
        $cursor = 0;

        foreach ($this->weights as $outcome => $weight) {
            $cursor += $weight;

            if ($roll <= $cursor) {
                return MockOutcome::from($outcome);
            }
        }

        return MockOutcome::Succeed;
    }
}
