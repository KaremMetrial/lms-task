# AI Usage

AI was used substantially on this submission. This document is specific about where,
because "AI was used" is not useful information and the quest asks for ownership rather
than a disclaimer.

Tool: Claude (Opus) in Claude Code, driving a real Docker environment — running
migrations, executing the test suite, seeding data at volume, and inspecting MySQL
directly. That mattered more than the code generation, for reasons in
[§4](#4-what-running-it-found-that-review-did-not).

---

## 1. Workflow

Deliberately **decisions first, code second**:

1. **Understand the brief and name the ambiguities.** Before any code, the open
   questions were listed explicitly: when money counts as earned, how a payment divides
   between instructors, what happens at the messy edges.
2. **Write `ARCHITECTURE.md` first.** Every decision recorded with its trade-off before
   a line of implementation. That document is not a write-up of what was built; the code
   follows it.
3. **Schema next, because the schema is the guarantee.** Wherever a rule could be
   enforced in PHP or in MySQL, it went in MySQL.
4. **Pure logic with property tests.** `Money`, `RecognitionSchedule` and the weighting
   strategies were built and tested framework-free before touching persistence.
5. **Then the pipeline**, then the admin screen, then volume and measurement.
6. **Run everything at scale and fix what that exposed** — which turned out to be the
   most valuable phase of the whole exercise.

### Prompts that did the work

Not verbatim, but representative of the shape:

- *"Separate the two questions the brief is conflating: how much is earned by date X,
  and who earns it. Show me where that separation pays off."*
- *"The strategy interface returns finished amounts. That means every new strategy has
  to re-prove the rounding invariant. Restructure so it returns weights only."*
- *"I claimed this doubled throughput. Measure it and correct the comment."*
- *"Six parallel shards, two died. Find out why, do not just add a retry."*
- *"This test asserts `method_exists` on two names I guessed. Make it structural."*

The productive pattern was **making AI defend a claim, then checking it**. Several
claims did not survive — [§4](#4-what-running-it-found-that-review-did-not).

---

## 2. Generated vs. designed

### Decisions I made, and would defend in a review

These are architecture, not code, and none came from asking "how should I build this":

1. **Separating recognition from attribution** (§1 of ARCHITECTURE). Most of the
   brief's ambiguity dissolves once *how much is earned by date X* and *who earns it* are
   two independent rules. This is the single decision the rest of the design hangs off,
   and it is why a mid-term refund touches no instructor balance.
2. **Ratable recognition instead of day-one.** Chosen specifically for its effect on
   refunds: the unearned portion was never recognised, so it was never payable, so it was
   never paid. Clawbacks become the exception rather than the norm. Picking the right
   recognition rule replaced a pile of refund code.
3. **Monthly accrual granularity.** 500k × 365 = 182M rows a year rejected in favour of
   ~6M, accepting mid-period refund proration as the cost.
4. **Idempotency in the schema, not the application.** Four unique indexes, and a
   conditional `UPDATE` as the only state transition. `Cache::lock()` is present but
   explicitly labelled a liveness tool — locks expire, indexes do not. This decision paid
   for itself twice; see [§4](#4-what-running-it-found-that-review-did-not).
5. **`reserved_minor`, and `unknown` as a first-class state.** A timeout is not a
   failure. The money is held, and `available = earned − paid − reserved` structurally
   cannot offer a second run money that may already be in flight.
6. **Strategies return weights; `Money::allocate()` is the only divider.** So a new
   weighting rule cannot introduce a rounding bug. This was a correction to my own first
   design, which had strategies returning finished amounts.
7. **Equal-weight as the default over watch time** — gameability, telemetry
   trustworthiness, explainability. With the accepted unfairness asserted in a test
   rather than left to be discovered.
8. **Rates snapshotted per subscription.** Allocation reads the subscription row, never
   config, so changing the platform's cut cannot silently rewrite last year's earnings.
9. **Tests on real MySQL.** The correctness claims are about engine behaviour; SQLite
   would make them meaningless.
10. **Read-only admin panel as a constraint.** An editable balance is a write path that
    bypasses the ledger. Corrections belong in an `adjustment` entry with a reason.

### Substantially AI-generated, then reviewed

- Boilerplate: 15 migrations, 14 factories, 13 models with their `@property` annotations,
  enum scaffolding, the Dockerfile and Compose topology.
- Filament resource and relation manager wiring.
- Test bodies, once I had specified what each test must prove. The property-test *idea*
  was mine; the generation loops were not.
- Prose in `ARCHITECTURE.md` and this file, from my decisions and notes.

### AI suggestions I rejected

- **A `float`/`DECIMAL` money column.** Non-negotiable: integer minor units only.
- **`if (! exists) { create() }` for idempotency.** Two workers both pass the check.
  The unique index is the mechanism; a pre-check is either redundant or wrong.
- **Treating a provider timeout as a failure and retrying.** The exact double-payment
  bug the brief is testing for.
- **A random ULID per payout attempt.** A retry would present a different identity to
  the provider. The key is derived from `(batch, instructor)`.
- **`Cache::lock()` as the idempotency guarantee.** A distributed lock with a TTL cannot
  be the safety mechanism for money.
- **Editing the original allocation row on a partial refund.** Destroys the audit trail
  and makes the historical split unreproducible. Corrections are new entries.
- **Suppressing 43 PHPStan errors.** They were symptoms of missing `@property`
  annotations. Fixed at the source; level 6 is clean with 3 narrowly scoped, documented
  exemptions.
- **Self-healing balance drift.** `ledger:verify --fix` exists but nothing calls it
  automatically. Silently repairing drift destroys the evidence of its cause.

---

## 3. Mistakes AI made that I caught

Most were caught by *running* things, which is the argument for an agentic setup over
a chat window.

| What | Consequence if shipped |
|---|---|
| `MoneyCast` rejected plain integers | Every factory and seeder broken. The guard was in the wrong place — the real hazard is `float` (`(int) 29.99 → 29`, silently), not `int` on a column named `_minor` |
| `config/ledger.php` used outcome keys (`succeeded`, `failed`) that do not exist on `MockOutcome` | First real payout run threw **after** an item had moved to `submitted`. Fixed by deriving the keys from the enum, plus validation at construction so a typo is a boot error, not a stranded payment |
| `AllocationOutcome` and `RefundOutcome` declared inside another class's file | PSR-4 violation. Tests passed because they loaded the sibling class first; autoloading the enum directly failed at runtime |
| `env()` inside `ScaleSeeder` | Returns `null` once `config:cache` has run. A 500k-row scale test would have silently seeded 20k instead |
| Filament filters: a default `outstanding` filter AND-ed with `negative`/`in_flight` | Filtering for negative balances showed nothing. Replaced with one mutually exclusive selector — they are four *views*, not four conditions |
| Relation manager pointed at `payoutItems()` on the wrong model | 500 on the admin screen |
| nginx `fastcgi_pass app:9000` as a literal | nginx resolves an upstream once at startup. Recreating the app container gave a 502 on every request while everything reported healthy. Fixed with a resolver and a variable, not a restart |
| A test asserting `method_exists` on two guessed names | Weak test that would miss a float accessor added under any other name |
| `DemoSeeder` faked a paid balance by writing `paid_minor` directly | Snapshot and ledger disagreed from the first seed. Caught by `ledger:verify`, not by review |
| `UID=$(id -u) docker compose build` in the README and Makefile | `UID` is **readonly in bash**, so the documented setup command fails before Docker runs. Found by following my own instructions from scratch |

And two arithmetic errors of my own, both in test expectations rather than in the code:

- Asserted `floor(249900 × 17 ÷ 365) = 11638`; it is `11639`. The test now computes the
  floor itself rather than relying on numbers typed by hand.
- Asserted `|heavyLost − 9 × lightLost| ≤ 2` for a 9:1 proportional reversal, which
  amplifies the small side's rounding error ninefold. Restated as the actual
  largest-remainder property: each party within one minor unit of their exact share.

---

## 4. What running it found that review did not

The three most valuable findings in this project came from executing it, not from
reading it. All three were **wrong claims in my own comments and documentation**, and
all three are now corrected in place rather than quietly deleted.

### "This roughly doubled throughput"

Written in a comment about moving balance arithmetic into SQL. Measured: **39 → 42
subscriptions/second.** Real, and small.

Profiling then showed why: the read path is 1.7 ms and the remaining ~22 ms is the ~15
round trips an atomic allocation needs. That is not waste to shave — it is the cost of
each allocation committing as a unit. The comment now records the measured figure and
points at sharding as the actual answer to volume.

A comment asserting an unmeasured performance claim is worse than no comment, and in
code that moves money it is exactly the habit that has to be broken.

### "Shards do not even contend"

Written about `--shard=i/N`. Running six shards killed two of them with `SQLSTATE 40001`
deadlocks.

Shards partition *subscriptions*; different subscriptions share *instructors*, so they
contend on the same `instructor_balances` rows. Obvious in hindsight and invisible in
review.

The fix is `attempts: 3` on the transaction — **and it is safe only because the
transaction is already idempotent.** Blindly retrying a money-moving transaction is
normally reckless; here the unique indexes mean a replay writes nothing. The property
built for crash recovery answered lock contention too, for one parameter instead of a
new design. That is the strongest argument in this codebase for putting idempotency in
the schema: it keeps paying out in situations it was not designed for.

Six shards now produce exactly the single-process allocation count with zero integrity
violations, in 69s instead of 158s.

### `ledger:verify` caught the seeder I wrote to demonstrate `ledger:verify`

The demo seeder needed an instructor with a negative balance, so it faked the
prerequisite: `UPDATE instructor_balances SET paid_minor = earned_minor`. Then the
chargeback drove the balance negative and the screen looked right.

Running `ledger:verify` on a freshly seeded database failed:

```
Instructor 6   Paid: 2,029.46 EGP  vs  0.00 EGP
```

The snapshot claimed money had been paid; the ledger had no entry to justify it. The
seeder was doing the one thing the entire design forbids — moving money without a
ledger entry behind it — and it did so in the file whose job is to demonstrate that
this cannot happen.

Fixed by reordering: pay the instructor through the real payout pipeline first, then
apply the chargeback. Same end state, reached legitimately, and `ledger:verify` now
passes on the seeded data.

Two things worth taking from it. The verification tool earns its place by failing on
its own author. And a seeder that writes state directly instead of driving the real
actions is not a shortcut — it is a place where the invariants silently do not apply.

### A test that passed, then failed, then passed again

The symptom looked like flakiness. It was not.

`config/cache.php` puts cache **locks** on a different Redis connection — and database —
than cache **values**. So `Cache::flush()` clears the values and leaves every lock in
place. `ShouldBeUnique` jobs key their lock on a model id; `RefreshDatabase` resets
auto-increment ids to 1; a lock left behind by an earlier **test run** silently swallowed
a dispatch for the next 300–600 seconds.

Found by a decisive experiment rather than by theorising: flush Redis, run once (passes),
run again immediately (fails). Then scan the keyspace — the lock was in database 0 while
the test cache was database 2.

My first theory (leakage between tests in one run) was wrong; the leak was between runs.
Worth recording because the wrong theory produced a fix that appeared reasonable and
changed nothing.

---

## 5. What differentiates this from a typical AI-generated submission

A model asked to "build an instructor payout system" produces something plausible:
`DECIMAL` money columns, `if (!exists) create()` idempotency, a timeout treated as a
failure, a `balance` column updated in place, and tests that assert the happy path on
round numbers. Every one of those is a double-payment or a corrupted-balance bug, and
every one of them looks fine in review.

What is different here:

1. **The decisions are argued, and the rejected alternative is named.** Every section of
   `ARCHITECTURE.md` says what was chosen, what it cost, and what was given up.
2. **Correctness is structural.** Four unique indexes and a generated column, not
   careful code. The strongest evidence: six concurrent processes, 6,489 allocations,
   19,467 ledger entries, zero violations — with no coordination between them.
3. **The rounding invariant lives in exactly one place**, so a future weighting strategy
   cannot break it. That was a correction to my own first design.
4. **Properties over examples.** 3,000 random splits; 1,095 recognition schedules.
5. **`unknown` is a first-class state**, distinct from `failed`, visible as such in the
   admin panel, and resolvable only by asking. The two indistinguishable timeout cases
   are both modelled precisely because they are indistinguishable.
6. **Performance is measured and reported honestly**, including the unflattering
   number. 42/second, profiled, with the bottleneck named and the parallel answer
   demonstrated rather than promised.
7. **Four of my own claims were disproven by running the system, and are corrected in
   place.** The wrong version is still described, because how it was found is the useful
   part. One of them was caught by this project's own verification command, failing on
   its own seeder.
8. **The limitations list is 11 items long** and includes things a submission would
   normally hide — a monthly plan starting on the 31st runs 28 days, accrual needs
   sharding at scale, `ledger:verify` does not page anyone.

---

## 6. Where I would push back on the brief

Two places, stated because engineering judgment includes disagreeing with the spec.

**Laravel 11 in a system that moves money.** Three security advisories affect every
Laravel 11 release and were patched only in 12.60 / 13.10, with no backport. Composer
refuses the install. Filament v3 supports Laravel 12, so `Laravel 12 + Filament v3 +
Livewire v3` would have satisfied the stack with one documented deviation and no known
CVEs. The spec says Laravel 11, so Laravel 11 is what is here — with each advisory
listed individually in `composer.json` **with its reason**, so `composer audit` still
fails on anything new. For a real deployment this is the one line I would argue about.

**Equal-weight attribution is a placeholder for a product decision.** Splitting revenue
equally among engaged instructors is defensible and explainable, but 40 hours of one
instructor and one lesson of another splitting 50/50 is a real cost. `watch_time` ships
as an alternative and the strategy is swappable by config, but the right rule is a
business decision informed by data this system does not yet have. Building both and
making the choice configurable was the honest response to being unable to answer it.
