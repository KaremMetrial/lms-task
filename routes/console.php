<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Money movement schedule
|--------------------------------------------------------------------------
|
| withoutOverlapping() on the payout run is convenience, not safety: the unique
| indexes on payout_batches.period_key and payout_items (batch, instructor) already
| make an overlapping run harmless. It simply avoids pointless duplicate work.
|
*/

// Accrual runs on the 1st, recognising the month that just ended.
Schedule::command('ledger:accrue')
    ->monthlyOn(1, '01:00')
    ->withoutOverlapping()
    ->onOneServer();

// Payouts run on the 2nd, once the previous month is fully accrued.
Schedule::command('payouts:run')
    ->monthlyOn(2, '02:00')
    ->withoutOverlapping()
    ->onOneServer();

// Sweep uncertain outcomes often. This only ever ASKS the provider, so running it
// frequently — or twice at once — cannot move money.
Schedule::command('payouts:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Drift in the balance snapshot is silent by nature: everything keeps working, it
// just stops being true. In production this should page someone on failure.
Schedule::command('ledger:verify')
    ->dailyAt('03:00')
    ->onOneServer();
