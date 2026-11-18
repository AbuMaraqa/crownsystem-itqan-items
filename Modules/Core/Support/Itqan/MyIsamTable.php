<?php

declare(strict_types=1);

namespace Modules\Core\Support\Itqan;

use Generator;
use RuntimeException;

/**
 * قارئ جدول MyISAM من ملفاته مباشرة (.frm و.MYI و.MYD)، للقراءة فقط.
 * قاعدة إتقان ملفات MySQL قديمة لا يقرؤها MySQL 8، فنقرأ الصيغة نفسها:
 * الصفوف الثابتة الطول والصفوف المضغوطة الديناميكية (packed). مطابق لأداة
 * tools/myisam.js في توثيق إتقان، وقد طابقت عدد الصفوف في كل الجداول.
 */
final class MyIsamTable
{
    private const T_DECIMAL = 0;
    private const T_TINY = 1;
    private const T_SHORT = 2;
    private const T_LONG = 3;
    private const T_FLOAT = 4;
    private const T_DOUBLE = 5;
    private const T_TIMESTAMP = 7;
    private const T_LONGLONG = 8;
    private const T_INT24 = 9;
    private const T_DATETIME = 12;
    private const T_NEWDATE = 14;
    private const T_VARCHAR = 15;
    private const T_TIMESTAMP2 = 17;
    private const T_DATETIME2 = 18;
    private const T_NEWDECIMAL = 246;
    private const T_ENUM = 247;
    private const T_SET = 248;
    private const T_VAR_STRING = 253;
    private const T_STRING = 254;

    /** ترميزات latin1 في MySQL القديم. */
    private const LATIN_CHARSETS = [8, 5, 15, 31, 47, 48, 49, 94];

    private const BINARY_CHARSET = 63;

    /**
     * @param  array<int, array<string, mixed>>  $columns  مرتبة بموضعها في الصف
     * @param  array<int, string>  $columnOrder  أسماء الأعمدة بترتيب تعريف الجدول
     */
    private function __construct(
        private readonly string $dataFile,
        private readonly array $columns,
        private readonly array $columnOrder,
        private readonly int $options,
        private readonly int $recordLength,
        private readonly int $nullBytes,
        private readonly int $records,
    ) {}

    public static function open(string $directory, string $table): self
    {
        $base = rtrim($directory, '\\/').DIRECTORY_SEPARATOR.$table;

        foreach (['.frm', '.MYI', '.MYD'] as $extension) {
            if (! is_file($base.$extension)) {
                throw new RuntimeException("Itqan table file not found: {$base}{$extension}");
            }
        }

        [$columns, $order, $options, $recordLength, $nullBytes] = self::readFrm($base.'.frm');

        return new self($base.'.MYD', $columns, $order, $options, $recordLength, $nullBytes, self::recordCount($base.'.MYI'));
    }

    /** عدد الصفوف الحية كما يسجله الفهرس. */
    public function count(): int
    {
        return $this->records;
    }

    /** @return array<int, string> */
    public function columnNames(): array
    {
        return $this->columnOrder;
    }

