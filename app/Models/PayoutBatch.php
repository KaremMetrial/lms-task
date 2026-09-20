<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Money\Money;
use App\Domain\Payouts\PayoutBatchStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PayoutBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $period_key
 * @property PayoutBatchStatus $status
 * @property string $currency
 * @property Money $minimum_payout_minor
 * @property int $items_count
 * @property Money $total_minor
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 */
final class PayoutBatch extends Model
{
    /** @use HasFactory<PayoutBatchFactory> */
    use HasFactory;

    protected $fillable = [
        'period_key', 'status', 'currency', 'minimum_payout_minor',
        'items_count', 'total_minor', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayoutBatchStatus::class,
            'minimum_payout_minor' => MoneyCast::class,
            'total_minor' => MoneyCast::class,
            'items_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<PayoutItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PayoutItem::class);
    }
}
