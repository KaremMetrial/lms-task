<?php

declare(strict_types=1);

use App\Jobs\Payouts\ReconcilePayoutItem;
use App\Jobs\Payouts\SendPayoutItem;

/*
 * Configuration invariants that no behavioural test would catch.
 *
 * Each of these was wrong in a way that passed the entire suite: the tests run on the
 * sync driver, where retry_after and queue names do not exist. They surface only on a
 * real Redis queue under a real worker — which is precisely where they cost money.
 */

it('releases a job only after its own timeout could have expired', function (string $job) {
    // If a job can outlive retry_after, Redis hands it back to the queue mid-run and a
    // second worker executes the same payout concurrently. retry_after was 90 while
    // SendPayoutItem allowed 120.
    $timeout = (new $job(1))->timeout;
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    expect($retryAfter)->toBeGreaterThan($timeout + 30);
})->with([
    'send' => SendPayoutItem::class,
    'reconcile' => ReconcilePayoutItem::class,
]);

it('has Horizon supervising the queue the payout jobs are actually dispatched to', function () {
    // Horizon shipped watching only `default`, so its dashboard showed nothing of the
    // pipeline it was added to make visible.
    $queue = (new SendPayoutItem(1))->queue;

    expect($queue)->toBe('payouts')
        ->and(config('horizon.defaults.supervisor-1.queue'))->toContain($queue);
});

it('dispatches reconciliation onto the same queue as sending', function () {
    expect((new ReconcilePayoutItem(1))->queue)->toBe((new SendPayoutItem(1))->queue);
});
