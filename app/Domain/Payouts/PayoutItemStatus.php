<?php

declare(strict_types=1);

namespace App\Domain\Payouts;

/**
 * The payout state machine.
 *
 *     pending ──► submitted ──► succeeded   (terminal)
 *                     │   └───► failed      (terminal)
 *                     └───────► unknown ──► succeeded | failed
 *
 * `Unknown` is the case the whole design exists for. A timeout is NOT a failure:
 * the provider may already have moved the money. Treating unknown as failed and
 * retrying is exactly how an instructor gets paid twice.
 *
 * The legal transitions live here, and the database enforces them separately via
 * conditional UPDATE (`WHERE status = ?`). Two independent mechanisms agreeing is
 * the point — this enum documents and unit-tests the intent, MySQL guarantees it
 * under concurrency.
 */
enum PayoutItemStatus: string
{
    /** Claimed for this batch, nothing sent yet. Amount is not yet reserved. */
    case Pending = 'pending';

    /** Intent durably recorded, provider call in flight. Amount is reserved. */
    case Submitted = 'submitted';

    /** Outcome genuinely unknown. Amount stays reserved until the provider says. */
    case Unknown = 'unknown';

    /** Money confirmed sent. */
    case Succeeded = 'succeeded';

    /** Provider confirmed it never processed this. Reservation released. */
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed => true,
            default => false,
        };
    }

    /** True while the amount must be held in `reserved_minor`. */
    public function holdsReservation(): bool
    {
        return match ($this) {
            self::Submitted, self::Unknown => true,
            default => false,
        };
    }

    /** True when the outcome must be discovered by asking the provider. */
    public function needsStatusCheck(): bool
    {
        return match ($this) {
            self::Submitted, self::Unknown => true,
            default => false,
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Submitted, self::Failed],
            self::Submitted => [self::Succeeded, self::Failed, self::Unknown],
            self::Unknown => [self::Succeeded, self::Failed],

            // Terminal. Re-running the whole pipeline must land here and stop,
            // which is what makes a replay a no-op instead of a second payment.
            self::Succeeded, self::Failed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /**
     * Where a provider outcome lands.
     *
     * Note what is absent: there is no path from a timeout to `Failed`. An
     * unreachable provider produces `Unknown`, full stop.
     */
    public static function forFailureClass(FailureClass $class): self
    {
        return match ($class) {
            FailureClass::Permanent, FailureClass::Transient => self::Failed,
            FailureClass::Unknown => self::Unknown,
        };
    }
}