    /**
     * الصفوف الحية، كل صف مصفوفة اسم العمود => القيمة.
     *
     * @param  array<int, string>|null  $only  الأعمدة المطلوبة، او كلها
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(?array $only = null): Generator
    {
        $wanted = $only === null ? null : array_flip($only);

        if ($this->records === 0) {
            return;
        }

        $data = file_get_contents($this->dataFile);
        if ($data === false) {
            throw new RuntimeException("Unable to read {$this->dataFile}");
        }

        $length = strlen($data);

        if ($length === 0) {
            return;
        }

        if (! ($this->options & 1)) {
            for ($p = 0; $p + $this->recordLength <= $length; $p += $this->recordLength) {
                if (ord($data[$p]) === 0) {
                    continue;
                }

                yield $this->fixedRow($data, $p, $wanted);
            }

            return;
        }

        $p = 0;
        $emitted = 0;

        // نتوقف عند عدد الصفوف المسجل: بعض الملفات فيها بايتات زائدة بعد آخر صف حي.
        while ($p < $length && $emitted < $this->records) {
            $block = self::block($data, $p);

            if ($block['deleted']) {
                $p += $block['block'];

                continue;
            }

            if ($block['first']) {
                $raw = substr($data, $block['start'], $block['data']);
                $next = $block;

                while (! $next['last']) {
                    $next = self::block($data, $next['next']);
                    $raw .= substr($data, $next['start'], $next['data']);
                }

                yield $this->packedRow($raw, $wanted);
                $emitted++;
            }

            $p = $block['start'] + $block['block'];
        }
    }

    /**
     * @param  array<string, int>|null  $wanted
     * @return array<string, mixed>
     */
    private function fixedRow(string $data, int $p, ?array $wanted): array
    {
        $row = [];

        foreach ($this->columns as $column) {
            if ($wanted !== null && ! isset($wanted[$column['name']])) {
                continue;
            }

            if ($column['nullable'] && (ord($data[$p + $column['nullByte']]) & $column['nullMask'])) {
                $row[$column['name']] = null;

                continue;
            }

            if ($column['type'] === self::T_VARCHAR) {
                $prefix = $column['len'] - 1 > 255 ? 2 : 1;
                $at = $p + $column['recpos'];
                $size = $prefix === 1 ? ord($data[$at]) : self::u16le($data, $at);
                $row[$column['name']] = $this->text($column, substr($data, $at + $prefix, $size));

                continue;
            }

            $value = $this->decode($column, substr($data, $p + $column['recpos'], $column['len']));
            $row[$column['name']] = is_string($value) ? rtrim($value, ' ') : $value;
        }

        return $row;
    }

    /**
     * صف مضغوط: بايتات علامات الضغط، ثم بايتات NULL، ثم الأعمدة.
     *
     * @param  array<string, int>|null  $wanted
     * @return array<string, mixed>
     */
    private function packedRow(string $raw, ?array $wanted): array
    {
        $types = array_map(fn (array $column): string => self::storageType($column), $this->columns);
        $packBits = count(array_filter($types, fn (string $type): bool => in_array($type, ['blob', 'zero', 'endspace'], true)));

        $from = $packBits ? ($packBits + 7) >> 3 : 0;
        $packPos = 0;
        $flag = ord($raw[0] ?? "\0");
        $bit = 1;
        $nulls = substr($raw, $from, $this->nullBytes);
        $from += $this->nullBytes;

        $next = function () use (&$bit, &$flag, &$packPos, $raw): void {
            $bit <<= 1;

            if ($bit >= 256) {
                $flag = ord($raw[++$packPos] ?? "\0");
                $bit = 1;
            }
        };

        $row = [];

        foreach ($this->columns as $i => $column) {
            $type = $types[$i];
            $isNull = $column['nullable'] && (ord($nulls[$column['nullByte']] ?? "\0") & $column['nullMask']);

            if ($type === 'varchar') {
                // في الصف المضغوط طول varchar الطويل بايت، او 0xFF ثم بايتان big-endian.
                if ($column['len'] - 1 <= 255 || ord($raw[$from]) !== 0xFF) {
                    $size = ord($raw[$from++]);
                } else {
                    $size = self::u16be($raw, $from + 1);
                    $from += 3;
                }

                $value = substr($raw, $from, $size);
                $from += $size;
            } elseif ($type === 'normal') {
                $value = substr($raw, $from, $column['len']);
                $from += $column['len'];
            } elseif ($flag & $bit) {
                if ($type === 'blob') {
                    $value = '';
                } elseif ($type === 'zero') {
                    $value = str_repeat("\0", $column['len']);
                } else {
                    if ($column['len'] > 255 && (ord($raw[$from]) & 128)) {
                        $size = (ord($raw[$from]) & 127) + (ord($raw[$from + 1]) << 7);
                        $from += 2;
                    } else {
                        $size = ord($raw[$from++]);
                    }

                    $value = str_pad(substr($raw, $from, $size), $column['len'], ' ');
                    $from += $size;
                }

                $next();
            } elseif ($type === 'blob') {
                $sizeBytes = $column['len'] - 8;
                $size = self::uintLe($raw, $from, $sizeBytes);
                $value = substr($raw, $from + $sizeBytes, $size);
                $from += $sizeBytes + $size;
                $next();
            } else {
                $value = substr($raw, $from, $column['len']);
                $from += $column['len'];
                $next();
            }

            if ($wanted !== null && ! isset($wanted[$column['name']])) {
                continue;
            }

            if ($isNull) {
                $row[$column['name']] = null;

                continue;
            }

            $decoded = $this->decode($column, $value);

            if (is_string($decoded) && ($type === 'endspace' || $column['type'] === self::T_STRING)) {
                $decoded = rtrim($decoded, ' ');
            }

            $row[$column['name']] = $decoded;
        }

        if ($from > strlen($raw)) {
            throw new RuntimeException('Itqan row overrun in '.$this->dataFile);
        }

        return $row;
    }

