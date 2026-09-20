<?php

declare(strict_types=1);

namespace App\Domain\Payouts\Provider\Exceptions;

use RuntimeException;

/**
 * The provider did not answer.
 *
 * Deliberately NOT a subclass of any "failure" type, and deliberately not named
 * ProviderFailed. The name is the documentation: nothing failed, we simply do not
 * know. Anyone reaching for a `catch (ProviderFailed)` block will not accidentally
 * swallow this and retry a payment that already went through.
 */
final class ProviderTimeout extends RuntimeException
{
    public function __construct(
        public readonly string $idempotencyKey,
        string $message = 'The provider did not respond. The outcome is unknown, not failed.',
    ) {
        parent::__construct($message);
    }
}
