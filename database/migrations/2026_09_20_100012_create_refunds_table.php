<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();

            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('EGP');

            // prorata : the normal path — the student gets back the unconsumed
            //           portion of the term. Because revenue is recognised
            //           ratably, that portion was never earned, so no instructor
            //           balance is touched at all.
            //
            // full    : the exception — fraud, chargeback. Already-earned money is
            //           recovered, which writes clawback ledger entries and can
            //           drive a balance negative. Netted against future earnings,
            //           never collected by demanding money back.
            $table->enum('kind', ['prorata', 'full']);

            $table->string('reason');
            $table->timestamp('refunded_at');

            // Refund webhooks get delivered twice. This is what makes that safe.
            $table->string('idempotency_key', 64)->unique();

            $table->timestamps();

            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
