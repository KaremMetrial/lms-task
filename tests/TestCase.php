<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Clear the cache before every test.
         *
         * RefreshDatabase resets MySQL; Redis is a separate store and survives — not
         * only between tests, but between whole test RUNS. That combination is
         * actively hostile to this suite:
         *
         *   ShouldBeUnique jobs key their lock on a model id, e.g.
         *   "reconcile-payout-item:1". Because the database is reset, auto-increment
         *   ids restart at 1 — so a lock left behind by an earlier run silently
         *   swallows a dispatch minutes later, and the test fails for a reason that
         *   has nothing to do with the code under test. It took a bisect to find.
         *
         * The same applies to the Cache::lock() guarding the payout command. Flushing
         * here makes runs order-independent and repeatable, which is the difference
         * between a suite you can trust in CI and one you learn to re-run.
         *
         * This only works because phpunit.xml also sets
         * REDIS_CACHE_LOCK_CONNECTION=cache. By default Laravel puts cache LOCKS on
         * a different Redis connection than cache VALUES, so Cache::flush() clears
         * the values and leaves every lock in place — see the comment in
         * phpunit.xml. That single default cost a bisect to find.
         *
         * Safe to flush: phpunit.xml points both at a dedicated Redis database.
         */
        Cache::flush();
    }
}
