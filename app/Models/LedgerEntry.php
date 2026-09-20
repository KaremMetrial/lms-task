<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Ledger\LedgerEntryType;
use App\Domain\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only ledger row.
 *
 * There is no updated_at and no update path. A mistake is corrected by writing an
 * opposing entry, never by editing history — which is the only reason a balance
 * can be recomputed from zero and trusted.
 *
 * @property int $id
 * @property int $instructor_id
 * @property LedgerEntryType $type
 * @property Money $amount_minor
 * @property string $currency
 * @property string $source_type
 * @property int $source_id
 * @property CarbonImmutable $occurred_at
 * @property string|null $reason
 */
final class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'instructor_id', 'type', 'amount_minor', 'currency',
        'source_type', 'source_id', 'occurred_at', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_minor' => MoneyCast::class,
            'source_id' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Instructor, $this> */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }
}
