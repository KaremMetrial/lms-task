<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();

            $table->enum('plan', ['monthly', 'quarterly', 'annual']);

            // Money is always BIGINT minor units. Never DECIMAL, never float.
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('EGP');

            // The platform's cut AS IT WAS AT PURCHASE TIME, in basis points.
            //
            // Allocation reads this column, never config. If the platform changes
            // its cut next quarter, that must not silently rewrite what
            // instructors earned last year — and a config read at allocation time
            // would do exactly that, invisibly.
            $table->unsignedSmallInteger('platform_share_bps');

            $table->date('starts_on');
            $table->date('ends_on');

            $table->enum('status', ['active', 'cancelled', 'expired'])->default('active');

            // Set when a student leaves mid-term. Recognition stops here; the
            // unearned remainder is refunded pro rata and never accrues.
            $table->date('cancelled_on')->nullable();

            $table->timestamps();

            // Accrual sweeps the term range for a given period.
            $table->index(['status', 'starts_on', 'ends_on']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
