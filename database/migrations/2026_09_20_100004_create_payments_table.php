<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();

            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('EGP');
            $table->timestamp('paid_at');

            // Inbound idempotency. A payment webhook delivered twice must create
            // one payment, and MySQL is what guarantees that — not a prior SELECT.
            $table->string('idempotency_key', 64)->unique();

            $table->string('external_reference')->nullable();
            $table->timestamps();

            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
