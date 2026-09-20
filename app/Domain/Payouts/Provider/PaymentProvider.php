<?php

declare(strict_types=1);

namespace App\Domain\Payouts\Provider;

use App\Domain\Payouts\Provider\Exceptions\ProviderTimeout;

interface PaymentProvider
{
    /**
     * Attempt a payout.
     *
     * @throws ProviderTimeout when the call did not return an answer. This is NOT a
     *                         failure: the provider may already have moved the money.
     *                         The only safe response is to record the outcome as
     *                         unknown and ask later — never to resend.
     */
    public function send(PayoutRequest $request): ProviderResult;

    /**
     * Ask what happened to a previously sent payout.
     *
     * This is the ONLY way an unknown outcome is ever resolved. It is a read, so it
     * is safe to call any number of times — which is precisely why the retry path
     * is a status check and not a resend.
     */
    public function status(string $idempotencyKey): ProviderResult;
}
