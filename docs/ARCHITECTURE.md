# Architecture — Instructor Revenue Ledger

This document records the decisions, not the code. Where the brief left a rule
unspecified, the call I made is stated with the reasoning and the trade-off I
accepted.

Everything here follows from one premise: **this system moves money, so
correctness is a schema-level guarantee, not a code-level intention.** Wherever a
rule could be enforced either in PHP or in MySQL, it is enforced in MySQL.

---

## 1. The two questions the brief is really asking

Most of the ambiguity in the brief collapses once you separate two things that
sound like one:

| | Question | My answer |
|---|---|---|
| **Recognition** | *How much* of a payment is earned by a given date? | Straight-line over the term, by day |
| **Attribution** | *Who* earns the recognised amount? | The instructors the student engaged with in that window — equal share by default, watch-time weighted optionally |

Conflating these is the single biggest source of wrong answers here. "Instructor
X is owed Y" is the composition of two independent rules, and keeping them
separate is what makes refunds, plan changes and rounding tractable.

---

## 2. Decision: money is earned ratably, not on payment day

A student pays for the whole term on day one. The money arrives immediately; it
is **not** earned immediately.

Revenue is recognised straight-line across the subscription term. An annual
subscription of 2,499 EGP earns 1/365th of its instructor pool per day.

### Why

The alternative — treat the full amount as earned the moment it is paid — is
simpler for exactly one release, then it poisons everything downstream:

- A student refunds in month 4 of 12. Under day-one recognition, instructors have
  already been paid for months 5–12 of a service they will never deliver. Now you
  need clawbacks against instructors who did nothing wrong, and negative
  balances become routine rather than exceptional.
- Under ratable recognition, the unearned portion was never earned, so it was
  never payable, so it was never paid. **A mid-term refund is a no-op against
  instructor balances in the normal case.** That is an enormous simplification,
  and it falls out of choosing the right recognition rule rather than from
  clever refund code.

This also happens to be what IFRS 15 / ASC 606 require for a service delivered
over time, so the ledger agrees with how the business will eventually be
audited.

### The trade-off I accepted

Ratable recognition means recognition events, and recognition events mean rows.
Day-level rows would be 500,000 subscriptions × 365 days = **182M rows per
year**, which is a scaling problem I chose not to buy.

**Accrual granularity is one calendar month, prorated by days of overlap.**

An annual subscription starting 15 Jan produces 13 periods: Jan (17 days),
Feb–Dec (whole), Jan (14 days). 500k subscriptions produce roughly 6M accrual
periods per year — the "tens of millions of underlying records" the brief
describes, at a size MySQL handles comfortably with the right indexes.

Monthly buckets mean a refund lands mid-period. That residual is handled by
prorating within the affected period only, which is a bounded, testable amount of
arithmetic. Daily granularity would remove that case at 30× the row count. Not
worth it.

---

## 3. Decision: attribution follows engagement, with two strategies shipped

For each accrual period, the instructor pool is divided among the **instructors
whose courses the student engaged with during that period**. Two rules for *how*
are implemented; the platform picks one with a single config value.

| Strategy | Rule | Status |
|---|---|---|
| `equal_weight` | Equal share per engaged instructor | **Default** |
| `watch_time` | Share proportional to seconds watched | Shipped, opt-in |

```
LEDGER_ALLOCATION_STRATEGY=equal_weight   # or watch_time
```

Engaged with nobody in a period → the whole period is platform revenue. Nothing
is owed.

### The structural decision that matters more than the choice

A strategy decides **weights only**. It never divides money.

```php
interface RevenueAllocationStrategy
{
    public function name(): string;

    /** @return array<int, int> instructor id => non-negative weight */
    public function weights(EngagementWindow $window): array;
}
```

`Money::allocate()` is the single place in the codebase that divides an amount,
and it is where the "parts sum to the total, exactly" invariant lives. So adding
a third strategy tomorrow is a pure weighting decision that **cannot introduce a
rounding bug**, however it is written. The alternative — each strategy returning
finished amounts — would mean re-proving exactness for every new rule, and would
eventually mean one of them is wrong.

