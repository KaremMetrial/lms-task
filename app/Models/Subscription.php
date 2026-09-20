<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Money\Money;
use App\Domain\Subscriptions\Plan;
use App\Domain\Subscriptions\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read Money $amount_minor
 * @property int $id
 * @property int $student_id
 * @property Plan $plan
 * @property Money $amount_minor
 * @property string $currency
 * @property int $platform_share_bps
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property SubscriptionStatus $status
 * @property CarbonImmutable|null $cancelled_on
 */
final class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'student_id', 'plan', 'amount_minor', 'currency',
        'platform_share_bps', 'starts_on', 'ends_on', 'status', 'cancelled_on',
    ];

    protected function casts(): array
    {
        return [
            'plan' => Plan::class,
            'status' => SubscriptionStatus::class,
            'amount_minor' => MoneyCast::class,
            'platform_share_bps' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'cancelled_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Allocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * The last day revenue may be recognised for.
     *
     * A cancellation truncates the term: nothing accrues past the day the student
     * left, which is what makes a pro-rata refund a no-op against instructor
     * balances rather than a clawback.
     */
    public function recognitionEndsOn(): CarbonImmutable
    {
        if ($this->cancelled_on !== null && $this->cancelled_on->lessThan($this->ends_on)) {
            return $this->cancelled_on;
        }

        return $this->ends_on;
    }

    /** Total days of the term as sold, which is the denominator for proration. */
    public function termDays(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }
}
