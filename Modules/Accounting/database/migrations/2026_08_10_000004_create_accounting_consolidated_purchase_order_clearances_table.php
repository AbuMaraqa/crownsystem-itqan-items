<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'consolidated_purchase_order_clearances';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('consolidated_purchase_order_id');
            $table->foreign('consolidated_purchase_order_id', 'acct_cpo_clearances_order_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'consolidated_purchase_orders')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('clearance_company_id')->nullable();
            $table->foreign('clearance_company_id', 'acct_cpo_clearances_company_fk')->references('id')->on('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by', 'acct_cpo_clearances_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['consolidated_purchase_order_id', 'clearance_company_id'], 'acct_cpo_clearances_order_company_idx');
        });

        $itemsTableName = config('accounting.table_prefix').'consolidated_purchase_order_clearance_attachments';

        Schema::create($itemsTableName, function (Blueprint $table) use ($tableName) {
            $table->id();
            $table->foreignId('clearance_id');
            $table->foreign('clearance_id', 'acct_cpo_clearance_attachments_clearance_fk')
                ->references('id')
                ->on($tableName)
                ->cascadeOnDelete();
            $table->unsignedBigInteger('attachment_type_id')->nullable();
            $table->foreign('attachment_type_id', 'acct_cpo_clearance_attachments_type_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'clearance_attachment_types')
                ->nullOnDelete();
            $table->string('copy_path')->nullable();
            $table->string('original_path')->nullable();
            $table->timestamps();

            $table->index(['clearance_id', 'attachment_type_id'], 'acct_cpo_clearance_attachments_clearance_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'consolidated_purchase_order_clearance_attachments');
        Schema::dropIfExists(config('accounting.table_prefix').'consolidated_purchase_order_clearances');
    }
};
