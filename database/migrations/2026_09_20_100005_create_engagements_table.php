<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engagements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();

            // Denormalised from courses.instructor_id.
            //
            // Deliberate: allocation groups tens of millions of engagement rows by
            // instructor for a given (student, period). Joining to courses on every
            // allocation would dominate the query cost.
            //
            // The trade-off: if a course is reassigned to another instructor, past
            // engagement rows keep the instructor who actually taught it. For a
            // revenue ledger that is the correct behaviour, not a sync bug — money
            // already earned should not move.
            $table->foreignId('instructor_id')->constrained()->restrictOnDelete();

            // Accrual bucket, 'YYYY-MM'. CHAR(7) sorts and compares lexicographically,
            // which is all the ordering we need, and keeps the index narrow.
            $table->char('accrual_period', 7);

            // Consumed by WeightedByWatchTime. Stored even when the equal-weight
            // strategy is active, so switching strategies needs no backfill.
            $table->unsignedBigInteger('watched_seconds')->default(0);
            $table->unsignedInteger('sessions_count')->default(0);

            $table->timestamp('last_engaged_at')->nullable();
            $table->timestamps();

            // One row per student per course per period; repeated views accumulate
            // into watched_seconds rather than inserting duplicates.
            $table->unique(['student_id', 'course_id', 'accrual_period'], 'engagements_unique_per_period');

            // The allocation query: "who did this student engage with this month?"
            $table->index(['student_id', 'accrual_period'], 'engagements_student_period_idx');
            $table->index(['accrual_period', 'instructor_id'], 'engagements_period_instructor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagements');
    }
};
