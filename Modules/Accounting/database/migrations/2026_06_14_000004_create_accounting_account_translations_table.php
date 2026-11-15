<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('accounting.table_prefix').'account_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->constrained(config('accounting.table_prefix').'accounts')
                ->cascadeOnDelete();
            $table->string('locale', 5)->index('acct_acc_trans_locale_idx');
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['account_id', 'locale'], 'acct_acc_trans_account_locale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'account_translations');
    }
};