The strategy name is persisted on every `allocations` row. After the platform
switches rules, a historical split is still re-derivable by the rule that
actually produced it; `AllocationStrategyResolver::byName()` throws rather than
falling back to the current default, because re-explaining an old split with a
rule that never touched it is worse than an error.

### Why `equal_weight` is the default

1. **It is hard to game.** Revenue proportional to watch time rewards padding
   lesson length. An equal share per engaged instructor rewards being worth
   opening at all, which is closer to what the platform wants.
2. **It does not depend on telemetry.** Trustworthy watch-time attribution needs
   heartbeats, dedup and bot filtering, and it arrives late and incomplete.
   Building money on a data source like that produces balances that change after
   they have been paid.
3. **It is explainable.** "This student opened your course and two others, so you
   got a third" is something an instructor can verify. An opaque weighting
   function is not.

**The accepted cost:** 40 hours of instructor A and one lesson of instructor B
splits 50/50. That is genuinely unfair to A. It was preferred because the failure
mode is bounded and visible, whereas premature watch-time weighting fails
silently. The trade-off is asserted in a test rather than left to be discovered.

### Aggregation before weighting — a correctness requirement

Engagement is recorded per course, and one instructor can own several courses.
`EngagementWindow::fromRows()` aggregates **per instructor** before any weighting
happens.

Without that step, equal-weight allocation would give an instructor with three
watched courses three shares instead of one — a silent overpayment that scales
with how many courses an instructor publishes. It is the kind of bug that never
throws and only shows up in an audit.

### `watch_time` and its two degenerate cases

Both needed a judgement call, and neither is obvious:

- **No watch time recorded for anyone.** Falls back to equal weight. The student
  demonstrably engaged — that is why rows exist — so what is missing is the
  split, not whether anyone earned. Returning nothing would hand the whole period
  to the platform every time telemetry broke, turning a tracking outage into
  unpaid instructors. Dividing by zero is not an option either.
- **Some instructors at zero, others not.** Zero-weight instructors get nothing.
  That is the strategy's premise honestly applied — a course opened but never
  watched delivered no attention — and it is also why this is not the default.

**Known limitation, deliberately not built:** a single very long video can
dominate a period. The usual mitigation caps each instructor's weight at a
multiple of the median. That is a policy decision with a real fairness trade-off,
so it belongs in a documented config knob rather than buried inside a weighting
function.

## 4. Decision: integer minor units, largest-remainder splits

**All money is `BIGINT` in minor units (piastres). No floats, no `DECIMAL`, ever,
anywhere in the money path.** Integers are exact, so no arbitrary-precision
library is needed in the hot path.

One real hazard remains: **PHP does not raise on integer overflow — it silently
promotes to float**, which in a ledger means a balance that is quietly
approximate. Every multiplication in the money path is therefore bounds-checked
against `PHP_INT_MAX` first and throws `MoneyOverflow` rather than wrapping. A
loud failure is the only acceptable outcome; a plausible-looking wrong number is
the worst one.

A `Money` value object wraps the integer and the currency. It is immutable, it
refuses cross-currency arithmetic, and it has no `toFloat()`.

### The splitting rule

Two levels, each exact:

```
pool     = floor(amount × instructor_share_bps ÷ 10000)
platform = amount − pool                    ← platform absorbs the first residual
```

Then `pool` is divided among N instructors by the **largest remainder method**:

1. Each instructor gets `floor(pool ÷ N)`.
2. The leftover `pool mod N` piastres go one each to the instructors with the
   largest fractional remainder.
3. Ties break by **`instructor_id` ascending** — deterministic, so the same
   inputs always produce the same split, which is what makes it testable.

### Worked example

Monthly plan, 299.00 EGP = `29900` piastres, 30% platform cut, 3 instructors
engaged:

