<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «الذمم المدينة 1130» و«الذمم الدائنة 2110» اباء لحسابات العملاء والموردين، فيصبحان
 * حسابي مجموعة لا يُرحَّل عليهما مباشرة (كل قيد يذهب لحساب الطرف). الحساب الذي عليه
 * قيود مباشرة يُترك كما هو حتى لا يضيع رصيده.
 */
return new class extends Migration
{
    public function up(): void
    {
        $accounts = config('accounting.table_prefix').'accounts';
        $lines = config('accounting.table_prefix').'journal_lines';

        if (! Schema::hasTable($accounts) || ! Schema::hasTable($lines)) {
            return;
        }

        DB::table($accounts)
            ->whereIn('code', ['1130', '2110'])
            ->where('is_postable', true)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from($lines)->whereColumn("$lines.account_id", "$accounts.id"))
            ->update(['is_postable' => false]);
    }

    public function down(): void
    {
        //
    }
};
