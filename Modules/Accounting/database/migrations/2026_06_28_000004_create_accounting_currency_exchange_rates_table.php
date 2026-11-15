<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('accounting.table_prefix').'currency_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('base_currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->date('rate_date');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->boolean('is_active')->default(true)->index('acct_currency_rates_active_idx');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['base_currency_id', 'currency_id', 'rate_date'], 'acct_currency_rates_unique');
            $table->index(['currency_id', 'rate_date'], 'acct_currency_rates_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'currency_exchange_rates');
    }
};
