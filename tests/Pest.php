<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case bindings
|--------------------------------------------------------------------------
|
| Feature tests get the full application plus a migrated, isolated database.
| Unit tests stay framework-free on purpose: the money primitives (amount
| arithmetic, split/remainder rules, allocation maths) must be provable without
| booting Laravel or touching a database, which keeps them fast and keeps the
| logic honest about its dependencies.
|
| Note for the concurrency tests: RefreshDatabase wraps each test in a
| transaction, so rows are invisible to a second connection. Tests that prove
| two racing payout runs cannot double-pay must opt out of this trait and use
| Illuminate\Foundation\Testing\DatabaseTruncation instead.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
