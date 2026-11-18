<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ربط سجلات النظام القديم (إتقان) بسجلاتها في CrownSystem. به يُعاد النقل دون تكرار:
 * يُبحث بالمفتاح القديم، فيُحدَّث السجل الموجود بدل انشاء نسخة ثانية.
 * المفتاح القديم نص لأن معرّفات إتقان نصوص بأصفار بادئة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('legacy_links')) {
            return;
        }

        Schema::create('legacy_links', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30);
            $table->string('source_table', 64);
            $table->string('source_key', 191);
            $table->string('target_type', 191);
            $table->unsignedBigInteger('target_id');
            $table->string('checksum', 64)->nullable();
            $table->timestamps();

            $table->unique(['source', 'source_table', 'source_key', 'target_type'], 'legacy_links_source_unique');
            $table->index(['target_type', 'target_id'], 'legacy_links_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_links');
    }
};
