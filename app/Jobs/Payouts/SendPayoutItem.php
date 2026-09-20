<?php

declare(strict_types=1);

namespace App\Jobs\Payouts;

use App\Actions\Payouts\PayoutItemTransitions;
use App\Domain\Payouts\FailureClass;
use App\Domain\Payouts\PayoutItemStatus;
use App\Domain\Payouts\Provider\Exceptions\ProviderTimeout;
use App\Domain\Payouts\Provider\PaymentProvider;
use App\Domain\Payouts\Provider\PayoutRequest;
use App\Domain\Payouts\Provider\ProviderOutcome;
use App\Models\PayoutItem;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends one payout to the provider.
 *
 * ── The rule this job exists to enforce ──────────────────────────────────────
 *
 * **Laravel's retry mechanism must never resend a payment.**
 *
 * So a provider timeout is CAUGHT here and turned into state (`unknown` plus a
 * scheduled status check). It is not rethrown. If it were, the queue would retry
 * this job, the job would send again, and an instructor whose money already moved
 * would be paid twice. The only thing that ever runs again is a status *check*.
 *
 * ── What happens if the worker is killed ─────────────────────────────────────
 *
 * The item was moved to 'submitted' and committed BEFORE the provider call. So:
 *
 *   • Laravel retries this job → the conditional update requires 'pending', finds
 *     'submitted', returns false, and the job exits without sending.
 *   • Nobody retries it → the item sits in 'submitted' until the reconcile sweep
 *     ages it into 'unknown' and asks the provider.
 *
 * Either way the money is asked about, never re-sent. `ShouldBeUnique` reduces
 * wasted duplicate work but is explicitly NOT the correctness mechanism — a cache
 * lock can expire, and a unique index cannot.
 */
final class SendPayoutItem implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * Never fail the job because the worker's own timeout fired: that would put it
     * back on the queue in an unknown state. Provider uncertainty is handled in
     * state, not by the queue.
     */
    public bool $failOnTimeout = false;

    public function __construct(public readonly int $payoutItemId)
    {
        $this->onQueue('payouts');
    }

    public function uniqueId(): string
    {
        return "payout-item:{$this->payoutItemId}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(PaymentProvider $provider, PayoutItemTransitions $transitions): void
    {
        $item = PayoutItem::query()->find($this->payoutItemId);

        if ($item === null) {
            return;
        }

        // Already resolved by someone else, or already in flight. Nothing to do —
        // and in particular, nothing to resend.
        if ($item->status !== PayoutItemStatus::Pending) {
            return;
        }

        $instructor = $item->instructor;

        if ($instructor?->payout_account_ref === null) {
            $transitions->releaseAsFailed(
                $item,
                FailureClass::Permanent,
                'Instructor has no payout account on file.',
            );

            return;
        }

        // ── Write-ahead: durable intent, then the side effect ──────────────────
        if (! $transitions->claimForSending($item)) {
            return;
        }

        $item->refresh();

        $request = new PayoutRequest(
            idempotencyKey: $item->idempotency_key,
            instructorId: $item->instructor_id,
            accountRef: $instructor->payout_account_ref,
            amount: $item->amount_minor,
        );

        try {
            $result = $provider->send($request);
        } catch (ProviderTimeout $e) {
            // THE case. Not a failure — the money may already have moved.
            $transitions->markUnknown(
                $item,
                CarbonImmutable::now()->addSeconds($this->firstBackoffSeconds()),
                $e->getMessage(),
            );

            ReconcilePayoutItem::dispatch($item->id)
                ->delay(now()->addSeconds($this->firstBackoffSeconds()));

            Log::warning('Payout outcome unknown; scheduled a status check.', [
                'payout_item_id' => $item->id,
                'idempotency_key' => $item->idempotency_key,
            ]);

            return;
        }

        match ($result->outcome) {
            ProviderOutcome::Succeeded => $transitions->settleAsSucceeded(
                $item,
                (string) $result->reference,
            ),

            ProviderOutcome::Failed => $transitions->releaseAsFailed(
                $item,
                $result->failureClass ?? FailureClass::Permanent,
                (string) ($result->message ?? 'Rejected by the provider.'),
            ),

            // Accepted but still processing, or the provider answered with no record
            // despite having taken the call. Both are uncertainty, so both hold the
            // reservation and wait for a status check.
            ProviderOutcome::Pending,
            ProviderOutcome::NotFound => $this->deferToStatusCheck($item, $transitions, $result->outcome),
        };
    }

    private function deferToStatusCheck(
        PayoutItem $item,
        PayoutItemTransitions $transitions,
        ProviderOutcome $outcome,
    ): void {
        $transitions->markUnknown(
            $item,
            CarbonImmutable::now()->addSeconds($this->firstBackoffSeconds()),
            "Provider returned {$outcome->value}; awaiting a definitive status.",
        );

        ReconcilePayoutItem::dispatch($item->id)->delay(now()->addSeconds($this->firstBackoffSeconds()));
    }

    private function firstBackoffSeconds(): int
    {
        /** @var list<int> $schedule */
        $schedule = config('ledger.payout.status_check_backoff_seconds', [30]);

        return $schedule[0] ?? 30;
    }
}