```
pool     = floor(29900 × 7000 ÷ 10000) = 20930
platform = 29900 − 20930               =  8970

20930 ÷ 3 = 6976 remainder 2
  → instructor 7  : 6977   (+1, lowest id)
  → instructor 12 : 6977   (+1)
  → instructor 31 : 6976
                    ─────
                    20930  ✅ exact
```

### The invariant

> For every allocation, `platform_minor + Σ instructor_minor == payment_minor`,
> exactly, with no tolerance.

This is asserted as a property in the test suite, not just on hand-picked
examples. It is the single most important assertion in the project: if it holds
for every input, money cannot be created or destroyed by the allocator.

### Period-level residual

Prorating a term across months has the same problem one level up:
`Σ floor(total × days_i ÷ total_days) < total`. Rather than distribute that
residual, **the final accrual period absorbs it**: its amount is
`total − Σ(previous periods)`. One rule, exact by construction, and the error is
concentrated where it is least surprising.

---

## 5. Domain model

```
students ──< subscriptions ──< payments
                  │                │
                  │                └──< allocations ──< allocation_lines
                  │                          │               (per instructor)
                  │                          ▼
            (accrual periods)          ledger_entries  ← append-only, source of truth
                  │                          │
                  │                          ├──> instructor_balances   (snapshot)
                  │                          │
engagements ──────┘                          └──< payout_items >── payout_batches
(student × course × period)                          │
                                                     ▼
                                              PaymentProvider
```

### `ledger_entries` — the source of truth

Append-only. **Nothing is ever updated or deleted.** A mistake is corrected by
writing an opposing entry, never by editing history.

| column | purpose |
|---|---|
| `instructor_id` | who |
| `type` | `earning` · `payout` · `reversal` · `clawback` · `adjustment` |
| `amount_minor` | signed: `+` increases what is owed, `−` decreases it |
| `source_type` / `source_id` | what caused it (allocation line, payout item, refund) |
| `occurred_at` | business time, which is not `created_at` |

**`UNIQUE (type, source_type, source_id)`** — this one index is the backbone of
the whole idempotency story. A replayed allocation, a retried payout job, a
duplicated refund webhook: each tries to write a ledger entry whose
`(type, source)` already exists, and MySQL rejects it. Not "the code checks
first" — the database makes it impossible.

Balance is, by definition, `SUM(amount_minor)`. That is always recomputable from
zero, which makes the ledger auditable.

### `instructor_balances` — the snapshot

`SUM()` over tens of millions of rows is not an answer to "what is this
instructor owed" on a dashboard. So there is a snapshot table, updated **inside
the same transaction** as the ledger write, under `SELECT ... FOR UPDATE`:

| column | meaning |
|---|---|
| `earned_minor` | lifetime earnings |
| `paid_minor` | successfully paid out |
| `reserved_minor` | **in-flight payouts of uncertain outcome** |
| `version` | optimistic-concurrency counter |

```
available_to_pay = earned − paid − reserved
```

`reserved_minor` is the column that makes the unreliable provider survivable —
see §7.

**The ledger is the truth; the snapshot is a cache with a receipt.** A
`ledger:verify` command recomputes every balance from the ledger and reports
drift. If they ever disagree, the ledger wins and the snapshot is rebuilt. I
would rather have a fast answer I can prove than a fast answer I have to trust.

### Historical rates are snapshotted, not looked up

`subscriptions.platform_share_bps` stores the cut **as it was at purchase time**.
Allocation reads that column, never config. Changing the platform's cut
tomorrow must not silently rewrite what instructors earned last year — a config
read at allocation time would do exactly that, and it would be invisible.

---

## 6. Idempotency

The brief's hardest requirement: the payout process may run twice, concurrently,
or be manually re-triggered, and no instructor may ever be paid twice. Four
independent layers, each of which would be sufficient on a good day:

### Layer 1 — one batch per period, enforced by MySQL

`payout_batches` has **`UNIQUE (period_key)`** (e.g. `2026-09`). Two servers
starting the September run at the same instant: one inserts, one gets a duplicate
key error and attaches to the existing batch. There is no window in which two
batches for September exist.

