<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Allocation\AllocateSubscriptionPeriod;
use App\Actions\Payouts\ClaimPayoutBatch;
use App\Actions\Payouts\FanOutPayoutBatch;
use App\Actions\Refunds\ProcessRefund;
use App\Domain\Payouts\Provider\MockOutcome;
use App\Domain\Payouts\Provider\MockPaymentProvider;
use App\Domain\Payouts\Provider\OutcomeDecider;
use App\Domain\Payouts\Provider\PaymentProvider;
use App\Domain\Payouts\Provider\ScriptedOutcomes;
use App\Domain\Refunds\RefundKind;
use App\Domain\Subscriptions\Plan;
use App\Jobs\Payouts\SendPayoutItem;
use App\Models\Course;
use App\Models\Engagement;
use App\Models\Instructor;
use App\Models\Payment;
use App\Models\PayoutItem;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A demonstration dataset that exercises EVERY state the system can be in.
 *
 * Built by running the real actions — AllocateSubscriptionPeriod, ProcessRefund, the
 * payout jobs against the real MockPaymentProvider — rather than by inserting
 * hand-crafted rows. So the data is produced the same way production data is, and
 * the seeder doubles as an end-to-end demonstration: if it runs, the pipeline works.
 *
 * Provider outcomes are SCRIPTED, not random, so the resulting screen looks the same
 * every time and a walkthrough can be narrated with confidence.
 *
 * After running, the admin panel shows at least one instructor in each of:
 *   • paid in full
 *   • outstanding balance awaiting the next run
 *   • money in flight with an UNKNOWN outcome (the interesting one)
 *   • a failed payout, released back to available
 *   • below the payout minimum, carried forward
 *   • earning but not yet payable (no payout account)
 *   • negative balance from a clawback
 */
