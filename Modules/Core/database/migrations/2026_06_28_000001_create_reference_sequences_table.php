<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('sequence_code', 100);
            $table->string('prefix', 30)->nullable();
            $table->string('period_key', 20)->default('');
            $table->string('scope_key', 100)->default('');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->unsignedTinyInteger('padding')->default(5);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['sequence_code', 'period_key', 'scope_key'], 'reference_sequences_unique');
            $table->index(['sequence_code', 'period_key'], 'reference_sequences_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_sequences');
    }
};