    /** @param array<string, mixed> $column */
    private function decode(array $column, string $b): mixed
    {
        return match ($column['type']) {
            self::T_TINY => ($column['packFlag'] & 1) ? self::signed(ord($b[0]), 8) : ord($b[0]),
            self::T_SHORT => self::signed(self::u16le($b, 0), 16),
            self::T_INT24 => self::signed(self::uintLe($b, 0, 3), 24),
            self::T_LONG => self::signed(self::uintLe($b, 0, 4), 32),
            self::T_LONGLONG => unpack('q', $b)[1],
            self::T_FLOAT => unpack('g', $b)[1],
            self::T_DOUBLE => unpack('e', $b)[1],
            self::T_NEWDATE => self::newDate(self::uintLe($b, 0, 3)),
            self::T_DATETIME => self::dateTime(unpack('P', $b)[1]),
            self::T_DATETIME2 => self::dateTime2($b),
            self::T_TIMESTAMP2 => self::timestamp(unpack('N', $b)[1]),
            self::T_TIMESTAMP => self::timestamp(unpack('V', $b)[1]),
            self::T_NEWDECIMAL => self::decimal(
                $b,
                $column['fieldLength'] - ($column['decimals'] ? 1 : 0) - (($column['packFlag'] & 1) ? 1 : 0),
                $column['decimals'],
            ),
            self::T_ENUM, self::T_SET => $column['len'] === 1 ? ord($b[0]) : self::u16le($b, 0),
            default => $this->text($column, $b),
        };
    }

    /** @param array<string, mixed> $column */
    private function text(array $column, string $bytes): string
    {
        if ($column['charset'] === self::BINARY_CHARSET) {
            return $bytes;
        }

        return in_array($column['charset'], self::LATIN_CHARSETS, true)
            ? mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1')
            : $bytes;
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>, 2: int, 3: int, 4: int}
     */
    private static function readFrm(string $file): array
    {
        $h = file_get_contents($file);

        if ($h === false || strlen($h) < 64 || ord($h[0]) !== 0xFE || ord($h[1]) !== 0x01) {
            throw new RuntimeException("Not a binary .frm file: {$file}");
        }

        $length = self::u16le($h, 4);
        $formPos = self::uintLe($h, 64 + $length, 4);
        $fields = self::u16le($h, $formPos + 258);
        $screens = self::u16le($h, $formPos + 260);
        $namesLength = self::u16le($h, $formPos + 268);
        $nullFields = self::u16le($h, $formPos + 282);
        $options = self::u16le($h, 30);
        $recordLength = self::u16le($h, 16);
        $base = $formPos + 288 + $screens;

        $names = array_slice(array_values(array_filter(
            explode("\xFF", substr($h, $base + $fields * 17, $namesLength)),
            fn (int $index): bool => $index > 0,
            ARRAY_FILTER_USE_KEY,
        )), 0, $fields);

        $columns = [];

        for ($i = 0; $i < $fields; $i++) {
            $p = $base + $i * 17;
            $packFlag = self::u16le($h, $p + 8);
            $columns[] = [
                'name' => $names[$i],
                'fieldLength' => self::u16le($h, $p + 3),
                'recpos' => self::uintLe($h, $p + 5, 3) - 1,
                'packFlag' => $packFlag,
                'type' => ord($h[$p + 13]),
                'charset' => ord($h[$p + 14]) | (ord($h[$p + 11]) << 8),
                'nullable' => (bool) ($packFlag & 32768),
                'decimals' => ($packFlag >> 8) & 31,
                'nullByte' => 0,
                'nullMask' => 0,
            ];
        }

        $bit = ($options & 1) ? 0 : 1;

        foreach ($columns as &$column) {
            if ($column['nullable']) {
                $column['nullByte'] = $bit >> 3;
                $column['nullMask'] = 1 << ($bit & 7);
                $bit++;
            }
        }
        unset($column);

        $nullBytes = ($nullFields + (($options & 1) ? 0 : 1) + 7) >> 3;
        $order = array_column($columns, 'name');

        usort($columns, fn (array $a, array $b): int => $a['recpos'] <=> $b['recpos']);

        foreach ($columns as $i => &$column) {
            $column['len'] = ($columns[$i + 1]['recpos'] ?? $recordLength) - $column['recpos'];
        }
        unset($column);

        return [$columns, $order, $options, $recordLength, $nullBytes];
    }