### Layer 2 — one item per instructor per batch

`payout_items` has **`UNIQUE (batch_id, instructor_id)`**, so fan-out is
replayable. Re-running the fan-out step produces zero new rows.

### Layer 3 — a deterministic idempotency key

```
idempotency_key = sha256("payout:v1:{batch_id}:{instructor_id}")
```

**Deterministic, not random.** A random ULID generated per attempt would give the
provider a different key on a retry, which is precisely how you double-pay. A
derived key means a replay is byte-identical, and the provider's own dedup
becomes a second safety net. It is also `UNIQUE` on our side.

### Layer 4 — state transitions as conditional updates

Every transition is a single conditional `UPDATE`:

```sql
UPDATE payout_items
   SET status = 'submitted', submitted_at = NOW(), attempts = attempts + 1
 WHERE id = ? AND status = 'pending'
```

If `affectedRows === 0`, somebody else already moved this item and **this worker
returns without acting**. This is compare-and-swap in the database. It needs no
lock, no coordination, and it is correct under any number of concurrent workers.

Laravel's `ShouldBeUnique` on the job is present too, but it is a *politeness*
layer — it reduces wasted work. It is explicitly **not** the correctness
mechanism, because it depends on a cache lock that can expire. Correctness lives
in the four layers above, all of which are in MySQL.

### Why not just `Cache::lock()`?

A distributed lock is a liveness tool, not a safety tool. Redis locks expire; a
worker paused by GC past its TTL will resume believing it still holds the lock.
A unique index does not have a TTL. `Cache::lock()` is used on the Artisan
command to stop pointless duplicate work, never to prevent double payment.

---

## 7. The unreliable provider

The provider can succeed, fail permanently, or **time out after it has already
moved the money**. The third case is the whole problem: the outcome is not
`failure`, it is **`unknown`**, and treating unknown as failure is how you pay
twice.

### Write-ahead, then call

```
1. BEGIN
2. pending → submitted                     (conditional update)
3. reserved_minor += amount                (money is now neither available nor paid)
4. COMMIT                                  ← durable record of intent
5. call the provider                       ← only now
```

If the process dies between 4 and 5, or during 5, recovery finds a `submitted`
row and knows an attempt may be in flight. **The record of intent is always
written before the side effect.** Nothing is inferred from the absence of a row.

### The state machine

```
                  ┌──────────────► succeeded (terminal)
                  │                    ▲
pending ──► submitted ──► unknown ─────┤
                  │          │         │
                  │          └─────────┴──► failed (terminal)
                  └──────────────► failed
```

- **Timeout / connection error →** `unknown`. Never `failed`. Never retried
  blindly.
- **`unknown` is resolved only by asking the provider**, via
  `status($idempotencyKey)` — never by guessing, and never by re-sending.
- A `submitted` row that has gone stale (no response, no crash detected) is swept
  into `unknown` by a reconciler and resolved the same way.

### What `reserved_minor` buys

While an item is `submitted` or `unknown`, its amount sits in `reserved_minor`:
not paid, not available. So the next payout run computes
`earned − paid − reserved` and **cannot include money that might already be in
flight.** This is what stops the second run from re-paying an uncertain first
attempt, without needing to know the first attempt's outcome yet.

Resolution moves it out:

- `succeeded` → `reserved −= amount`, `paid += amount`, write a `payout` ledger
  entry (deduped by `UNIQUE (type, source)`).
- `failed` → `reserved −= amount`, no ledger entry. The money becomes available
  again and the next run picks it up naturally.

### Retry policy by failure class

| Provider outcome | Classification | Action |
|---|---|---|
| success | terminal | settle, write ledger entry |
| permanent failure (rejected account, etc.) | terminal | release reservation, surface for human review |
| timeout / network error | **unknown** | keep reserved, schedule status check with backoff |
| stale `submitted` | **unknown** | same as timeout |

Retries use exponential backoff with jitter. Crucially, the retry is a **status
check**, not a resend — the only thing that ever resends is an item the provider
has explicitly confirmed it never received.

