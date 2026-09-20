<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EngagementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $student_id
 * @property int $course_id
 * @property int $instructor_id
 * @property string $accrual_period
 * @property int $watched_seconds
 * @property int $sessions_count
 * @property CarbonImmutable|null $last_engaged_at
 */
final class Engagement extends Model
{
    /** @use HasFactory<EngagementFactory> */
    use HasFactory;

    protected $fillable = [
        'student_id', 'course_id', 'instructor_id',
        'accrual_period', 'watched_seconds', 'sessions_count', 'last_engaged_at',
    ];

    protected function casts(): array
    {
        return [
            'watched_seconds' => 'integer',
            'sessions_count' => 'integer',
            'last_engaged_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Instructor, $this> */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }
}
