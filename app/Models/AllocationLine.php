<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Money\Money;
use Database\Factories\AllocationLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $allocation_id
 * @property int $instructor_id
 * @property Money $amount_minor
 * @property string $currency
 * @property int $weight
 * @property int $weight_total
 */
final class AllocationLine extends Model
{
    /** @use HasFactory<AllocationLineFactory> */
    use HasFactory;

    protected $fillable = [
        'allocation_id', 'instructor_id', 'amount_minor', 'currency',
        'weight', 'weight_total',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => MoneyCast::class,
            'weight' => 'integer',
            'weight_total' => 'integer',
        ];
    }

    /** @return BelongsTo<Allocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class);
    }

    /** @return BelongsTo<Instructor, $this> */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }
}