### The mock

`MockPaymentProvider` randomly picks one of the three outcomes and — importantly
— **remembers internally what actually happened** in the timeout case. So
`status()` can later reveal "that one you never heard back about? it succeeded."
The tests drive it deterministically with a seeded sequence so every branch is
exercised on purpose rather than by luck.

---

## 8. Refunds

**Policy: pro-rata refund of the unearned portion.** A student leaving in month 4
of 12 gets months 5–12 back, not the whole year.

This is the decision that makes refunds boring, and it is only available *because*
of the recognition rule in §2:

- Future periods were never accrued → nothing to reverse, they are simply voided.
- The current partial period is prorated to the cancellation date; the unaccrued
  slice is voided.
- Already-accrued periods stand. The instructor taught; the student watched.

So in the normal case, **a mid-term refund does not touch any instructor's
balance at all.** No clawback, no negative balance, no awkward email.

### Clawbacks still exist, as the exception

Fraud and chargebacks force a full refund of already-earned money. That path is
modelled:

- A `clawback` ledger entry reverses the earning, which can drive a balance
  negative.
- A negative balance is **netted against future earnings**, never collected by
  demanding money back.
- A ledger entry never disappears; the reversal is a new row. History stays
  intact, which is the entire reason for an append-only ledger.

If an instructor's balance is negative and they have stopped earning, that is a
business-recovery problem, not a schema problem — the system reports it and stops
paying. Deciding what to do about it is out of scope, and I would rather flag
that boundary than pretend the code resolves it.

### Minimum payout threshold

Payouts below **100.00 EGP** (`10000` minor) are skipped and carried forward.
Provider fees make dust payouts value-destroying for both sides. The amount stays
in the balance, fully visible as outstanding, and joins the next run.

---

## 9. Scaling — measured, not asserted

Target: 500k active subscriptions, tens of millions of ledger rows.

Everything below was run rather than reasoned about. `ledger:benchmark` reproduces
the query numbers on whatever data is present, and fails the build if any measured
query reports a full table scan.

### What was measured

Dataset: 20,000 subscriptions, 2,000 instructors, 6,000 courses, **720,000 engagement
rows** (seeded in 83s), 6,489 allocations, 19,467 ledger entries.

| Query | Median | EXPLAIN | Index used |
|---|---|---|---|
| Balance for one instructor | 0.34 ms | `const` | PRIMARY |
| Payable instructors (keyset page of 1,000) | 0.41 ms | `index` | PRIMARY |
| Engagement window (one student, one period) | 0.38 ms | `ref` | `engagements_student_period_idx` |
| Allocation idempotency lookup | 0.26 ms | `const` | `allocations_unique_per_period` |
| Sweep for uncertain payouts | 0.33 ms | `range` | `payout_items_sweep_idx` |
| Rebuild one balance from the ledger *(audit only)* | 0.34 ms | `ref` | `ledger_entries_instructor_time_idx` |

Every index in the schema was chosen for a query on that list. None is speculative.

### Accrual throughput, and where it actually goes

Single process: **~42 subscriptions/second**. At 500k that is roughly 3.5 hours for one
period, which is too slow to leave unexamined.

Profiling 300 subscriptions put the read side at **1.7 ms** total — payment lookup
0.52 ms, recognition schedule 0.62 ms, engagement window 0.56 ms. The remaining ~22 ms
is the write path: one transaction, the allocation row, one line per instructor, a
ledger entry per instructor, and a balance movement per instructor. About **15 round
trips**, and every one of them is there because an allocation must commit or not
commit as a unit.

Two micro-optimisations were made and measured honestly:

- Balance arithmetic moved into SQL (`SET x = x + ?`) instead of
  `SELECT ... FOR UPDATE` → read into PHP → write back. Three round trips became one,
  and the correctness argument got *shorter*: a single statement is atomic in InnoDB,
  so a lost update is impossible without a read at all.
- `UPDATE` before `INSERT` on the balance row. A row is missing exactly once per
  instructor ever; an unconditional `insertOrIgnore` was a wasted statement on every
  earning after that.

