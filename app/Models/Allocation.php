<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Allocation\AllocationStatus;
use App\Domain\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\AllocationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $subscription_id
 * @property int $payment_id
 * @property string $accrual_period
 * @property CarbonImmutable $period_starts_on
 * @property CarbonImmutable $period_ends_on
 * @property int $days_in_period
 * @property Money $gross_minor
 * @property Money $platform_minor
 * @property Money $instructor_pool_minor
 * @property string $currency
 * @property string $strategy
 * @property AllocationStatus $status
 * @property CarbonImmutable $allocated_at
 * @property CarbonImmutable|null $voided_at
 * @property-read Collection<int, AllocationLine> $lines
 */
final class Allocation extends Model
{
    /** @use HasFactory<AllocationFactory> */
    use HasFactory;

    protected $fillable = [
        'subscription_id', 'payment_id', 'accrual_period',
        'period_starts_on', 'period_ends_on', 'days_in_period',
        'gross_minor', 'platform_minor', 'instructor_pool_minor', 'currency',
        'strategy', 'status', 'allocated_at', 'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AllocationStatus::class,
            'gross_minor' => MoneyCast::class,
            'platform_minor' => MoneyCast::class,
            'instructor_pool_minor' => MoneyCast::class,
            'days_in_period' => 'integer',
            'period_starts_on' => 'immutable_date',
            'period_ends_on' => 'immutable_date',
            'allocated_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return HasMany<AllocationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(AllocationLine::class);
    }

    /**
     * The invariant this whole design rests on, as a runtime check.
     *
     * Used by the verification command; the same property is asserted
     * exhaustively over random inputs in the unit tests.
     */
    public function balances(): bool
    {
        $lineTotal = $this->lines->reduce(
            fn (int $carry, AllocationLine $line): int => $carry + $line->amount_minor->minor,
            0,
        );

        return $this->platform_minor->minor + $lineTotal === $this->gross_minor->minor
            && $lineTotal === $this->instructor_pool_minor->minor;
    }
}
