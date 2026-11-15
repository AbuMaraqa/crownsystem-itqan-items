<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('accounting.table_prefix').'account_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('source_module')->index('acct_map_source_module_idx');
            $table->string('source_key')->index('acct_map_source_key_idx');
            $table->foreignId('account_id')
                ->nullable()
                ->constrained(config('accounting.table_prefix').'accounts')
                ->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['source_module', 'source_key'], 'acct_map_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'account_mappings');
    }
};
