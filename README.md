# Instructor Revenue Ledger

The money core of an LMS: subscription payments in, instructor payouts out, correct
under retries, crashes, concurrent runs, an unreliable payment provider, and refunds.

Built for the Career 180 Hiring Quest. Not a full application — the deliberate scope
is the part that moves money, plus the evidence that it stays correct.

**Start here:** [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) records the decisions and
the reasoning. [`docs/AI_USAGE.md`](docs/AI_USAGE.md) covers how AI was used.

---

## What it does

| | |
|---|---|
| **Recognises** | prepaid subscription revenue straight-line across the term, in monthly accrual periods |
| **Attributes** | each period's instructor pool to the instructors the student actually engaged with — equal share by default, watch-time weighted optionally |
| **Records** | every movement in an append-only ledger, with a reconciled balance snapshot for fast reads |
| **Pays** | instructors through a queued, idempotent payout pipeline against a deliberately unreliable provider |
| **Refunds** | pro rata, which under ratable recognition touches no instructor balance at all |
| **Proves** | 118 tests, 14,427 assertions, PHPStan level 6, plus a benchmark command that fails on a full table scan |

Answers, at any point in time: **how much each instructor is owed, how much has been
paid, and how much is still outstanding** — from a single indexed row, never by
scanning the ledger.

---

## Setup

Requires Docker and Docker Compose. Nothing else — no local PHP, MySQL or Node.

```bash
git clone <this-repo> && cd lms-task
make setup
```

That one target does, in order: copy `.env`, write your UID/GID into it, build the
image, start MySQL/Redis/php-fpm, `composer install`, generate an app key if there is
none, start the worker/scheduler/nginx, then migrate and seed the demonstration data.

The order is not cosmetic. `vendor/` is not committed, and the worker and scheduler run
`php artisan` as their main process — started before `composer install`, they crash-loop
on a missing `autoload.php`. An earlier version of this README listed the steps by hand
and produced exactly that on a fresh clone.

<details>
<summary>The same, by hand</summary>

```bash
cp .env.example .env
make env                                               # UID/GID into .env
docker compose build
docker compose up -d mysql redis app                   # NOT the worker yet
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose up -d                                   # now the worker, scheduler, nginx
docker compose exec app php artisan migrate:fresh --seed
```
</details>

Then open **http://localhost:8000/admin** and sign in:

```
admin@career180.test / password
```

