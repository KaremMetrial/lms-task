<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_batches', function (Blueprint $table) {
            $table->id();

            // ───────────────────────────────────────────────────────────────────
            // Idempotency layer 1.
            //
            // Two servers starting the September run in the same instant: one
            // INSERT wins, the other gets a duplicate-key error and attaches to
            // the existing batch. There is no window in which two September
            // batches exist, so there is nothing to reconcile later.
            // ───────────────────────────────────────────────────────────────────
            $table->char('period_key', 7)->unique();

            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending');

            $table->char('currency', 3)->default('EGP');

            // Snapshotted, not read from config at settle time — same reasoning as
            // subscriptions.platform_share_bps.
            $table->bigInteger('minimum_payout_minor');

            $table->unsignedInteger('items_count')->default(0);
            $table->bigInteger('total_minor')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_batches');
    }
};