Combined effect: **39 → 42 subscriptions/second.** Real, and small. Recorded at that
size rather than rounded up, because the first draft of this document claimed the
change "roughly doubled throughput" before anyone had measured it.

### The answer to volume is parallelism

The work is embarrassingly parallel, and the schema already makes concurrency safe.
`ledger:accrue --shard=i/N` partitions on `id % N`:

```bash
for i in $(seq 0 5); do
  php artisan ledger:accrue --period=2026-06 --shard=$i/6 &
done; wait
```

Measured on the same 6,489 subscriptions: **158s → 69s** across 6 shards, producing
**exactly 6,489 allocations** — the same number as the single-process run, with zero
duplicates.

### The deadlock this uncovered

The first version of this section claimed shards "do not even contend, because the
partitions are disjoint." That was wrong, and running it proved so: **2 of 6 shards
died on `SQLSTATE 40001` deadlocks.**

Shards partition *subscriptions*. But different subscriptions share *instructors*, so
concurrent shards contend on the same `instructor_balances` rows — and on the
insert-intention gap locks taken when a balance row is created for the first time.

The fix is one argument: `DB::transaction($callback, attempts: 3)`.

**And it is safe only because the transaction is already idempotent.** A blind retry
of a money-moving transaction is normally reckless; here the unique indexes on
`allocations` and `ledger_entries` mean a replay writes nothing. The property built for
crash recovery turned out to answer lock contention too, at the cost of one parameter
instead of a new design. That is the strongest argument for putting idempotency in the
schema rather than in the application: it keeps paying out in situations it was not
designed for.

After the fix, six parallel shards produce zero integrity violations:

| Check | Violations |
|---|---|
| Duplicate allocations for the same (subscription, period) | **0** |
| Allocations where `platform + Σ lines ≠ gross` | **0** |
| Duplicate ledger entries for the same source | **0** |
| Balances disagreeing with the ledger sum | **0** |

### Structural choices behind those numbers

**Keyset pagination everywhere.** `WHERE id > ? ORDER BY id LIMIT n`, never `OFFSET`.
An offset scan re-reads every earlier row on every page, so a batch job gets slower the
further it gets — the standard way something that worked at 10k rows dies at 10M.

**Many small transactions, not one big one.** One transaction per allocation, not one
per run. A single long transaction holds locks for minutes, blocks everything, and
loses all its work on one failure. Small transactions are individually retryable, which
is what makes both crash recovery and deadlock retry cheap.

**Reads never scan the ledger.** Dashboards read `instructor_balances` — one row per
instructor. The ledger is for audit and rebuild. This is the entire reason the snapshot
exists.

**Queue isolation.** Payout jobs run on a dedicated `payouts` queue, so application
work cannot starve money movement and payout workers can be scaled or paused
independently. Being able to pause payouts during an incident is a capability worth
having before you need it.

**Partitioning is the next step, not this one.** `ledger_entries` partitioned by month
would keep the working set small past a few hundred million rows. Not implemented: it
adds real operational complexity and nothing in the design depends on it.

## 9b. Testing strategy

118 tests, 14,427 assertions. The count is not the point; what they protect against is.

### Properties, not examples

The money invariant is checked across **3,000 generated random splits** rather than a
handful of chosen numbers:

> For every allocation, `platform + Σ instructor amounts == the original amount`,
> exactly, with no tolerance.

Random totals (including negatives, for reversals), 1–9 recipients, weights up to a
month of watched seconds, arbitrary instructor ids. Seeded with a fixed value, so a
failure is reproducible rather than a one-off. A second property asserts the defining
characteristic of largest remainder: no recipient is ever more than one minor unit from
their exact share.

Recognition is verified the same way: **3 plans × 365 possible start dates = 1,095
schedules**, each asserted to recognise exactly the amount paid. If the
final-period-absorbs-the-residual rule were wrong in a leap year, on a 31st, or across a
year boundary, that catches it.

