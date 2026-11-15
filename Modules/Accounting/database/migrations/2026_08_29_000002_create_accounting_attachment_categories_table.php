<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تصنيفات المرفقات تُدار من اعدادات النظام، ليختار المستخدم منها عند اضافة
     * مرفق الى طلبية شراء تجميعية بدل قائمة ثابتة في الشيفرة.
     */
    public function up(): void
    {
        $categories = config('accounting.table_prefix').'attachment_categories';

        if (! Schema::hasTable($categories)) {
            Schema::create($categories, function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->foreign('created_by', 'acct_attachment_categories_created_by_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        $attachments = config('accounting.table_prefix').'consolidated_purchase_order_attachments';

        if (Schema::hasTable($attachments) && ! Schema::hasColumn($attachments, 'attachment_category_id')) {
            Schema::table($attachments, function (Blueprint $table) use ($categories) {
                $table->unsignedBigInteger('attachment_category_id')->nullable()->after('consolidated_purchase_order_id');
                $table->foreign('attachment_category_id', 'acct_cpo_attachments_category_fk')
                    ->references('id')
                    ->on($categories)
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $attachments = config('accounting.table_prefix').'consolidated_purchase_order_attachments';

        if (Schema::hasColumn($attachments, 'attachment_category_id')) {
            Schema::table($attachments, function (Blueprint $table) {
                $table->dropForeign('acct_cpo_attachments_category_fk');
                $table->dropColumn('attachment_category_id');
            });
        }

        Schema::dropIfExists(config('accounting.table_prefix').'attachment_categories');
    }
};
