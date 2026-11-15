<?php

namespace Modules\Core\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * المصدر الوحيد لتعابير SQL التي تختلف بين MySQL وPostgreSQL وSQLite.
 *
 * النظام يعمل على MySQL محلياً وعلى PostgreSQL في بيئات أخرى، والاختبارات قد
 * تعمل على SQLite؛ لذا لا يُكتب تعبير خاص بمحرك واحد داخل الاستعلامات مباشرة،
 * بل يُطلب من هنا.
 */
class SqlDialect
{
    /**
     * تعبير يحوّل عمود تاريخ إلى نص "YYYY-MM".
     */
    public static function yearMonth(string $column, ?Connection $connection = null): string
    {
        return match (self::driver($connection)) {
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            'sqlite' => "strftime('%Y-%m', {$column})",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    /**
     * شرط مطابقة تعبير نمطي مع ربط واحد (?) للنمط.
     */
    public static function regexp(string $column, ?Connection $connection = null): string
    {
        return match (self::driver($connection)) {
            'pgsql' => "{$column} ~ ?",
            default => "{$column} REGEXP ?",
        };
    }

    /**
     * تحويل نص أرقام إلى عدد صحيح للمقارنة أو MAX.
     */
    public static function castInteger(string $column, ?Connection $connection = null): string
    {
        return match (self::driver($connection)) {
            'pgsql' => "CAST({$column} AS BIGINT)",
            'sqlite' => "CAST({$column} AS INTEGER)",
            default => "CAST({$column} AS UNSIGNED)",
        };
    }

    /**
     * عدد أحرف النص (لا البايتات).
     */
    public static function charLength(string $column, ?Connection $connection = null): string
    {
        return match (self::driver($connection)) {
            'sqlite' => "LENGTH({$column})",
            default => "CHAR_LENGTH({$column})",
        };
    }

    public static function isMysql(?Connection $connection = null): bool
    {
        return in_array(self::driver($connection), ['mysql', 'mariadb'], true);
    }

    private static function driver(?Connection $connection): string
    {
        return ($connection ?? DB::connection())->getDriverName();
    }
}
