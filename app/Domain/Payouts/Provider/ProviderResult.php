<?php

declare(strict_types=1);

namespace App\Domain\Payouts\Provider;

use App\Domain\Payouts\FailureClass;

final readonly class ProviderResult
{
    private function __construct(
        public ProviderOutcome $outcome,
        public ?string $reference = null,
        public ?FailureClass $failureClass = null,
        public ?string $message = null,
    ) {}

    public static function succeeded(string $reference): self
    {
        return new self(ProviderOutcome::Succeeded, reference: $reference);
    }

    public static function failed(FailureClass $class, string $message): self
    {
        return new self(ProviderOutcome::Failed, failureClass: $class, message: $message);
    }

    public static function pending(?string $reference = null): self
    {
        return new self(ProviderOutcome::Pending, reference: $reference);
    }

    public static function notFound(): self
    {
        return new self(
            ProviderOutcome::NotFound,
            message: 'The provider holds no record of this idempotency key.',
        );
    }

    public function isTerminal(): bool
    {
        return in_array($this->outcome, [ProviderOutcome::Succeeded, ProviderOutcome::Failed], true);
    }
}
