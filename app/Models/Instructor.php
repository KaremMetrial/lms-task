<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InstructorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $payout_account_ref
 * @property string $status
 */
final class Instructor extends Model
{
    /** @use HasFactory<InstructorFactory> */
    use HasFactory;

    protected $fillable = ['name', 'email', 'payout_account_ref', 'status'];

    /** @return HasMany<Course, $this> */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /** @return HasOne<InstructorBalance, $this> */
    public function balance(): HasOne
    {
        return $this->hasOne(InstructorBalance::class);
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** @return HasMany<PayoutItem, $this> */
    public function payoutItems(): HasMany
    {
        return $this->hasMany(PayoutItem::class);
    }

    public function isPayable(): bool
    {
        return $this->status === 'active' && $this->payout_account_ref !== null;
    }
}
