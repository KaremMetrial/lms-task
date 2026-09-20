<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();

            $table->char('accrual_period', 7);
            $table->date('period_starts_on');
            $table->date('period_ends_on');

            // Days of the term that fall inside this calendar month. Drives the
            // straight-line proration, and is stored so a historical allocation
            // can be re-derived without recomputing calendars.
            $table->unsignedSmallInteger('days_in_period');

            // gross = platform + instructor_pool, exactly. Asserted in tests.
            $table->bigInteger('gross_minor');
            $table->bigInteger('platform_minor');
            $table->bigInteger('instructor_pool_minor');
            $table->char('currency', 3)->default('EGP');

            // Which allocation strategy produced the lines below.
            //
            // Recorded per allocation, not read from config at display time: after
            // the platform switches from equal-weight to watch-time, every past
            // split must still be explainable by the rule that actually made it.
            $table->string('strategy', 40);

            // voided when a refund removes an unearned period.
            $table->enum('status', ['allocated', 'voided'])->default('allocated');
            $table->timestamp('allocated_at');
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            // THE accrual idempotency guard.
            //
            // Re-running allocation for September cannot create a second September
            // row for the same subscription. Not "the code checks first" — MySQL
            // makes it impossible, including for two workers racing.
            $table->unique(['subscription_id', 'accrual_period'], 'allocations_unique_per_period');

            $table->index(['accrual_period', 'status'], 'allocations_period_status_idx');
            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allocations');
    }
};
