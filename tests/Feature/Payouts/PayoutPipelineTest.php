<?php

declare(strict_types=1);

use App\Actions\Payouts\ClaimPayoutBatch;
use App\Actions\Payouts\FanOutPayoutBatch;
use App\Actions\Payouts\PayoutItemTransitions;
use App\Domain\Money\Money;
use App\Domain\Payouts\FailureClass;
use App\Domain\Payouts\PayoutItemStatus;
use App\Domain\Payouts\Provider\MockOutcome;
use App\Domain\Payouts\Provider\MockPaymentProvider;
use App\Domain\Payouts\Provider\OutcomeDecider;
use App\Domain\Payouts\Provider\PaymentProvider;
use App\Domain\Payouts\Provider\PayoutRequest;
use App\Domain\Payouts\Provider\ScriptedOutcomes;
use App\Jobs\Payouts\ReconcilePayoutItem;
use App\Jobs\Payouts\SendPayoutItem;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Every provider outcome is SCRIPTED, never random.
 *
 * The real MockPaymentProvider is used — only its outcome decider is swapped. So
 * these tests exercise the actual production code path, including the provider's own
 * dedup table, rather than a stub that agrees with our assumptions.
 */
function scriptProvider(MockOutcome ...$outcomes): void
{
    app()->bind(OutcomeDecider::class, fn () => new ScriptedOutcomes(...$outcomes));
    app()->bind(PaymentProvider::class, fn ($app) => new MockPaymentProvider(
        $app->make(OutcomeDecider::class)
    ));
}

/** An instructor who has earned $minor and is ready to be paid. */
function owedInstructor(int $minor = 50_000): Instructor
{
    $instructor = Instructor::factory()->create();

    InstructorBalance::factory()->for($instructor)->owed($minor)->create();

    return $instructor;
}

function itemFor(Instructor $instructor, int $minor = 50_000, string $period = '2026-01'): PayoutItem
{
    $batch = PayoutBatch::factory()->forPeriod($period)->create();

    return PayoutItem::factory()->create([
        'payout_batch_id' => $batch->id,
        'instructor_id' => $instructor->id,
        'amount_minor' => $minor,
        'idempotency_key' => PayoutItem::idempotencyKeyFor($batch->id, $instructor->id),
    ]);
}

function balanceOf(Instructor $instructor): InstructorBalance
{
    return InstructorBalance::findOrFail($instructor->id);
}

/**
 * Run a status check the way a queue worker would: resolve handle()'s dependencies
 * from the container and invoke it.
 *
 * Deliberately not ReconcilePayoutItem::dispatchSync() — where the follow-up job is
 * partially faked, the fake would swallow this explicit call too, and the test would
 * silently assert nothing.
 */
function reconcile(PayoutItem $item): void
{
    app()->call([new ReconcilePayoutItem($item->id), 'handle']);
}

// ═════════════════════════════════════════════════════════════════════════════
// 1. The happy path, so the rest has a baseline
// ═════════════════════════════════════════════════════════════════════════════

it('pays an instructor and records it once', function () {
    scriptProvider(MockOutcome::Succeed);

    $instructor = owedInstructor(50_000);
    $item = itemFor($instructor, 50_000);

    SendPayoutItem::dispatchSync($item->id);

    $item->refresh();
    $balance = balanceOf($instructor);

    expect($item->status)->toBe(PayoutItemStatus::Succeeded)
        ->and($item->provider_reference)->not->toBeNull()
        ->and($balance->paid_minor->minor)->toBe(50_000)
        ->and($balance->reserved_minor->isZero())->toBeTrue()
        ->and($balance->available_minor->isZero())->toBeTrue()
        ->and(LedgerEntry::where('type', 'payout')->count())->toBe(1);
});

// ═════════════════════════════════════════════════════════════════════════════
// 2. REQUIRED: running the payout process twice never double-pays
// ═════════════════════════════════════════════════════════════════════════════

it('running the payout command twice never double-pays', function () {
    scriptProvider(MockOutcome::Succeed);

    $instructor = owedInstructor(50_000);

    $this->artisan('payouts:run', ['--period' => '2026-01', '--sync' => true])->assertSuccessful();
    $this->artisan('payouts:run', ['--period' => '2026-01', '--sync' => true])->assertSuccessful();

    expect(PayoutBatch::count())->toBe(1)
        ->and(PayoutItem::count())->toBe(1)
        ->and(LedgerEntry::where('type', 'payout')->count())->toBe(1)
        ->and(balanceOf($instructor)->paid_minor->minor)->toBe(50_000)
        // The provider was asked to move money exactly once.
        ->and(DB::table('mock_provider_transactions')->count())->toBe(1);
});

