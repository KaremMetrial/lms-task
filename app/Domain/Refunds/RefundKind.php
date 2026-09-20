<?php

declare(strict_types=1);

namespace App\Domain\Refunds;

enum RefundKind: string
{
    /**
     * The normal path: the student gets back the unconsumed portion of the term.
     * Because revenue is recognised ratably, that portion was never earned — so
     * no instructor balance is touched at all.
     */
    case Prorata = 'prorata';

    /**
     * The exception: fraud, chargeback. Already-earned money is recovered, which
     * writes clawback entries and can drive a balance negative.
     */
    case Full = 'full';
}
