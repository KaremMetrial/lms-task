<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Money\Money;
use App\Domain\Payouts\FailureClass;
use App\Domain\Payouts\PayoutItemStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PayoutItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $payout_batch_id
 * @property int $instructor_id
 * @property Money $amount_minor
 * @property string $currency
 * @property PayoutItemStatus $status
 * @property string $idempotency_key
 * @property string|null $provider_reference
 * @property int $attempts
 * @property FailureClass|null $failure_class
 * @property string|null $last_error
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $settled_at
 * @property CarbonImmutable|null $next_check_at
 */
final class PayoutItem extends Model
{
    /** @use HasFactory<PayoutItemFactory> */
    use HasFactory;

    protected $fillable = [
        'payout_batch_id', 'instructor_id', 'amount_minor', 'currency',
        'status', 'idempotency_key', 'provider_reference', 'attempts',
        'failure_class', 'last_error', 'submitted_at', 'settled_at', 'next_check_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayoutItemStatus::class,
            'failure_class' => FailureClass::class,
            'amount_minor' => MoneyCast::class,
            'attempts' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'next_check_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PayoutBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }

    /** @return BelongsTo<Instructor, $this> */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    /**
     * Derived, never random.
     *
     * A fresh key per attempt would hand the provider a different identity on
     * every retry, which is precisely how a retry becomes a second payment. This
     * key is a pure function of (batch, instructor), so a replay is byte-identical
     * and the provider's own dedup acts as a second safety net.
     */
    public static function idempotencyKeyFor(int $batchId, int $instructorId): string
    {
        return hash('sha256', "payout:v1:{$batchId}:{$instructorId}");
    }
}
