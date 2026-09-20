<?php

/**
 * Boot smoke test.
 *
 * Deliberately thin: it only proves the container, MySQL connection, Redis cache
 * and migrations line up well enough for the real suite to mean anything. The
 * money-correctness tests live alongside the ledger code.
 */
it('serves the application root', function () {
    $this->get('/')->assertOk();
});

it('is connected to the dedicated test database', function () {
    expect(DB::connection()->getDatabaseName())->toBe('lms_test');
});