it('a third and fourth run still pay nothing further', function () {
    scriptProvider(MockOutcome::Succeed);

    $instructor = owedInstructor(50_000);

    foreach (range(1, 4) as $ignored) {
        $this->artisan('payouts:run', ['--period' => '2026-01', '--sync' => true])->assertSuccessful();
    }

    expect(balanceOf($instructor)->paid_minor->minor)->toBe(50_000)
        ->and(DB::table('mock_provider_transactions')->count())->toBe(1);
});

it('two concurrent runs resolve to a single batch', function () {
    // Both callers reach ClaimPayoutBatch for the same period. The unique index on
    // period_key means one creates and the other attaches — never two batches.
    $claim = app(ClaimPayoutBatch::class);

    $first = $claim->execute('2026-03');
    $second = $claim->execute('2026-03');

    expect($first->wasCreated)->toBeTrue()
        ->and($second->wasCreated)->toBeFalse()
        ->and($second->batch->id)->toBe($first->batch->id)
        ->and(PayoutBatch::count())->toBe(1);
});

// ═════════════════════════════════════════════════════════════════════════════
// 3. REQUIRED: retried jobs never double-pay
// ═════════════════════════════════════════════════════════════════════════════

it('a retried send job never sends twice', function () {
    scriptProvider(MockOutcome::Succeed);

    $instructor = owedInstructor(50_000);
    $item = itemFor($instructor, 50_000);

    // The queue hands the same job back five times, as it would after crashes.
    foreach (range(1, 5) as $ignored) {
        SendPayoutItem::dispatchSync($item->id);
    }

    expect(balanceOf($instructor)->paid_minor->minor)->toBe(50_000)
        ->and(LedgerEntry::where('type', 'payout')->count())->toBe(1)
        ->and(DB::table('mock_provider_transactions')->count())->toBe(1);
});

it('a job retried after the worker was killed mid-flight does not resend', function () {
    // Simulating a SIGKILL between recording intent and recording the answer: the
    // item is left in 'submitted' with the reservation placed.
    $instructor = owedInstructor(50_000);
    $item = itemFor($instructor, 50_000);

    app(PayoutItemTransitions::class)->claimForSending($item);
    $item->refresh();

    expect($item->status)->toBe(PayoutItemStatus::Submitted)
        // Write-ahead worked: the hold exists even though nothing was sent.
        ->and(balanceOf($instructor)->reserved_minor->minor)->toBe(50_000)
        ->and(balanceOf($instructor)->available_minor->isZero())->toBeTrue();

    // Laravel retries the job. It requires 'pending', sees 'submitted', and stops.
    scriptProvider(MockOutcome::Succeed);
    SendPayoutItem::dispatchSync($item->id);

    expect($item->fresh()->status)->toBe(PayoutItemStatus::Submitted)
        // Nothing was sent, so the provider has no record at all.
        ->and(DB::table('mock_provider_transactions')->count())->toBe(0)
        ->and(LedgerEntry::where('type', 'payout')->count())->toBe(0);
});

it('the conditional update is what stops a concurrent second worker', function () {
    $instructor = owedInstructor(50_000);
    $item = itemFor($instructor, 50_000);

    $transitions = app(PayoutItemTransitions::class);

    expect($transitions->claimForSending($item))->toBeTrue()
        // Second worker, same item: compare-and-swap fails and it backs off.
        ->and($transitions->claimForSending($item->fresh()))->toBeFalse()
        // Crucially the hold was placed ONCE, not twice.
        ->and(balanceOf($instructor)->reserved_minor->minor)->toBe(50_000);
});

// ═════════════════════════════════════════════════════════════════════════════
// 4. REQUIRED: unreliable provider responses never cause duplicate payments
// ═════════════════════════════════════════════════════════════════════════════

