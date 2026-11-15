<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(config('items.table_prefix') . 'manufacturer_translations', function (Blueprint $table) {
            $table->id();

            $table->string('locale')->index();
            $table->string('name');
            $table->string('description')->nullable();
            $table->foreignId('manufacturer_id')->index('manufacturer_id')->constrained(config('items.table_prefix') . 'manufacturers')->onDelete('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('items.table_prefix') . 'manufacturer_translations');
    }
};
