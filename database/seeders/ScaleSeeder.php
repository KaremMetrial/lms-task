<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Subscriptions\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Generates the volume the brief describes: 500,000 active subscriptions and tens of
 * millions of underlying records.
 *
 * ── Why this is not factories ────────────────────────────────────────────────
 *
 * Eloquent factories issue one INSERT per row and boot a model for each. At 18 million
 * engagement rows that is hours, and it would tell us nothing about the schema.
 * Everything here is chunked raw INSERT through the query builder, which is the
 * only way a dataset this size is reachable on a laptop.
 *
 * ── Scale it down, and say so ────────────────────────────────────────────────
 *
 * The defaults are modest so `db:seed --class=ScaleSeeder` is usable in a review.
 * Override through the environment:
 *
 *   SEED_SUBSCRIPTIONS=500000 SEED_PERIODS=12 php artisan db:seed --class=ScaleSeeder
 *
 * At 500k subscriptions × 3 engaged instructors × 12 periods the engagements table
 * alone is ~18M rows. That is the figure the scaling section of ARCHITECTURE.md is
 * written against, and `ledger:benchmark` measures the queries on whatever was
 * actually generated rather than on an assumption.
 */
final class ScaleSeeder extends Seeder
{
    /**
     * Students per batch.
     *
     * Kept deliberately small: each student generates instructorsPerStudent x periods
     * engagement rows, so 2,000 students at 3 x 12 built an array of 72,000 rows before
     * a single INSERT — which exhausted memory on the first real run. Engagements are
     * now flushed as they are built rather than accumulated.
     */
    private const CHUNK = 500;

    /** Rows per INSERT statement. Comfortably under MySQL's placeholder ceiling. */
    private const INSERT_BATCH = 1_000;

    public function run(): void
    {
        // From config, not env(): see the note in config/ledger.php. Overridden the
        // same way, through the environment, but via a path that survives config:cache.
        $subscriptions = (int) config('ledger.seeding.subscriptions');
        $instructors = (int) config('ledger.seeding.instructors');
        $coursesEach = (int) config('ledger.seeding.courses_per_instructor');
        $periods = (int) config('ledger.seeding.periods');
        $instructorsPerStudent = (int) config('ledger.seeding.instructors_per_student');

        $engagementEstimate = $subscriptions * $instructorsPerStudent * $periods;

        $this->command?->warn(sprintf(
            'Generating %s subscriptions, %s instructors, ~%s engagement rows.',
            number_format($subscriptions),
            number_format($instructors),
            number_format($engagementEstimate),
        ));

        // Nothing is logged in memory for a run that issues tens of thousands of
        // statements.
        DB::connection()->disableQueryLog();

        // Re-runnable. Without this, a second attempt collides on the unique email and
        // the seeder becomes single-use — which is useless while tuning scale.
        $this->truncate();

        $started = microtime(true);

        $this->seedInstructors($instructors);
        $courseIds = $this->seedCourses($instructors, $coursesEach);
        $this->seedStudentsAndSubscriptions($subscriptions, $courseIds, $periods, $instructorsPerStudent);

        $elapsed = microtime(true) - $started;

        $this->command?->newLine();
        $this->command?->table(['Table', 'Rows'], [
            ['instructors', number_format(DB::table('instructors')->count())],
            ['courses', number_format(DB::table('courses')->count())],
            ['students', number_format(DB::table('students')->count())],
            ['subscriptions', number_format(DB::table('subscriptions')->count())],
            ['payments', number_format(DB::table('payments')->count())],
            ['engagements', number_format(DB::table('engagements')->count())],
        ]);

        $this->command?->info(sprintf('Done in %.1fs.', $elapsed));
        $this->command?->line(
            '  Next: <fg=cyan>php artisan ledger:benchmark</> measures the queries that matter at this size.'
        );
    }