it('a timeout after the money moved does not pay twice, and is settled by asking', function () {
    // THE scenario. The provider moves the money, then the response is lost.
    scriptProvider(MockOutcome::TimeoutAfterSuccess);

    // PARTIAL fake: only the follow-up status check is held back, so we can inspect
    // the intermediate 'unknown' state. Two reasons it has to be partial:
    //   • under the sync driver a delayed dispatch runs immediately, which would
    //     settle the item before we looked at it;
    //   • a full Queue::fake() also swallows dispatchSync for a ShouldQueue job, so
    //     the send under test would never run at all.
    Queue::fake([ReconcilePayoutItem::class]);

    $instructor = owedInstructor(50_000);
    $item = itemFor($instructor, 50_000);

    SendPayoutItem::dispatchSync($item->id);
    $item->refresh();

    // The send path scheduled its own resolution rather than leaving it to chance.
    Queue::assertPushed(ReconcilePayoutItem::class);

    // Unknown, NOT failed. The money is held: neither paid nor available.
    expect($item->status)->toBe(PayoutItemStatus::Unknown)
        ->and($item->next_check_at)->not->toBeNull()
        ->and(balanceOf($instructor)->reserved_minor->minor)->toBe(50_000)
        ->and(balanceOf($instructor)->paid_minor->isZero())->toBeTrue()
        ->and(balanceOf($instructor)->available_minor->isZero())->toBeTrue()
        // The provider did move it — our side simply does not know yet.
        ->and(DB::table('mock_provider_transactions')->where('outcome', 'succeeded')->count())->toBe(1)
        ->and(LedgerEntry::where('type', 'payout')->count())->toBe(0);

    // The status check discovers the truth. No second transfer.
    reconcile($item);
    $item->refresh();

    expect($item->status)->toBe(PayoutItemStatus::Succeeded)
        ->and(balanceOf($instructor)->paid_minor->minor)->toBe(50_000)
        ->and(balanceOf($instructor)->reserved_minor->isZero())->toBeTrue()
        ->and(LedgerEntry::where('type', 'payout')->count())->toBe(1)
        // Still exactly one transfer at the provider.
        ->and(DB::table('mock_provider_transactions')->count())->toBe(1);
});

it('a timeout where nothing happened releases the money for the next run', function () {
    // Indistinguishable from the case above at the moment it happens — which is why
    // both must lead to 'unknown' and be resolved by asking.
    scriptProvider(MockOutcome::TimeoutBeforeAnything);
    Queue::fake([ReconcilePayoutItem::class]);

    $instructor = owedInstructor(50_000);
    $item = itemFor($instructor, 50_000);

    SendPayoutItem::dispatchSync($item->id);
    $item->refresh();

    expect($item->status)->toBe(PayoutItemStatus::Unknown)
        ->and(balanceOf($instructor)->reserved_minor->minor)->toBe(50_000)
        // Nothing was recorded at the provider — which is why status() will say so.
        ->and(DB::table('mock_provider_transactions')->count())->toBe(0);

    reconcile($item);
    $item->refresh();

    // The provider has no record, and because the key is deterministic that is a
    // real answer: nothing moved. Safe to release.
    expect($item->status)->toBe(PayoutItemStatus::Failed)
        ->and($item->failure_class->value)->toBe('transient')
        ->and(balanceOf($instructor)->reserved_minor->isZero())->toBeTrue()
        ->and(balanceOf($instructor)->paid_minor->isZero())->toBeTrue()
        // Back to payable, so the next run picks it up naturally.
        ->and(balanceOf($instructor)->available_minor->minor)->toBe(50_000)
        ->and(LedgerEntry::where('type', 'payout')->count())->toBe(0);
});

it('a second payout run cannot touch money held by an uncertain first attempt', function () {
    // This is what `reserved_minor` is for. The first attempt's outcome is unknown,
    // so the money must not be offered to a second run.
    scriptProvider(MockOutcome::TimeoutAfterSuccess);

    // The automatic status check is held back so it cannot settle the item before
    // the second run happens: the reservation alone has to be what protects the money.
    Queue::fake([ReconcilePayoutItem::class]);

    $instructor = owedInstructor(50_000);

    $this->artisan('payouts:run', ['--period' => '2026-01', '--sync' => true])->assertSuccessful();

    expect(PayoutItem::sole()->status)->toBe(PayoutItemStatus::Unknown)
        ->and(balanceOf($instructor)->reserved_minor->minor)->toBe(50_000)
        ->and(balanceOf($instructor)->available_minor->isZero())->toBeTrue();

    // A different period, so period_key does not stop it. The reservation must.
    $this->artisan('payouts:run', ['--period' => '2026-02', '--sync' => true])->assertSuccessful();

    expect(PayoutItem::count())->toBe(1)
        ->and(DB::table('mock_provider_transactions')->count())->toBe(1);
});

it('a permanent failure releases the hold and writes no ledger entry', function () {
    scriptProvider(MockOutcome::FailPermanently);

    $instructor = owedInstructor(50_000);
    $item = itemFor($instructor, 50_000);

    SendPayoutItem::dispatchSync($item->id);
    $item->refresh();

    expect($item->status)->toBe(PayoutItemStatus::Failed)
        ->and($item->failure_class->value)->toBe('permanent')
        ->and(balanceOf($instructor)->reserved_minor->isZero())->toBeTrue()
        // Nothing was earned or paid, so the ledger has nothing to say.
        ->and(LedgerEntry::count())->toBe(0)
        ->and(balanceOf($instructor)->available_minor->minor)->toBe(50_000);
});

