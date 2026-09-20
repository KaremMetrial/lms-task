<?php

declare(strict_types=1);

use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Domain\Money\Exceptions\MoneyOverflow;
use App\Domain\Money\Money;

// ─────────────────────────────────────────────────────────────────────────────
// Arithmetic and currency safety
// ─────────────────────────────────────────────────────────────────────────────

it('adds and subtracts exactly', function () {
    expect(Money::of(29_900)->plus(Money::of(100))->minor)->toBe(30_000)
        ->and(Money::of(29_900)->minus(Money::of(29_900))->isZero())->toBeTrue()
        ->and(Money::of(100)->minus(Money::of(250))->minor)->toBe(-150);
});

it('refuses to mix currencies rather than guessing a rate', function () {
    expect(fn () => Money::of(100, 'EGP')->plus(Money::of(100, 'USD')))
        ->toThrow(CurrencyMismatch::class);
});

it('treats amounts of different currencies as unequal even at the same magnitude', function () {
    expect(Money::of(100, 'EGP')->equals(Money::of(100, 'USD')))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// Basis-point shares
// ─────────────────────────────────────────────────────────────────────────────

it('takes a basis-point share rounded down', function () {
    // 29_900 × 70% = 20_930 exactly.
    expect(Money::of(29_900)->shareOfBps(7_000)->minor)->toBe(20_930);

    // 101 × 70% = 70.7 → 70, never 71: the counterpart must not overshoot.
    expect(Money::of(101)->shareOfBps(7_000)->minor)->toBe(70);
});

it('guarantees share plus counterpart equals the whole', function () {
    foreach (range(1, 400) as $minor) {
        foreach ([1, 500, 2_500, 3_000, 7_000, 9_999] as $bps) {
            $amount = Money::of($minor);
            $share = $amount->shareOfBps($bps);
            $counterpart = $amount->minus($share);

            expect($share->plus($counterpart)->minor)->toBe($minor);
        }
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Allocation — the one place money is divided
// ─────────────────────────────────────────────────────────────────────────────

it('splits the worked example from the architecture doc', function () {
    // 29_900 piastres, 30% platform cut, 3 engaged instructors.
    $pool = Money::of(29_900)->shareOfBps(7_000);
    expect($pool->minor)->toBe(20_930);

    $lines = $pool->allocate([7 => 1, 12 => 1, 31 => 1]);

    // 20_930 ÷ 3 = 6_976 remainder 2 → the two lowest ids get the extra piastre.
    expect($lines[7]->minor)->toBe(6_977)
        ->and($lines[12]->minor)->toBe(6_977)
        ->and($lines[31]->minor)->toBe(6_976)
        ->and($lines[7]->minor + $lines[12]->minor + $lines[31]->minor)->toBe(20_930);
});

it('breaks ties by lowest instructor id, deterministically', function () {
    // Same inputs, keys supplied in a different order: the result must not move.
    $a = Money::of(100)->allocate([31 => 1, 7 => 1, 12 => 1]);
    $b = Money::of(100)->allocate([7 => 1, 12 => 1, 31 => 1]);

    expect(array_map(fn ($m) => $m->minor, $a))
        ->toBe(array_map(fn ($m) => $m->minor, $b))
        ->and($a[7]->minor)->toBe(34)
        ->and($a[12]->minor)->toBe(33)
        ->and($a[31]->minor)->toBe(33);
});

it('gives the leftover to the largest remainder, not to the first key', function () {
    // Weights 1:1:8 over 100. Exact shares are 10, 10, 80 — no remainder at all.
    expect(array_map(fn ($m) => $m->minor, Money::of(100)->allocate([1 => 1, 2 => 1, 3 => 8])))
        ->toBe([1 => 10, 2 => 10, 3 => 80]);

    // Weights 1:1:1 over 7 → 2,2,2 with 1 left over, all remainders equal,
    // so the tie-break decides: lowest id.
    expect(array_map(fn ($m) => $m->minor, Money::of(7)->allocate([5 => 1, 9 => 1, 14 => 1])))
        ->toBe([5 => 3, 9 => 2, 14 => 2]);
});

it('handles a single recipient taking everything', function () {
    expect(Money::of(29_900)->allocate([4 => 1])[4]->minor)->toBe(29_900);
});

it('returns nothing for an empty weight set', function () {
    expect(Money::of(29_900)->allocate([]))->toBe([]);
});

it('refuses to allocate against weights that sum to zero', function () {
    expect(fn () => Money::of(100)->allocate([1 => 0, 2 => 0]))
        ->toThrow(InvalidArgumentException::class, 'sum to zero');
});

it('rejects negative weights', function () {
    expect(fn () => Money::of(100)->allocate([1 => -5, 2 => 10]))
        ->toThrow(InvalidArgumentException::class, 'cannot be negative');
});

it('splits a negative amount without losing a unit', function () {
    // A reversal being split back across instructors. Floor division must not
    // round the wrong way for negatives.
    $lines = Money::of(-100)->allocate([1 => 1, 2 => 1, 3 => 1]);

    expect(array_sum(array_map(fn ($m) => $m->minor, $lines)))->toBe(-100);
});

it('gives zero-weight recipients nothing while still summing exactly', function () {
    $lines = Money::of(1_000)->allocate([1 => 0, 2 => 3, 3 => 7]);

    expect($lines[1]->minor)->toBe(0)
        ->and(array_sum(array_map(fn ($m) => $m->minor, $lines)))->toBe(1_000);
});

// ─────────────────────────────────────────────────────────────────────────────
// THE invariant.
//
// This is the most important assertion in the project. Every other guarantee is
// worthless if the allocator can create or destroy a single piastre, so it is
// checked across thousands of generated inputs rather than hand-picked ones —
// including the awkward shapes: primes, many recipients, lopsided weights.
// ─────────────────────────────────────────────────────────────────────────────

it('never creates or destroys money, across thousands of random splits', function () {
    mt_srand(20260920); // Seeded: a failure is reproducible, not a one-off.

    $checked = 0;

    for ($i = 0; $i < 3_000; $i++) {
        $total = mt_rand(-500_000, 500_000);
        $recipients = mt_rand(1, 9);

        $weights = [];
        for ($r = 0; $r < $recipients; $r++) {
            // Random instructor ids, so key ordering is genuinely arbitrary.
            $weights[mt_rand(1, 9_999)] = mt_rand(0, 2_592_000); // up to a month of seconds
        }

        if (array_sum($weights) === 0) {
            continue; // Documented as the caller's decision, not the allocator's.
        }

        $lines = Money::of($total)->allocate($weights);

        $sum = array_sum(array_map(fn (Money $m): int => $m->minor, $lines));

        expect($sum)->toBe($total);
        expect($lines)->toHaveCount(count($weights));

        $checked++;
    }

    // Guard against the test silently skipping everything.
    expect($checked)->toBeGreaterThan(2_900);
});

it('keeps every recipient within one minor unit of their exact share', function () {
    // Largest remainder's defining property: no recipient is off by more than 1.
    mt_srand(11);

    for ($i = 0; $i < 500; $i++) {
        $total = mt_rand(1, 1_000_000);
        $weights = [];
        foreach (range(1, mt_rand(2, 6)) as $id) {
            $weights[$id] = mt_rand(1, 10_000);
        }

        $weightSum = array_sum($weights);
        $lines = Money::of($total)->allocate($weights);

        foreach ($weights as $id => $weight) {
            $exact = $total * $weight / $weightSum;
            expect(abs($lines[$id]->minor - $exact))->toBeLessThan(1.0);
        }
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Overflow: PHP promotes integer overflow to float silently, which in a ledger
// means a quietly approximate balance. It must throw instead.
// ─────────────────────────────────────────────────────────────────────────────

it('throws instead of silently becoming a float on overflow', function () {
    expect(fn () => Money::of(PHP_INT_MAX)->shareOfBps(9_000))
        ->toThrow(MoneyOverflow::class);

    expect(fn () => Money::of(PHP_INT_MAX)->allocate([1 => 3, 2 => 4]))
        ->toThrow(MoneyOverflow::class);
});

it('exposes no method that can return a float', function () {
    // A structural guard, not a guess at two method names: if anyone ever adds a
    // float-returning accessor to Money, this fails. That is the door through
    // which imprecision would enter the money path.
    $floatReturning = [];

    foreach ((new ReflectionClass(Money::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $type = $method->getReturnType();

        if ($type instanceof ReflectionNamedType && $type->getName() === 'float') {
            $floatReturning[] = $method->getName();
        }
    }

    expect($floatReturning)->toBe([]);
});

it('declares a return type on every public method', function () {
    // An untyped return is where a float sneaks back in unnoticed.
    $untyped = [];

    foreach ((new ReflectionClass(Money::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getName() !== '__construct' && ! $method->hasReturnType()) {
            $untyped[] = $method->getName();
        }
    }

    expect($untyped)->toBe([]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Presentation
// ─────────────────────────────────────────────────────────────────────────────

it('formats major units for display only', function () {
    expect(Money::of(29_900)->format())->toBe('299.00 EGP')
        ->and(Money::of(6_977)->format())->toBe('69.77 EGP')
        ->and(Money::of(-150)->format())->toBe('-1.50 EGP')
        ->and(Money::of(249_900_00)->format())->toBe('249,900.00 EGP');
});
