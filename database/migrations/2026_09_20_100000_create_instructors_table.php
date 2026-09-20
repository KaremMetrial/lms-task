<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();

            // Opaque handle at the payment provider. Nullable because an
            // instructor can exist and earn before they have been onboarded for
            // payouts — earnings accrue, payouts wait.
            $table->string('payout_account_ref')->nullable();

            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->timestamps();

            // Payout runs select payable instructors by status.
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructors');
    }
};
