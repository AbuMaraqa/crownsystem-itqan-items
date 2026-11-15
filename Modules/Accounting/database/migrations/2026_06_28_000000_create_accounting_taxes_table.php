<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('accounting.table_prefix').'taxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable()->index('acct_taxes_code_idx');
            $table->decimal('rate', 8, 4)->default(0);
            $table->boolean('is_active')->default(true)->index('acct_taxes_active_idx');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'taxes');
    }
};
