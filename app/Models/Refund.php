<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Money\Money;
use App\Domain\Refunds\RefundKind;
use Carbon\CarbonImmutable;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $subscription_id
 * @property int $payment_id
 * @property Money $amount_minor
 * @property string $currency
 * @property RefundKind $kind
 * @property string $reason
 * @property CarbonImmutable $refunded_at
 * @property string $idempotency_key
 */
final class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    protected $fillable = [
        'subscription_id', 'payment_id', 'amount_minor', 'currency',
        'kind', 'reason', 'refunded_at', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'kind' => RefundKind::class,
            'amount_minor' => MoneyCast::class,
            'refunded_at' => 'immutable_datetime',
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
}