### Structural tests, not name guesses

An early test asserted `method_exists(Money::class, 'toFloat') === false`. PHPStan
pointed out the result was statically knowable — which revealed the test was weak: it
guessed two method names. It was replaced with reflection over every public method,
asserting none returns `float` and none is missing a return type. That catches a
float-returning accessor added next year under any name.

### The three the brief asked for, and how they are proven

| Requirement | How it is proven |
|---|---|
| Running the payout process twice never double-pays | Run the command 2, 3, then 4 times. Assert one batch, one item, one ledger entry, **and exactly one row in the provider's own books** |
| Retried jobs never double-pay | Dispatch the same job 5 times. Separately: claim an item, simulate SIGKILL mid-flight, then let the queue retry — assert nothing was sent |
| Unreliable provider responses never cause duplicates | Timeout-after-success, timeout-before-anything, and permanent failure, each scripted deterministically |

Replays are additionally proven by the `version` counter on the balance snapshot —
asserting the value is unchanged only shows it ended up back where it started;
asserting the counter is unchanged shows **no write happened at all**.

### Deterministic failure, never random

The mock provider's outcomes are scripted in tests (`ScriptedOutcomes`), never random.
A test that depends on randomness proves nothing when it passes and cannot be debugged
when it fails. The weighted-random decider exists only for manual runs and demos.

The provider is *real* in these tests — only its outcome decider is swapped. So the
code under test is the production code path, including the provider's own dedup table,
rather than a stub that agrees with our assumptions.

### Two test-infrastructure decisions that cost real debugging time

Both are documented in `phpunit.xml`, because either one silently invalidates the suite:

1. **`force="true"` on every env override.** Docker Compose was passing the project
   `.env` into the container via `env_file`, so `DB_DATABASE` existed as a real process
   environment variable. PHP then populated `$_SERVER` from it, and Laravel's `env()`
   prefers `$_SERVER` over `$_ENV` — so `phpunit.xml` was being ignored entirely and the
   suite ran against the **development** database, with `RefreshDatabase` wiping it.
   The root fix was removing `env_file` from the PHP services (the app reads `.env` from
   the bind mount anyway); `force="true"` stays as the belt.

2. **`REDIS_CACHE_LOCK_CONNECTION=cache`.** `config/cache.php` puts cache *locks* on a
   different Redis connection — and database — than cache *values*. So `Cache::flush()`
   clears the values and leaves every lock behind. `ShouldBeUnique` jobs key their lock
   on a model id, `RefreshDatabase` resets auto-increment ids to 1, and a lock left by an
   earlier **test run** silently swallowed a dispatch for the next 300–600 seconds. The
   symptom was a test that passed, then failed on an immediate re-run, then passed again
   ten minutes later. Found by flushing Redis, running twice, and scanning for the key —
   it was in database 0 while the test cache was database 2.

### The verification command is part of the test strategy

`ledger:verify` recomputes every balance from the ledger and every allocation from its
lines, and a feature test asserts it passes after a refund. It also runs against the
seeded demonstration data — where it immediately caught the seeder faking a paid balance
by writing `paid_minor` directly instead of paying through the pipeline. A tool that
fails on its own author's shortcut is doing its job.

### End-to-end, on a fresh clone, under a real crash

Beyond the suite, the whole system was exercised from `git clone` through `make setup`
on a clean machine state, against the real Redis queue and worker container:

- 211 payouts queued; the worker **SIGKILLed** two seconds into the batch, leaving one
  item frozen in `submitted` mid-provider-call; then `payouts:run` re-run for the same
  period while the worker drained.
- 49 provider timeouts, some after the money had moved, all resolved by status checks
  the send path scheduled for itself.
- The item killed mid-flight was **not re-sent** when the queue retried it — the
  conditional update found it `submitted` and stopped. The stale sweep then asked the
  provider, which had no record, and released the money.

Result: zero violations across every integrity check, and the provider's own books,
the ledger and the balance snapshots agreeing on **40,273.64 EGP paid — to the piastre.**