    /**
     * Empty the tables this seeder owns, children first.
     *
     * Foreign keys are dropped for the duration rather than ordered perfectly by hand:
     * TRUNCATE cannot run against a referenced table even when it is empty.
     */
    private function truncate(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            foreach ([
                'ledger_entries', 'allocation_lines', 'allocations', 'payout_items',
                'payout_batches', 'instructor_balances', 'refunds', 'payments',
                'engagements', 'subscriptions', 'students', 'courses', 'instructors',
                'mock_provider_transactions',
            ] as $table) {
                DB::table($table)->truncate();
            }
        });
    }

    private function seedInstructors(int $count): void
    {
        $this->chunked('instructors', $count, function (int $offset, int $size): array {
            $rows = [];

            for ($i = 0; $i < $size; $i++) {
                $n = $offset + $i + 1;

                $rows[] = [
                    'name' => "Instructor {$n}",
                    'email' => "instructor{$n}@example.test",
                    // Every tenth instructor is not onboarded for payouts, so the
                    // "earns but cannot be paid" path is represented at scale too.
                    'payout_account_ref' => $n % 10 === 0 ? null : 'acct_'.Str::lower(Str::random(12)),
                    'status' => $n % 50 === 0 ? 'suspended' : 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            return $rows;
        });
    }

    /** @return list<int> */
    private function seedCourses(int $instructors, int $each): array
    {
        $total = $instructors * $each;

        $this->chunked('courses', $total, function (int $offset, int $size) use ($each): array {
            $rows = [];

            for ($i = 0; $i < $size; $i++) {
                $n = $offset + $i;

                $rows[] = [
                    'instructor_id' => intdiv($n, $each) + 1,
                    'title' => 'Course '.($n + 1),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            return $rows;
        });

        return DB::table('courses')->orderBy('id')->pluck('id')->all();
    }

    /**
     * Students, their subscriptions, their payments and their engagement — written
     * together so the whole set for a chunk of students lands in a few statements.
     *
     * @param  list<int>  $courseIds
     */
    private function seedStudentsAndSubscriptions(
        int $count,
        array $courseIds,
        int $periods,
        int $instructorsPerStudent,
    ): void {
        // instructor_id for a course, resolved once instead of joined per row.
        $courseOwner = DB::table('courses')->pluck('instructor_id', 'id')->all();
        $courseCount = count($courseIds);

        $plans = [Plan::Monthly, Plan::Quarterly, Plan::Annual];
        $bps = (int) config('ledger.platform_share_bps');
        $currency = (string) config('ledger.currency');

        $bar = $this->command?->getOutput()->createProgressBar((int) ceil($count / self::CHUNK));
        $bar?->start();

        for ($offset = 0; $offset < $count; $offset += self::CHUNK) {
            $size = min(self::CHUNK, $count - $offset);

            $students = [];

            for ($i = 0; $i < $size; $i++) {
                $n = $offset + $i + 1;
                $students[] = [
                    'name' => "Student {$n}",
                    'email' => "student{$n}@example.test",
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $firstStudentId = (int) (DB::table('students')->max('id') ?? 0) + 1;
            DB::table('students')->insert($students);

            $subscriptions = [];
            $engagements = [];
            $pendingEngagements = 0;

            for ($i = 0; $i < $size; $i++) {
                $studentId = $firstStudentId + $i;
                $plan = $plans[$studentId % 3];

                // Spread start dates across a year so accrual periods overlap the way
                // they do in production, rather than all aligning to one month.
                $startsOn = CarbonImmutable::parse('2026-01-01')->addDays($studentId % 365);

                $subscriptions[] = [
                    'student_id' => $studentId,
                    'plan' => $plan->value,
                    'amount_minor' => $plan->price()->minor,
                    'currency' => $currency,
                    'platform_share_bps' => $bps,
                    'starts_on' => $startsOn->toDateString(),
                    'ends_on' => $plan->termEndFor($startsOn)->toDateString(),
                    // Roughly one in twenty leaves mid-term, so the refund path has
                    // real data behind it at scale.
                    'status' => $studentId % 20 === 0 ? 'cancelled' : 'active',
                    'cancelled_on' => $studentId % 20 === 0
                        ? $startsOn->addDays(45)->toDateString()
                        : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                for ($p = 0; $p < $periods; $p++) {
                    $period = $startsOn->addMonthsNoOverflow($p)->format('Y-m');

                    for ($k = 0; $k < $instructorsPerStudent; $k++) {
                        // Deterministic spread, so re-running produces the same shape.
                        $courseId = $courseIds[($studentId * 7 + $k * 13 + $p) % $courseCount];

                        $engagements[] = [
                            'student_id' => $studentId,
                            'course_id' => $courseId,
                            'instructor_id' => $courseOwner[$courseId],
                            'accrual_period' => $period,
                            'watched_seconds' => 600 + (($studentId + $k * 17 + $p * 31) % 36_000),
                            'sessions_count' => 1 + (($studentId + $p) % 20),
                            'last_engaged_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        $pendingEngagements++;
                    }
                }

                // Flush as we go. Holding every engagement row for the batch in memory
                // is what broke the first run at scale.
                if ($pendingEngagements >= self::INSERT_BATCH) {
                    DB::table('engagements')->insertOrIgnore($engagements);
                    $engagements = [];
                    $pendingEngagements = 0;
                }
            }

            $firstSubscriptionId = (int) (DB::table('subscriptions')->max('id') ?? 0) + 1;
            DB::table('subscriptions')->insert($subscriptions);

            $payments = [];

            foreach ($subscriptions as $index => $subscription) {
                $subscriptionId = $firstSubscriptionId + $index;

                $payments[] = [
                    'subscription_id' => $subscriptionId,
                    'amount_minor' => $subscription['amount_minor'],
                    'currency' => $currency,
                    'paid_at' => $subscription['starts_on'].' 09:00:00',
                    // Derived, matching how the real flow keys a payment.
                    'idempotency_key' => hash('sha256', "payment:subscription:{$subscriptionId}"),
                    'external_reference' => 'pay_'.Str::lower(Str::random(14)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('payments')->insert($payments);

            // insertOrIgnore: the deterministic course spread can pick the same course
            // for the same student twice in a period, and engagements has a unique
            // index on (student, course, period). Letting MySQL drop the collision is
            // cheaper than de-duplicating in PHP.
            if ($engagements !== []) {
                DB::table('engagements')->insertOrIgnore($engagements);
                $engagements = [];
            }

            unset($students, $subscriptions, $payments);

            $bar?->advance();
        }

        $bar?->finish();
        $this->command?->newLine();
    }

    /**
     * @param  callable(int, int): list<array<string, mixed>>  $build
     */
    private function chunked(string $table, int $total, callable $build): void
    {
        for ($offset = 0; $offset < $total; $offset += self::INSERT_BATCH) {
            $size = min(self::INSERT_BATCH, $total - $offset);
            DB::table($table)->insert($build($offset, $size));
        }
    }
}
