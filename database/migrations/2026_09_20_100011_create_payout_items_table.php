<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('instructor_id')->constrained()->restrictOnDelete();

            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('EGP');

            // ───────────────────────────────────────────────────────────────────
            // The state machine. Transitions happen ONLY via conditional UPDATE:
            //
            //   UPDATE payout_items SET status='submitted'
            //    WHERE id=? AND status='pending'
            //
            // affectedRows === 0 means another worker already moved it, and this
            // worker returns without acting. Compare-and-swap in the database:
            // no lock, no coordination, correct under any number of workers.
            //
            //   pending ──► submitted ──► succeeded   (terminal)
            //                   │   └───► failed      (terminal)
            //                   └───────► unknown ──► succeeded | failed
            //
            // 'unknown' is the crucial state: a timeout is NOT a failure. It means
            // the provider may already have moved the money. It is resolved only
            // by asking the provider, never by guessing and never by re-sending.
            // ───────────────────────────────────────────────────────────────────
            $table->enum('status', [
                'pending',
                'submitted',
                'unknown',
                'succeeded',
                'failed',
            ])->default('pending');

            // Idempotency layer 3: sha256('payout:v1:{batch_id}:{instructor_id}').
            //
            // DETERMINISTIC, not random. A fresh ULID per attempt would hand the
            // provider a different key on every retry, which is precisely how you
            // double-pay. A derived key makes a replay byte-identical and lets the
            // provider's own dedup act as a second net.
            $table->char('idempotency_key', 64)->unique();

            $table->string('provider_reference')->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);

            // Why it failed, as a class rather than a string, so retry policy is a
            // decision on typed data instead of on error-message matching.
            $table->enum('failure_class', ['permanent', 'transient', 'unknown'])->nullable();
            $table->text('last_error')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamps();

            // Idempotency layer 2: fan-out is replayable. Re-running it produces
            // zero new rows.
            $table->unique(['payout_batch_id', 'instructor_id'], 'payout_items_unique_per_batch');

            // The reconciler's query: find items stuck in submitted/unknown that
            // are due for a status check.
            $table->index(['status', 'next_check_at'], 'payout_items_sweep_idx');
            $table->index(['payout_batch_id', 'status'], 'payout_items_batch_status_idx');
            $table->index(['instructor_id', 'status'], 'payout_items_instructor_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_items');
    }
};
