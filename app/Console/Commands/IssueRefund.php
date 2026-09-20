<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Refunds\ProcessRefund;
use App\Domain\Refunds\RefundKind;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Issues a refund. Primarily an operator and demo entry point — in production this
 * would be driven by a provider webhook, which is why the action it calls is keyed
 * on an idempotency key rather than on being invoked once.
 */
final class IssueRefund extends Command
{
    protected $signature = 'refunds:issue
        {subscription : Subscription id.}
        {--kind=prorata : prorata (normal) or full (fraud, chargeback).}
        {--on= : Effective date, YYYY-MM-DD. Defaults to today.}
        {--reason=Student cancelled : Recorded on the refund.}
        {--key= : Idempotency key. Defaults to one derived from the arguments.}';

    protected $description = 'Refund a subscription, correcting the ledger as required.';

    public function handle(ProcessRefund $refunds): int
    {
        $subscription = Subscription::query()->find((int) $this->argument('subscription'));

        if ($subscription === null) {
            $this->error('No such subscription.');

            return self::FAILURE;
        }

        $kind = RefundKind::tryFrom((string) $this->option('kind'));

        if ($kind === null) {
            $this->error('The --kind option must be prorata or full.');

            return self::FAILURE;
        }

        $effectiveOn = $this->option('on') !== null
            ? CarbonImmutable::parse((string) $this->option('on'))
            : CarbonImmutable::now();

        // Derived rather than random, so re-running the same command is a no-op
        // instead of a second refund.
        $key = (string) ($this->option('key') ?? hash(
            'sha256',
            "refund:v1:{$subscription->id}:{$kind->value}:{$effectiveOn->toDateString()}",
        ));

        $result = $refunds->execute(
            subscription: $subscription,
            kind: $kind,
            effectiveOn: $effectiveOn,
            reason: (string) $this->option('reason'),
            idempotencyKey: $key,
        );

        $this->components->twoColumnDetail('Outcome', $result->outcome->value);

        if ($result->outcome->value === 'already_processed') {
            $this->components->warn('This refund was already processed. Nothing changed.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Refunded to student', $result->refundedToStudent->format());
        $this->components->twoColumnDetail('Reclaimed from instructors', $result->reclaimedFromInstructors->format());
        $this->components->twoColumnDetail('Allocations voided', (string) $result->allocationsVoided);
        $this->components->twoColumnDetail('Allocations adjusted', (string) $result->allocationsAdjusted);

        if ($result->touchedNoInstructor()) {
            $this->components->info(
                'No instructor balance moved. The refunded portion was never recognised, '
                .'so it was never payable.'
            );
        }

        return self::SUCCESS;
    }
}