it('the provider dedupes a resend on the same key instead of paying again', function () {
    // Belt and braces: even if every one of our guards were bypassed and the same
    // request were sent twice, the deterministic key means the provider returns its
    // stored result rather than moving money a second time.
    scriptProvider(MockOutcome::Succeed, MockOutcome::Succeed);

    $instructor = owedInstructor(50_000);
    $item = itemFor($instructor, 50_000);

    $provider = app(PaymentProvider::class);
    $request = new PayoutRequest(
        idempotencyKey: $item->idempotency_key,
        instructorId: $instructor->id,
        accountRef: (string) $instructor->payout_account_ref,
        amount: Money::of(50_000),
    );

    $first = $provider->send($request);
    $second = $provider->send($request);

    expect($first->reference)->toBe($second->reference)
        ->and(DB::table('mock_provider_transactions')->count())->toBe(1);
});

it('repeated reconciliation of a settled item changes nothing', function () {
    scriptProvider(MockOutcome::TimeoutAfterSuccess);

    $instructor = owedInstructor(50_000);
    $item = itemFor($instructor, 50_000);

    SendPayoutItem::dispatchSync($item->id);

    foreach (range(1, 6) as $ignored) {
        reconcile($item);
    }

    expect(balanceOf($instructor)->paid_minor->minor)->toBe(50_000)
        ->and(LedgerEntry::where('type', 'payout')->count())->toBe(1)
        ->and(balanceOf($instructor)->reserved_minor->isZero())->toBeTrue();
});

// ═════════════════════════════════════════════════════════════════════════════
// 5. Fan-out and eligibility
// ═════════════════════════════════════════════════════════════════════════════

it('fan-out is replayable and creates no duplicate items', function () {
    owedInstructor(50_000);
    owedInstructor(75_000);

    $batch = PayoutBatch::factory()->forPeriod('2026-04')->create();
    $fanOut = app(FanOutPayoutBatch::class);

    $first = $fanOut->execute($batch);
    $second = $fanOut->execute($batch);

    expect($first->itemsCreated)->toBe(2)
        // Zero on replay — reported honestly rather than counted as work.
        ->and($second->itemsCreated)->toBe(0)
        ->and($second->totalItems)->toBe(2)
        ->and(PayoutItem::count())->toBe(2);
});

it('carries a below-minimum balance forward instead of paying dust', function () {
    $dusty = owedInstructor(5_000);   // below the 10_000 minimum
    $payable = owedInstructor(50_000);

    $batch = PayoutBatch::factory()->forPeriod('2026-04')->create();
    $summary = app(FanOutPayoutBatch::class)->execute($batch);

    expect($summary->itemsCreated)->toBe(1)
        ->and($summary->skippedBelowMinimum)->toBe(1)
        ->and(PayoutItem::sole()->instructor_id)->toBe($payable->id)
        // Still fully visible as outstanding, ready for the next run.
        ->and(balanceOf($dusty)->available_minor->minor)->toBe(5_000);
});

it('accrues for an instructor with no payout account but does not pay them', function () {
    $instructor = Instructor::factory()->withoutPayoutAccount()->create();
    InstructorBalance::factory()->for($instructor)->owed(50_000)->create();

    $batch = PayoutBatch::factory()->forPeriod('2026-04')->create();
    $summary = app(FanOutPayoutBatch::class)->execute($batch);

    expect($summary->itemsCreated)->toBe(0)
        ->and($summary->skippedNotPayable)->toBe(1)
        // The money is owed and stays owed. Nothing is lost.
        ->and(balanceOf($instructor)->available_minor->minor)->toBe(50_000);
});

// ═════════════════════════════════════════════════════════════════════════════
// 6. The state machine refuses illegal moves
// ═════════════════════════════════════════════════════════════════════════════

it('refuses to move a settled item back out of a terminal state', function () {
    scriptProvider(MockOutcome::Succeed);

    $instructor = owedInstructor(50_000);
    $item = itemFor($instructor, 50_000);

    SendPayoutItem::dispatchSync($item->id);
    $item->refresh();

    expect($item->status)->toBe(PayoutItemStatus::Succeeded)
        ->and($item->status->isTerminal())->toBeTrue()
        // A late-arriving failure callback cannot un-pay a completed payout.
        ->and(app(PayoutItemTransitions::class)->releaseAsFailed(
            $item,
            FailureClass::Permanent,
            'A late callback arrives claiming failure.',
        ))->toBeFalse()
        ->and($item->fresh()->status)->toBe(PayoutItemStatus::Succeeded)
        ->and(balanceOf($instructor)->paid_minor->minor)->toBe(50_000);
});
