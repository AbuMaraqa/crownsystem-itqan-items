<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * نوع الصنف كمصفوفة خصائص يعرّفها المستخدم: عدد منازل الكمية والسعر، وهل له
 * لون ومقاس وملاحظات وتاريخ وكمية مجانية وسيريال واصناف فرعية وابعاد.
 * هذه المرحلة تخزّن التعريف فقط، ولا تطبقه على سطور المستندات.
 *
 * يُزرع هنا النوع الافتراضي "مخزني" بمنزلتين للكمية والسعر.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('items.table_prefix');

        Schema::create($prefix.'item_type_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            // فارغ: الحقل مخفي في سطر المستند.
            $table->unsignedTinyInteger('quantity_decimals')->nullable()->default(2);
            $table->unsignedTinyInteger('price_decimals')->nullable()->default(2);
            $table->boolean('has_color')->default(false);
            $table->boolean('has_size')->default(false);
            $table->unsignedTinyInteger('notes_count')->default(0);
            $table->string('date_mode', 20)->default('none');
            $table->string('bonus_mode', 20)->default('none');
            $table->string('serial_mode', 30)->default('none');
            $table->string('sub_item_mode', 20)->default('none');
            $table->unsignedTinyInteger('dimensions')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create($prefix.'item_type_profile_translations', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->string('locale')->index();
            $table->string('name');
            // الاسم التلقائي للقيد اطول من حد MySQL (64 حرفاً).
            $table->foreignId('item_type_profile_id')
                ->constrained($prefix.'item_type_profiles', 'id', 'items_itp_translations_profile_foreign')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['item_type_profile_id', 'locale'], 'items_itp_translations_locale_unique');
        });

        $now = now();

        $profileId = DB::table($prefix.'item_type_profiles')->insertGetId([
            'code' => 'a_store',
            'quantity_decimals' => 2,
            'price_decimals' => 2,
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table($prefix.'item_type_profile_translations')->insert([
            ['item_type_profile_id' => $profileId, 'locale' => 'ar', 'name' => 'مخزني', 'created_at' => $now, 'updated_at' => $now],
            ['item_type_profile_id' => $profileId, 'locale' => 'en', 'name' => 'Store item', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        $prefix = config('items.table_prefix');

        Schema::dropIfExists($prefix.'item_type_profile_translations');
        Schema::dropIfExists($prefix.'item_type_profiles');
    }
};
