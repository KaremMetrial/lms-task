<?php

declare(strict_types=1);

namespace App\Domain\Payouts\Provider;

interface OutcomeDecider
{
    public function decide(PayoutRequest $request): MockOutcome;
}