Three configuration invariants that no behavioural test could catch — because the suite
runs on the sync driver, where they do not exist — are now pinned by
`tests/Feature/QueueConfigurationTest.php`: `retry_after` must exceed every payout job's
timeout, and Horizon must supervise the queue payouts actually use. Both were wrong.

### What is deliberately not tested

Load and soak behaviour. `ledger:benchmark` measures point queries and fails on a full
table scan, which is a different and weaker claim than "this holds under sustained
production traffic." Stating the weaker claim honestly is better than implying the
stronger one.

---

## 10. Known limitations

Stated plainly, because a submission that hides these is worse than one that
names them.

1. **Engagement data is assumed trustworthy and complete.** Late-arriving
   engagement events after a period is allocated would need a restatement path.
   Not built.
2. **Single currency.** `Money` carries a currency and refuses to mix, but there
   is no FX, no multi-currency payout, no rate history.
3. **`ledger_entries` is not partitioned.** Fine for tens of millions; needs
   attention beyond that (see §9).
4. **No provider webhooks.** Resolution of `unknown` items is pull-only
   (scheduled status checks). A webhook would shorten the uncertainty window but
   adds its own idempotency surface — which the existing unique indexes would
   already cover, so this is a small addition rather than a redesign.
5. **`ledger:verify` is a command, not an alarm.** In production it belongs on a
   schedule wired to paging, because silent snapshot drift is the failure mode
   most likely to go unnoticed.
6. **Tax, invoicing, and instructor payout-method validation are out of scope.**
   Real payouts fail on bad bank details far more often than on provider
   outages; that validation lives upstream of this system.
7. **The 30% platform cut is global.** Per-instructor negotiated rates would fit
   the schema (the rate is already snapshotted per subscription) but are not
   implemented.
8. **`watch_time` has no anti-domination cap.** A single very long video can take
   most of a period. See §3; the mitigation is a policy decision, not an
   oversight.
9. **Single-process accrual is ~42 subscriptions/second.** Acceptable only because
   the work shards cleanly (§9). A deployment at 500k subscriptions needs the shard
   loop wired into the scheduler, which is currently a manual invocation.
10. **A monthly plan starting on the 31st runs 28 days, not 31.** Carbon's month
   arithmetic is clamped rather than allowed to overflow into a third calendar month.
   Internally consistent — recognition is by day, so the student pays for and the
   instructors earn over exactly those days — but it is a real edge, and the
   alternative (a "monthly" subscription spanning March 3rd) is worse.
11. **The balance snapshot can be rebuilt but is not rebuilt automatically.**
   `ledger:verify --fix` exists; nothing invokes it. Deliberate: silently
   self-healing a drift would destroy the evidence of whatever caused it.

---

## 11. Senior bonus — mid-term plan changes (discussion only)

*Not implemented, as instructed. Sketching how the design absorbs it.*

A student upgrades from monthly to annual in month 2 of an annual term, or
switches plans partway through.

The design handles this **without new concepts**, which is the real point:

1. **Recognition already knows what is unearned.** The straight-line schedule
   makes "how much of the old plan has not yet been earned" a subtraction, not a
   judgement call. That figure is the fair credit.
2. **A plan change is a termination plus a purchase, sharing a transaction.**
   Void the old subscription's unaccrued periods (exactly the refund path in §8),
   then create the new subscription with the credit applied as a reduced first
   payment.
3. **Instructor balances are untouched.** Earned is earned. The adjustment is
   entirely between the platform and the student, because recognition and
   attribution are separate concerns (§1). This is the payoff for that split.
4. **Proration credit is money, so it goes through the ledger** — as a
   `platform_credit` entry against deferred revenue, not as a mutated
   subscription amount. Editing the original amount would destroy the audit trail
   and make the original allocation unreproducible.

The one genuinely hard case: a **downgrade** where the credit exceeds the new
plan's price. That is a refund plus a subscription, and it needs a policy
decision on whether cash leaves the platform — a business question, not a
technical one. I would push back on building it until Finance states the rule.

