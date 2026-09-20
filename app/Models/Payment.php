<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $subscription_id
 * @property Money $amount_minor
 * @property string $currency
 * @property CarbonImmutable $paid_at
 * @property string $idempotency_key
 * @property string|null $external_reference
 */
final class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'subscription_id', 'amount_minor', 'currency',
        'paid_at', 'idempotency_key', 'external_reference',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => MoneyCast::class,
            'paid_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return HasMany<Allocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }
}
