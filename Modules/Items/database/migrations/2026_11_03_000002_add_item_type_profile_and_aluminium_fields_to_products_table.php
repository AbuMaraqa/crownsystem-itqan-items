<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نوع الصنف (ItemTypeProfile)، وحقول مقطع الالمنيوم: عدد الرابطة، السماكة
 * (رفيع او خميل)، مفتوح او مغلق، والمساحة.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('items.table_prefix');

        Schema::table($prefix.'products', function (Blueprint $table) use ($prefix) {
            $table->foreignId('item_type_profile_id')
                ->nullable()
                ->after('item_type')
                ->constrained($prefix.'item_type_profiles', 'id', 'items_products_item_type_profile_id_foreign')
                ->nullOnDelete();
            $table->unsignedInteger('bundle_count')->nullable()->after('length');
            $table->string('profile_thickness', 10)->nullable()->after('bundle_count');
            $table->boolean('is_open_profile')->nullable()->after('profile_thickness');
            $table->decimal('area', 12, 4)->nullable()->after('is_open_profile');
        });
    }

    public function down(): void
    {
        Schema::table(config('items.table_prefix').'products', function (Blueprint $table) {
            $table->dropForeign('items_products_item_type_profile_id_foreign');
            $table->dropColumn(['item_type_profile_id', 'bundle_count', 'profile_thickness', 'is_open_profile', 'area']);
        });
    }
};