    private static function recordCount(string $file): int
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read {$file}");
        }

        $header = fread($handle, 96);
        fclose($handle);

        if ($header === false || strlen($header) < 44) {
            throw new RuntimeException("Invalid .MYI header: {$file}");
        }

        return unpack('J', substr($header, 28, 8))[1];
    }

    /** @param array<string, mixed> $column */
    private static function storageType(array $column): string
    {
        $type = $column['type'];

        if ($type >= 249 && $type <= 252) {
            return 'blob';
        }

        if ($type === self::T_VARCHAR) {
            return 'varchar';
        }

        // الأرقام والتواريخ تُحذف حين تكون أصفاراً كلها، والنصوص الثابتة وحدها تُضغط مسافاتها.
        if (! in_array($type, [self::T_STRING, self::T_VAR_STRING, self::T_ENUM, self::T_SET], true)) {
            return 'zero';
        }

        return $column['len'] <= 3 ? 'normal' : 'endspace';
    }

    /**
     * رأس كتلة في ملف ديناميكي، حسب تخطيط _mi_get_block_info.
     *
     * @return array{deleted: bool, first: bool, last: bool, start: int, data: int, block: int, next: int}
     */
    private static function block(string $d, int $p): array
    {
        $u2 = fn (int $o): int => self::u16be($d, $o);
        $u3 = fn (int $o): int => self::uintBe($d, $o, 3);
        $u8 = fn (int $o): int => unpack('J', substr($d, $o, 8))[1];
        $b = fn (int $o): int => ord($d[$o]);
        $make = fn (int $start, int $data, int $block, bool $first, bool $last, int $next = 0): array => [
            'deleted' => false, 'first' => $first, 'last' => $last, 'start' => $start, 'data' => $data, 'block' => $block, 'next' => $next,
        ];

        return match ($type = ord($d[$p])) {
            0 => ['deleted' => true, 'first' => false, 'last' => false, 'start' => $p, 'data' => 0, 'block' => $u3($p + 1), 'next' => 0],
            1 => $make($p + 3, $u2($p + 1), $u2($p + 1), true, true),
            2 => $make($p + 4, $u3($p + 1), $u3($p + 1), true, true),
            3 => $make($p + 4, $u2($p + 1), $u2($p + 1) + $b($p + 3), true, true),
            4 => $make($p + 5, $u3($p + 1), $u3($p + 1) + $b($p + 4), true, true),
            5 => $make($p + 13, $u2($p + 3), $u2($p + 3), true, false, $u8($p + 5)),
            6 => $make($p + 15, $u3($p + 4), $u3($p + 4), true, false, $u8($p + 7)),
            7 => $make($p + 3, $u2($p + 1), $u2($p + 1), false, true),
            8 => $make($p + 4, $u3($p + 1), $u3($p + 1), false, true),
            9 => $make($p + 4, $u2($p + 1), $u2($p + 1) + $b($p + 3), false, true),
            10 => $make($p + 5, $u3($p + 1), $u3($p + 1) + $b($p + 4), false, true),
            11 => $make($p + 11, $u2($p + 1), $u2($p + 1), false, false, $u8($p + 3)),
            12 => $make($p + 12, $u3($p + 1), $u3($p + 1), false, false, $u8($p + 4)),
            13 => $make($p + 16, $u3($p + 5), $u3($p + 5), true, false, $u8($p + 8)),
            default => throw new RuntimeException("Bad MyISAM block type {$type} at {$p}"),
        };
    }

    private static function decimal(string $bytes, int $precision, int $scale): string
    {
        $digits = [0, 1, 1, 2, 2, 3, 3, 4, 4, 4];
        $integerDigits = $precision - $scale;
        $intWords = intdiv($integerDigits, 9);
        $intRest = $integerDigits % 9;
        $fracWords = intdiv($scale, 9);
        $fracRest = $scale % 9;

        $negative = ! (ord($bytes[0]) & 0x80);
        $bytes[0] = chr(ord($bytes[0]) ^ 0x80);

        if ($negative) {
            $bytes = ~$bytes;
        }

        $o = 0;
        $integer = '';

        if ($intRest) {
            $integer .= (string) self::uintBe($bytes, $o, $digits[$intRest]);
            $o += $digits[$intRest];
        }

        for ($i = 0; $i < $intWords; $i++) {
            $word = (string) self::uintBe($bytes, $o, 4);
            $integer .= $integer === '' ? $word : str_pad($word, 9, '0', STR_PAD_LEFT);
            $o += 4;
        }

        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = '';

        for ($i = 0; $i < $fracWords; $i++) {
            $fraction .= str_pad((string) self::uintBe($bytes, $o, 4), 9, '0', STR_PAD_LEFT);
            $o += 4;
        }

        if ($fracRest) {
            $fraction .= str_pad((string) self::uintBe($bytes, $o, $digits[$fracRest]), $fracRest, '0', STR_PAD_LEFT);
        }

        return ($negative ? '-' : '').$integer.($fraction !== '' ? '.'.$fraction : '');
    }

    private static function newDate(int $v): string
    {
        return sprintf('%d-%02d-%02d', $v >> 9, ($v >> 5) & 15, $v & 31);
    }

    private static function dateTime(int $v): string
    {
        $s = str_pad((string) $v, 14, '0', STR_PAD_LEFT);

        return substr($s, 0, 4).'-'.substr($s, 4, 2).'-'.substr($s, 6, 2).' '.substr($s, 8, 2).':'.substr($s, 10, 2).':'.substr($s, 12, 2);
    }

    private static function dateTime2(string $b): string
    {
        $v = self::uintBe($b, 0, 5) - 0x8000000000;
        $ymd = intdiv($v, 2 ** 17);
        $ym = $ymd >> 5;
        $hms = $v % (2 ** 17);

        return sprintf('%d-%02d-%02d %02d:%02d:%02d', intdiv($ym, 13), $ym % 13, $ymd & 31, $hms >> 12, ($hms >> 6) & 63, $hms & 63);
    }

    private static function timestamp(int $seconds): ?string
    {
        return $seconds ? gmdate('Y-m-d H:i:s', $seconds) : null;
    }

    private static function signed(int $value, int $bits): int
    {
        return $value >= (1 << ($bits - 1)) ? $value - (1 << $bits) : $value;
    }

    private static function u16le(string $s, int $o): int
    {
        return unpack('v', substr($s, $o, 2))[1];
    }

    private static function u16be(string $s, int $o): int
    {
        return unpack('n', substr($s, $o, 2))[1];
    }

    private static function uintLe(string $s, int $o, int $bytes): int
    {
        $value = 0;

        for ($i = $bytes - 1; $i >= 0; $i--) {
            $value = ($value << 8) | ord($s[$o + $i]);
        }

        return $value;
    }

    private static function uintBe(string $s, int $o, int $bytes): int
    {
        $value = 0;

        for ($i = 0; $i < $bytes; $i++) {
            $value = ($value << 8) | ord($s[$o + $i]);
        }

        return $value;
    }
}