The seeded panel deliberately shows every state the system can reach, including one
payout stuck in `unknown` — see [Walkthrough](#walkthrough).

The `make build` step writes your host `UID`/`GID` into `.env` first, so files the
container writes through the bind mount stay editable on the host. Docker Compose reads
those for `${VAR}` substitution.

> Not `UID=$(id -u) docker compose build` — `UID` is a **readonly** variable in bash, so
> that form fails before Docker is even invoked. The value has to reach Compose through
> `.env`. This bit the first run of these very instructions.

### Without Docker

Works on any PHP 8.3 with MySQL 8 and Redis. Set `DB_HOST=127.0.0.1`,
`DB_PORT=3307`, `REDIS_HOST=127.0.0.1`, `REDIS_PORT=6380` to reach the containerised
services from the host, then `composer install && php artisan migrate --seed`.

---

## Running the tests

```bash
make test                      # the whole suite
make test-filter F=Money       # one group
make stan                      # PHPStan level 6
make lint                      # Pint
```

**The suite runs against real MySQL, not in-memory SQLite.** That is a deliberate
trade-off, not an oversight: everything this project must prove depends on engine
behaviour SQLite either lacks or fakes — unique-index violations as the idempotency
mechanism, `SELECT ... FOR UPDATE`, `REPEATABLE READ` snapshots, deadlock detection and
retry. Passing on SQLite would say nothing about production. The cost is that the
suite needs a running database; `docker/mysql/init/01-databases.sql` creates the
dedicated `lms_test` schema for it.

Two details in `phpunit.xml` are load-bearing and each cost real debugging time —
both are documented in that file.

---

## Commands

```bash
php artisan ledger:accrue      --period=2026-06          # recognise a period
php artisan ledger:accrue      --period=2026-06 --shard=0/8   # one of 8 parallel shards
php artisan payouts:run        --period=2026-06          # claim a batch, fan out, dispatch
php artisan payouts:run        --period=2026-06 --dry-run
php artisan payouts:reconcile                            # resolve unknown outcomes (asks, never resends)
php artisan refunds:issue      12 --kind=prorata --on=2026-04-15
php artisan ledger:verify                                # recompute every balance from the ledger
php artisan ledger:benchmark                             # time + EXPLAIN the critical queries
```

All of them are scheduled in [`routes/console.php`](routes/console.php). All of them
are safe to run twice, concurrently, or by hand while the schedule is also running —
see [Idempotency](docs/ARCHITECTURE.md#6-idempotency).

### Seeding at volume

```bash
SEED_SUBSCRIPTIONS=500000 SEED_PERIODS=12 \
  docker compose exec app php artisan db:seed --class=ScaleSeeder
```

Measured here: 20,000 subscriptions and **720,000 engagement rows in 83 seconds**.
At 500k × 3 instructors × 12 periods the engagements table is ~18M rows, which is the
figure the scaling section is written against.

---

## Walkthrough

The seeded dataset is built by running the real actions, so it is a demonstration
rather than fixtures. After `make fresh`:

```bash
docker compose exec app php artisan ledger:verify
```

One instructor (**Omar Shafik**) has money in flight with an **unknown** outcome — the
provider moved it and the response was lost. The admin panel shows the amount under
*In flight*: not paid, not payable. Then:

```bash
docker compose exec app php artisan payouts:reconcile --sync
```

It resolves by **asking** the provider, and settles without a second transfer. Check
the provider's own books to confirm exactly one movement:

```bash
make mysql
> SELECT idempotency_key, outcome, response_lost FROM mock_provider_transactions;
```

To see a crashed worker handled safely:

```bash
make worker-kill      # SIGKILL mid-job
make up               # it comes back; the item is asked about, never re-sent
```

To watch jobs, retries and failures in a dashboard:

```bash
make horizon          # swaps the plain worker for Horizon → http://localhost:8000/horizon
make worker           # swaps back
```

Horizon **replaces** the worker rather than running beside it, because both would
consume the `payouts` queue.

---

## Assumptions

Where the brief left a rule unspecified, these are the calls made. Each is argued in
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

| Question | Decision |
|---|---|
| When is money earned? | Straight-line across the term, in monthly accrual periods — not on payment day |
| How is a payment divided? | Equally among the instructors engaged that period (`equal_weight`); watch-time weighting ships as an opt-in alternative |
| Platform cut | 30%, snapshotted onto the subscription at purchase so a rate change never rewrites history |
| Money representation | `BIGINT` minor units (piastres). No floats, no `DECIMAL`, anywhere in the money path |
| Uneven splits | Largest remainder method, ties broken by lowest instructor id — deterministic and reproducible |
| Period rounding residual | Absorbed by the final accrual period, so the schedule sums to the amount paid exactly |
| Refund policy | Pro rata — the student gets back the unconsumed part of the term |
| Already-paid money on a full refund | `clawback`, netted against future earnings, never collected by demanding money back |
| Payout floor | 100.00 EGP; below that the balance is carried forward rather than paid as dust |
| Payout cadence | Monthly, one batch per period, enforced by a unique index |
| Currency | Single (EGP). `Money` refuses cross-currency arithmetic rather than guessing a rate |
| Engagement with nobody | The whole period is platform revenue; nothing is owed |
| Instructor not onboarded for payouts | Earnings accrue, payouts wait. Nothing is lost |

### One assumption that is not ours to make

The brief specifies **Laravel 11**, and Laravel 11 is pinned here — `11.56.1`, with
Filament v3 and Livewire v3 exactly as required.

Three security advisories affect every Laravel 11 release and were only patched in
12.60 / 13.10, with no 11.x backport. Composer refuses the install outright. Rather
than disable the audit wholesale, each advisory is listed individually in
`composer.json` **with the reason**, so `composer audit` still fails on anything new:

```json
"audit": { "ignore": {
  "PKSA-mdq4-51ck-6kdq": "Laravel 11 is pinned by the challenge spec ...",
  ...
} }
```

Run `make audit` to see exactly three known, documented exemptions. For a real
deployment of a system that moves money this would be the one place to push back on
the spec — but the spec is the spec, so the deviation is documented rather than made
silently.

---

## Layout

```
app/
  Domain/                     framework-free money logic
    Money/                    Money value object — the only thing that divides money
    Recognition/              straight-line schedule, calendar-month proration
    Allocation/               weighting strategies + resolver
    Ledger/                   LedgerWriter, balance repository
    Payouts/                  state machine, provider contract, the unreliable mock
    Subscriptions/ Refunds/   enums
  Actions/                    one use case per class
  Jobs/Payouts/               queued send + reconcile
  Console/Commands/           accrue, payouts, refunds, verify, benchmark
  Filament/Resources/         the read-only admin screen
database/
  migrations/                 17 migrations — 14 for the ledger, 3 Laravel defaults
  factories/ seeders/         DemoSeeder (every state) + ScaleSeeder (volume)
docker/                       nginx, php-fpm, mysql config
tests/
  Unit/                       Money, recognition, weighting — no database
  Feature/                    allocation, payouts, refunds, admin screen
docs/                         ARCHITECTURE.md, AI_USAGE.md
```

---

## Known limitations

Named rather than hidden. The full list with reasoning is in
[ARCHITECTURE §10](docs/ARCHITECTURE.md#10-known-limitations).

- Single-process accrual runs at ~42 subscriptions/second; volume is answered by
  `--shard`, measured at 6 shards → 2.3× wall-clock on this hardware.
- `ledger_entries` is not partitioned. Fine at tens of millions; needs attention past that.
- Provider resolution is pull-only. A webhook would shorten the uncertainty window;
  the existing unique indexes would already make it safe.
- `ledger:verify` reports drift but does not page anyone. In production it should.
- Engagement data is assumed complete. Late-arriving events after a period is
  allocated would need a restatement path.
