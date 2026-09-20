<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained()->restrictOnDelete();

            $table->enum('type', [
                'earning',    // + an allocation line was recognised
                'payout',     // - money successfully left for the instructor
                'reversal',   // - an earning was undone before being paid
                'clawback',   // - already-paid money is being recovered
                'adjustment', // +/- manual correction, always with a reason
            ]);

            // SIGNED. Positive increases what the instructor is owed, negative
            // decreases it. A balance is therefore literally SUM(amount_minor),
            // which means it is always recomputable from zero.
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('EGP');

            // What caused this entry: an allocation_line, a payout_item, a refund.
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');

            // Business time, which is NOT created_at. A September earning written
            // during the October run occurred in September.
            $table->timestamp('occurred_at');

            $table->string('reason')->nullable();

            // Append-only: created_at only. There is deliberately no updated_at,
            // because nothing here is ever updated.
            $table->timestamp('created_at')->useCurrent();

            // ───────────────────────────────────────────────────────────────────
            // THE backbone of the entire idempotency design.
            //
            // A replayed allocation, a retried payout job, a duplicated refund
            // webhook: each attempts a ledger entry whose (type, source) already
            // exists, and MySQL rejects it. This is why "running the payout twice"
            // cannot double-pay even if every other layer is bypassed.
            // ───────────────────────────────────────────────────────────────────
            $table->unique(['type', 'source_type', 'source_id'], 'ledger_entries_unique_source');

            // Balance history for one instructor.
            $table->index(['instructor_id', 'occurred_at'], 'ledger_entries_instructor_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
