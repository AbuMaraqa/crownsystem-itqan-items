<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نسبة ربح الزبون: تصنيف زبائن له نسبة ربح يشترك فيها اعضاؤه، ونسبة خاصة على
 * الزبون نفسه تتقدم على نسبة تصنيفه. تُحسب على سعر التكلفة عند تسعير بنود البيع.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'customer_groups';

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->decimal('profit_margin', 8, 4)->default(0);
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->foreign('created_by', 'acct_customer_groups_created_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->softDeletes();
                $table->timestamps();

                $table->index('is_active', 'acct_customer_groups_active_idx');
            });
        }

        Schema::table('users', function (Blueprint $table) use ($tableName) {
            if (! Schema::hasColumn('users', 'customer_group_id')) {
                $table->unsignedBigInteger('customer_group_id')->nullable();
                $table->foreign('customer_group_id', 'users_customer_group_id_fk')->references('id')->on($tableName)->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'profit_margin')) {
                $table->decimal('profit_margin', 8, 4)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'customer_group_id')) {
                $table->dropForeign('users_customer_group_id_fk');
                $table->dropColumn('customer_group_id');
            }

            if (Schema::hasColumn('users', 'profit_margin')) {
                $table->dropColumn('profit_margin');
            }
        });

        Schema::dropIfExists(config('accounting.table_prefix').'customer_groups');
    }
};
