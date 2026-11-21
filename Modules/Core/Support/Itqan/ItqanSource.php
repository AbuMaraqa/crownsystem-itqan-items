<?php

declare(strict_types=1);

namespace Modules\Core\Support\Itqan;

use Generator;
use RuntimeException;

/**
 * مصدر بيانات إتقان: مجلد قاعدة almamoun2015 بملفات MyISAM. للقراءة فقط، ولا يكتب في المجلد ابداً.
 */
final class ItqanSource
{
    /** @var array<string, MyIsamTable> */
    private array $tables = [];

    public function __construct(private readonly string $path)
    {
        if (! is_dir($path)) {
            throw new RuntimeException("Itqan data folder not found: {$path}");
        }
    }

    public static function fromConfig(?string $path = null): self
    {
        // المكان الافتراضي داخل storage، وهو خارج المجلد العام فلا تُنزَّل البيانات من المتصفح.
        $default = storage_path('app'.DIRECTORY_SEPARATOR.'itqan'.DIRECTORY_SEPARATOR.'almamoun2015');
        $path = $path ?: config('core.itqan.data_path') ?: (is_dir($default) ? $default : null);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException(
                "Itqan data folder not set. Upload the almamoun2015 folder to {$default}, "
                .'or set ITQAN_DATA_PATH in .env (then run php artisan config:clear), or pass --path.'
            );
        }

        return new self($path);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function table(string $name): MyIsamTable
    {
        return $this->tables[$name] ??= MyIsamTable::open($this->path, $name);
    }

    /**
     * @param  array<int, string>|null  $columns
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(string $table, ?array $columns = null): Generator
    {
        return $this->table($table)->rows($columns);
    }

    public function count(string $table): int
    {
        return $this->table($table)->count();
    }
}
