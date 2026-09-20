<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The mock provider's OWN books — deliberately a table, not in-memory state.
     *
     * Two reasons, and both are the point of the exercise:
     *
     *   1. The worker that sends a payout and the reconciler that later asks about
     *      it are DIFFERENT PROCESSES, often different containers. An in-memory
     *      mock could never reproduce "you timed out, but the money did move",
     *      because the truth would die with the process. Here it survives, so the
     *      status check discovers a real fact rather than a fixture.
     *
     *   2. The unique index on idempotency_key below IS provider-side dedup. Resend
     *      the same key and the provider returns its stored result instead of moving
     *      money again. That is what a deterministic key buys us, and it is visible
     *      here rather than asserted in a comment.
     *
     * This table is the fake external system. Nothing in the ledger reads it.
     */
    public function up(): void
    {
        Schema::create('mock_provider_transactions', function (Blueprint $table) {
            $table->id();

            // The provider's own dedup key — the same value we send.
            $table->char('idempotency_key', 64)->unique();

            $table->string('account_ref');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            // What the provider actually did. A row exists ONLY if the provider
            // reached a decision — which is why "no row" legitimately means
            // "never received", and is safe to retry.
            $table->enum('outcome', ['succeeded', 'failed']);

            $table->string('provider_reference')->nullable();
            $table->string('failure_reason')->nullable();

            // Set when the provider decided but the caller never heard back. Purely
            // for making the demo legible: it marks the rows where our side is
            // uncertain while the provider is not.
            $table->boolean('response_lost')->default(false);

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_provider_transactions');
    }
};
