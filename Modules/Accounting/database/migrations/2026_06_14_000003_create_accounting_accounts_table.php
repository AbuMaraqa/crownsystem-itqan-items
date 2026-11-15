<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\NormalBalance;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('accounting.table_prefix') . 'accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique('acct_accounts_code_unique');
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained(config('accounting.table_prefix') . 'accounts')
                ->nullOnDelete();
            $table->unsignedTinyInteger('type')->default(AccountType::ASSET->value());
            $table->unsignedTinyInteger('normal_balance')->default(NormalBalance::DEBIT->value());
            $table->unsignedSmallInteger('level')->default(1);
            $table->string('path')->nullable()->index('acct_accounts_path_idx');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_postable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['parent_id', 'is_active'], 'acct_accounts_parent_active_idx');
            $table->index(['type', 'is_postable'], 'acct_accounts_type_postable_idx');
            $table->index(['type', 'normal_balance', 'is_postable', 'is_active'], 'acct_accounts_fast_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix') . 'accounts');
    }
};
