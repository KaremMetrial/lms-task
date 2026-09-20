<?php

declare(strict_types=1);

namespace App\Domain\Money;

use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Domain\Money\Exceptions\MoneyOverflow;
use JsonSerializable;
use Stringable;

/**
 * An exact monetary amount, held as an integer number of minor units.
 *
 * Three rules, and they are the reason this class exists at all:
 *
 *   1. There is no float anywhere. `$minor` is piastres, cents, fils — never a
 *      fractional major unit. There is deliberately no toFloat() to reach for.
 *   2. Arithmetic across currencies throws rather than guessing.
 *   3. allocate() is the ONLY place in the codebase that divides money.
 *
 * Rule 3 is the important one. Allocation strategies decide *weights*; this class
 * decides how a total is split across them. So the invariant
 *
 *     sum(allocate(...)) === the original amount, exactly
 *
 * is enforced in exactly one place, and a new strategy cannot introduce a
 * rounding bug no matter how it is written.
 *
 * @immutable
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public int $minor,
        public string $currency,
    ) {}

    public static function of(int $minor, string $currency = 'EGP'): self
    {
        return new self($minor, strtoupper($currency));
    }

    public static function zero(string $currency = 'EGP'): self
    {
        return new self(0, strtoupper($currency));
    }

    // ── Arithmetic ──────────────────────────────────────────────────────────

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    public function absolute(): self
    {
        return new self(abs($this->minor), $this->currency);
    }

    // ── Comparison ──────────────────────────────────────────────────────────

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor < $other->minor;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor >= $other->minor;
    }

    // ── Proportions ─────────────────────────────────────────────────────────

    /**
     * The share of this amount given by $bps basis points, rounded DOWN.
     *
     * Rounding down is chosen so that a "share plus remainder" pair can never
     * exceed the original: the caller derives the counterpart with minus(), and
     * the two are exact by construction. Rounding to nearest here would let
     * share + counterpart drift above the total by one minor unit.
     *
     * 10_000 bps = 100%.
     */
    public function shareOfBps(int $bps): self
    {
        if ($bps < 0) {
            throw new \InvalidArgumentException("Basis points cannot be negative, got {$bps}.");
        }

        $this->assertProductFits($this->minor, $bps);

        return new self(intdiv($this->minor * $bps, 10_000), $this->currency);
    }

    /**
     * Split this amount across weighted recipients using the largest remainder
     * method, so that the parts sum to the original EXACTLY.
     *
     * The algorithm:
     *   1. Each recipient gets floor(total × weight ÷ weightSum).
     *   2. The leftover minor units — total minus the sum of those floors — are
     *      handed out one each, to the recipients with the largest fractional
     *      remainder.
     *   3. Ties break by array key ascending.
     *
     * Step 3 matters more than it looks. Without a deterministic tie-break, the
     * same inputs could produce different splits on different runs, which makes
     * the result untestable and makes an instructor's payout depend on hash
     * ordering. Keys here are instructor ids, so "lowest id wins the extra
     * piastre" is stable, explainable and reproducible forever.
     *
     * @param  array<int|string, int>  $weights  recipient key => non-negative weight
     * @return array<int|string, self> same keys, sorted ascending
     */
    public function allocate(array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        foreach ($weights as $key => $weight) {
            if ($weight < 0) {
                throw new \InvalidArgumentException("Weight for '{$key}' cannot be negative, got {$weight}.");
            }
        }

        $weightSum = array_sum($weights);

        if ($weightSum === 0) {
            throw new \InvalidArgumentException(
                'Cannot allocate against weights that sum to zero — the caller must decide what a '
                .'zero-weight window means before asking for a split.'
            );
        }

        // Deterministic tie-breaking depends on a known key order.
        ksort($weights, SORT_NUMERIC | SORT_FLAG_CASE);

        $base = [];
        $remainders = [];

        foreach ($weights as $key => $weight) {
            $this->assertProductFits($this->minor, $weight);

            $product = $this->minor * $weight;

            // intdiv truncates toward zero, which for a negative total (a reversal
            // being split back out) would round the wrong way. Use floor division
            // so the behaviour is consistent in both directions and the leftover
            // is always non-negative.
            $floor = (int) floor($product / $weightSum);

            $base[$key] = $floor;
            $remainders[$key] = $product - ($floor * $weightSum);
        }

        $leftover = $this->minor - array_sum($base);

        // Order by remainder descending, then by key ascending. array_keys on the
        // already-ksorted array gives us the stable secondary ordering for free.
        $order = array_keys($remainders);
        usort($order, function ($a, $b) use ($remainders) {
            return $remainders[$b] <=> $remainders[$a]
                ?: $a <=> $b;
        });

        foreach (array_slice($order, 0, abs($leftover)) as $key) {
            $base[$key] += $leftover > 0 ? 1 : -1;
        }

        return array_map(fn (int $minor): self => new self($minor, $this->currency), $base);
    }

    // ── Presentation ────────────────────────────────────────────────────────

    /** Major units, for display only. Never feed this back into arithmetic. */
    public function format(int $minorUnitDigits = 2): string
    {
        $divisor = 10 ** $minorUnitDigits;
        $sign = $this->minor < 0 ? '-' : '';
        $abs = abs($this->minor);

        return sprintf(
            '%s%s.%0'.$minorUnitDigits.'d %s',
            $sign,
            number_format(intdiv($abs, $divisor)),
            $abs % $divisor,
            $this->currency,
        );
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /** @return array{minor: int, currency: string} */
    public function jsonSerialize(): array
    {
        return ['minor' => $this->minor, 'currency' => $this->currency];
    }

    // ── Guards ──────────────────────────────────────────────────────────────

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }

    /**
     * PHP does not raise on integer overflow — it silently promotes to float,
     * which in a ledger means a balance that is quietly approximate. Every
     * multiplication in the money path is therefore bounds-checked first, so the
     * failure is a loud exception instead of a wrong number.
     */
    private function assertProductFits(int $a, int $b): void
    {
        if ($a === 0 || $b === 0) {
            return;
        }

        if (intdiv(PHP_INT_MAX, abs($b)) < abs($a)) {
            throw MoneyOverflow::forProduct($a, $b);
        }
    }
}
