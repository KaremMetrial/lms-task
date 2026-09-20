<?php

declare(strict_types=1);

namespace App\Domain\Payouts\Provider;

use App\Domain\Payouts\FailureClass;
use App\Domain\Payouts\Provider\Exceptions\ProviderTimeout;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A deliberately unreliable payment provider.
 *
 * It keeps its own books in `mock_provider_transactions`, which is what lets it
 * behave like a real external system rather than a stub:
 *
 *   • It DEDUPES on idempotency_key. Send the same key twice and it returns the
 *     stored result without moving money again — the behaviour that makes our
 *     deterministic key worth having.
 *
 *   • It can move money and then lose the response. The row is committed, the
 *     caller gets a ProviderTimeout, and a later status() reveals the truth. The
 *     truth outlives the process, so a reconciler in another container finds it.
 *
 *   • It can time out having done nothing. status() then reports NotFound, and
 *     because the key is deterministic that is trustworthy: if the provider had
 *     ever seen this payout, it would have seen this key.
 *
 * The two timeout cases are indistinguishable to the caller at the moment they
 * happen. That is the point.
 */
final class MockPaymentProvider implements PaymentProvider
{
    public function __construct(
        private readonly OutcomeDecider $decider,
        private readonly ?ConnectionInterface $connection = null,
    ) {}

    private function db(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }

    public function send(PayoutRequest $request): ProviderResult
    {
        // Provider-side dedup, first thing. A real provider does this, and relying on
        // it is a second safety net under our own unique indexes.
        $existing = $this->find($request->idempotencyKey);

        if ($existing !== null) {
            return $this->resultFor($existing);
        }

        $outcome = $this->decider->decide($request);

        return match ($outcome) {
            MockOutcome::Succeed => $this->recordSuccess($request, responseLost: false),

            MockOutcome::FailPermanently => $this->recordFailure($request),

            // The money moves, the row commits, and THEN the connection drops. This
            // is the case that breaks naive implementations.
            MockOutcome::TimeoutAfterSuccess => $this->timeoutAfter(
                fn () => $this->recordSuccess($request, responseLost: true),
                $request,
            ),

            // Nothing is recorded at all, so status() will legitimately say NotFound.
            MockOutcome::TimeoutBeforeAnything => throw new ProviderTimeout(
                $request->idempotencyKey,
                'Connection dropped before the provider did anything.',
            ),
        };
    }

    public function status(string $idempotencyKey): ProviderResult
    {
        $transaction = $this->find($idempotencyKey);

        if ($transaction === null) {
            // No record. Because the key is derived rather than random, this is a
            // real answer and not an artefact of having asked with the wrong key.
            return ProviderResult::notFound();
        }

        return $this->resultFor($transaction);
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function find(string $idempotencyKey): ?object
    {
        return $this->db()->table('mock_provider_transactions')
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function recordSuccess(PayoutRequest $request, bool $responseLost): ProviderResult
    {
        $reference = 'mpt_'.Str::lower(Str::random(18));

        $this->db()->table('mock_provider_transactions')->insert([
            'idempotency_key' => $request->idempotencyKey,
            'account_ref' => $request->accountRef,
            'amount_minor' => $request->amount->minor,
            'currency' => $request->amount->currency,
            'outcome' => 'succeeded',
            'provider_reference' => $reference,
            'response_lost' => $responseLost,
            'created_at' => now(),
        ]);

        return ProviderResult::succeeded($reference);
    }

    private function recordFailure(PayoutRequest $request): ProviderResult
    {
        $reason = 'Beneficiary account rejected the transfer.';

        $this->db()->table('mock_provider_transactions')->insert([
            'idempotency_key' => $request->idempotencyKey,
            'account_ref' => $request->accountRef,
            'amount_minor' => $request->amount->minor,
            'currency' => $request->amount->currency,
            'outcome' => 'failed',
            'failure_reason' => $reason,
            'response_lost' => false,
            'created_at' => now(),
        ]);

        return ProviderResult::failed(FailureClass::Permanent, $reason);
    }

    /**
     * Do the work, commit it, then lose the response.
     *
     * @param  callable(): ProviderResult  $work
     */
    private function timeoutAfter(callable $work, PayoutRequest $request): never
    {
        $work();

        throw new ProviderTimeout(
            $request->idempotencyKey,
            'The transfer completed but the response never reached the caller.',
        );
    }

    private function resultFor(object $transaction): ProviderResult
    {
        if ($transaction->outcome === 'succeeded') {
            return ProviderResult::succeeded((string) $transaction->provider_reference);
        }

        return ProviderResult::failed(
            FailureClass::Permanent,
            (string) ($transaction->failure_reason ?? 'Rejected by the provider.'),
        );
    }
}
