<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allocation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instructor_id')->constrained()->restrictOnDelete();

            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('EGP');

            // The weight this instructor carried, and the total it was measured
            // against. Stored for explainability: an instructor disputing a payout
            // can be shown the exact numerator and denominator behind their share,
            // whichever strategy was in force.
            $table->unsignedBigInteger('weight')->default(1);
            $table->unsignedBigInteger('weight_total')->default(1);

            $table->timestamps();

            $table->unique(['allocation_id', 'instructor_id'], 'allocation_lines_unique_instructor');
            $table->index('instructor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allocation_lines');
    }
};