final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        fake()->seed(20260920);

        $this->command?->info('Seeding a demonstration ledger. Provider outcomes are scripted, not random.');

        User::factory()->create([
            'name' => 'Ledger Admin',
            'email' => 'admin@career180.test',
            'password' => bcrypt('password'),
        ]);

        // ── Instructors, each destined for a different end state ──────────────
        $paid = Instructor::factory()->create(['name' => 'Nadia Farouk']);
        $inFlight = Instructor::factory()->create(['name' => 'Omar Shafik']);
        $failed = Instructor::factory()->create(['name' => 'Hala Mansour']);
        $dusty = Instructor::factory()->create(['name' => 'Karim Adel']);
        $notOnboarded = Instructor::factory()->withoutPayoutAccount()->create(['name' => 'Yasmin Tarek']);
        $clawedBack = Instructor::factory()->create(['name' => 'Tamer Roushdy']);

        $everyone = [$paid, $inFlight, $failed, $dusty, $notOnboarded, $clawedBack];

        /** @var array<int, Course> $courses */
        $courses = [];

        foreach ($everyone as $instructor) {
            $courses[$instructor->id] = Course::factory()->for($instructor)->create([
                'title' => ucfirst(fake()->words(3, true)),
            ]);
        }

        // ── Bulk of the revenue: annual subscribers across Q1 2026 ───────────
        //
        // Four instructors share these, so the equal-weight split and its
        // largest-remainder rounding are both visible in the data.
        $periods = ['2026-01', '2026-02', '2026-03'];
        $shared = [$paid, $inFlight, $failed, $clawedBack];

        foreach (range(1, 14) as $ignored) {
            $subscription = $this->subscriber(Plan::Annual, '2026-01-01');

            foreach ($shared as $instructor) {
                foreach ($periods as $period) {
                    $this->engage($subscription, $courses[$instructor->id], $period);
                }
            }

            $this->allocate($subscription, $periods);
        }

        // ── One subscriber whose only instructor is not yet onboarded ─────────
        //
        // Earnings accrue, payouts wait. Nothing is lost — it is simply not sent.
        $pending = $this->subscriber(Plan::Annual, '2026-01-01');
        foreach ($periods as $period) {
            $this->engage($pending, $courses[$notOnboarded->id], $period);
        }
        $this->allocate($pending, $periods);

        // ── A subscriber whose split lands someone below the payout minimum ───
        //
        // One monthly subscription shared three ways: 299.00 EGP, 70% to instructors,
        // split three ways is 69.77 each — under the 100.00 minimum. So Karim's
        // earnings are real and fully visible as outstanding, but the fan-out skips
        // him and carries the amount forward rather than paying dust.
        $small = $this->subscriber(Plan::Monthly, '2026-03-01');

        foreach ([$dusty, $paid, $failed] as $instructor) {
            $this->engage($small, $courses[$instructor->id], '2026-03');
        }

        $this->allocate($small, ['2026-03']);

        // ── A student who left mid-term: the pro-rata refund path ────────────
        $leaver = $this->subscriber(Plan::Annual, '2026-01-01');
        foreach ($shared as $instructor) {
            foreach ($periods as $period) {
                $this->engage($leaver, $courses[$instructor->id], $period);
            }
        }
        $this->allocate($leaver, $periods);

        app(ProcessRefund::class)->execute(
            subscription: $leaver->fresh(),
            kind: RefundKind::Prorata,
            effectiveOn: CarbonImmutable::parse('2026-03-15'),
            reason: 'Student cancelled mid-term',
            idempotencyKey: 'demo-prorata-refund',
        );

        // ── A subscription that will later be charged back ───────────────────
        //
        // Allocated BEFORE the payout, so this money genuinely leaves and the clawback
        // that follows has something real to recover.
        $fraud = $this->subscriber(Plan::Annual, '2026-01-01');
        foreach ($periods as $period) {
            $this->engage($fraud, $courses[$clawedBack->id], $period);
        }
        $this->allocate($fraud, $periods);

        // ── Run a payout with scripted outcomes ──────────────────────────────
        $this->runScriptedPayout([
            $paid->id => MockOutcome::Succeed,
            $inFlight->id => MockOutcome::TimeoutAfterSuccess,
            $failed->id => MockOutcome::FailPermanently,
            $clawedBack->id => MockOutcome::Succeed,
        ]);

        // ── The chargeback, AFTER the money left ──────────────────────────────
        //
        // An earlier version of this seeder faked the paid state by writing paid_minor
        // straight onto the balance snapshot. `ledger:verify` caught it immediately:
        // the snapshot claimed 2,029.46 paid while the ledger recomputed 0.00. The
        // seeder was doing precisely what the design forbids — moving money with no
        // ledger entry to justify it.
        //
        // The instructor is now paid through the real pipeline first, so the clawback
        // recovers money that actually left, the balance goes legitimately negative,
        // and ledger:verify passes on the seeded data.
        app(ProcessRefund::class)->execute(
            subscription: $fraud->fresh(),
            kind: RefundKind::Full,
            effectiveOn: CarbonImmutable::parse('2026-03-20'),
            reason: 'Chargeback raised by the card issuer',
            idempotencyKey: 'demo-chargeback',
        );

        $this->report();
    }

    private function subscriber(Plan $plan, string $startsOn): Subscription
    {
        $subscription = Subscription::factory()
            ->for(Student::factory()->create())
            ->plan($plan)
            ->startingOn($startsOn)
            ->create();

        Payment::factory()->forSubscription($subscription)->create();

        return $subscription;
    }

    private function engage(Subscription $subscription, Course $course, string $period): void
    {
        Engagement::factory()
            ->for($subscription->student)
            ->forCourse($course)
            ->inPeriod($period)
            ->watched(fake()->numberBetween(600, 12 * 3_600))
            ->create();
    }

    /** @param list<string> $periods */
    private function allocate(Subscription $subscription, array $periods): void
    {
        foreach ($periods as $period) {
            app(AllocateSubscriptionPeriod::class)->execute($subscription, $period);
        }
    }

    /**
     * Run one payout batch, giving each instructor a predetermined provider outcome.
     *
     * @param  array<int, MockOutcome>  $outcomes  instructor id => what the provider does
     */
    private function runScriptedPayout(array $outcomes): void
    {
        $batch = app(ClaimPayoutBatch::class)->execute('2026-03')->batch;
        app(FanOutPayoutBatch::class)->execute($batch);

        foreach (PayoutItem::where('payout_batch_id', $batch->id)->orderBy('instructor_id')->get() as $item) {
            $outcome = $outcomes[$item->instructor_id] ?? MockOutcome::Succeed;

            // Rebind per item so each one gets exactly the outcome we intend.
            app()->bind(OutcomeDecider::class, fn () => new ScriptedOutcomes($outcome));
            app()->bind(PaymentProvider::class, fn ($app) => new MockPaymentProvider($app->make(OutcomeDecider::class)));

            SendPayoutItem::dispatchSync($item->id);
        }

        $this->command?->newLine();

        foreach ([
            '  One payout is left <fg=yellow>unknown</>: the provider moved the money and the response',
            '  was lost. The send path queued its own status check, so a running worker resolves',
            '  it within ~30s — correct behaviour, but it makes the in-flight state brief.',
            '',
            '  To inspect it: <fg=cyan>docker compose stop worker</> before seeding, then',
            '  <fg=cyan>php artisan payouts:reconcile --sync</> to resolve it by hand.',
        ] as $line) {
            $this->command?->line($line);
        }
    }

    private function report(): void
    {
        $rows = DB::table('instructor_balances as b')
            ->join('instructors as i', 'i.id', '=', 'b.instructor_id')
            ->select('i.name', 'b.earned_minor', 'b.paid_minor', 'b.reserved_minor', 'b.available_minor')
            ->orderBy('b.instructor_id')
            ->get();

        $this->command?->newLine();
        $this->command?->table(
            ['Instructor', 'Earned', 'Paid', 'In flight', 'Outstanding'],
            $rows->map(fn ($r): array => [
                $r->name,
                number_format($r->earned_minor / 100, 2),
                number_format($r->paid_minor / 100, 2),
                number_format($r->reserved_minor / 100, 2),
                number_format($r->available_minor / 100, 2),
            ])->all(),
        );
    }
}
