<?php

namespace Modules\Core\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Entities\ReferenceSequence;

class ReferenceNumberService
{
    public function generate(string $sequenceCode, CarbonInterface|string|null $date = null, array $options = []): string
    {
        $date = $this->resolveDate($date);
        $periodKey = $this->periodKey($date, $options);
        $scopeKey = (string) ($options['scope_key'] ?? '');
        $prefix = $this->prefix($sequenceCode, $options);
        $padding = (int) ($options['padding'] ?? 5);
        $separator = (string) ($options['separator'] ?? '-');

        return DB::transaction(function () use ($sequenceCode, $periodKey, $scopeKey, $prefix, $padding, $separator) {
            ReferenceSequence::query()->insertOrIgnore([
                'sequence_code' => $sequenceCode,
                'prefix' => $prefix,
                'period_key' => $periodKey,
                'scope_key' => $scopeKey,
                'last_number' => 0,
                'padding' => $padding,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = ReferenceSequence::query()
                ->where('sequence_code', $sequenceCode)
                ->where('period_key', $periodKey)
                ->where('scope_key', $scopeKey)
                ->lockForUpdate()
                ->firstOrFail();

            $sequence->forceFill([
                'prefix' => $prefix,
                'last_number' => $sequence->last_number + 1,
                'padding' => $padding,
            ])->save();

            return $this->format($prefix, $periodKey, $sequence->last_number, $padding, $separator);
        });
    }

    public function preview(string $sequenceCode, CarbonInterface|string|null $date = null, array $options = []): string
    {
        $date = $this->resolveDate($date);
        $periodKey = $this->periodKey($date, $options);
        $scopeKey = (string) ($options['scope_key'] ?? '');
        $prefix = $this->prefix($sequenceCode, $options);
        $padding = (int) ($options['padding'] ?? 5);
        $separator = (string) ($options['separator'] ?? '-');

        $lastNumber = ReferenceSequence::query()
            ->where('sequence_code', $sequenceCode)
            ->where('period_key', $periodKey)
            ->where('scope_key', $scopeKey)
            ->value('last_number') ?: 0;

        return $this->format($prefix, $periodKey, ((int) $lastNumber) + 1, $padding, $separator);
    }

    private function resolveDate(CarbonInterface|string|null $date): CarbonInterface
    {
        if ($date instanceof CarbonInterface) {
            return $date;
        }

        return $date ? Carbon::parse($date) : now();
    }

    private function periodKey(CarbonInterface $date, array $options): string
    {
        $periodFormat = $options['period_format'] ?? 'Ym';

        return $periodFormat ? $date->format($periodFormat) : '';
    }

    private function prefix(string $sequenceCode, array $options): string
    {
        if (filled($options['prefix'] ?? null)) {
            return (string) $options['prefix'];
        }

        $prefix = preg_replace('/[^A-Za-z0-9]/', '', $sequenceCode) ?: $sequenceCode;

        return Str::upper($prefix);
    }

    private function format(string $prefix, string $periodKey, int $number, int $padding, string $separator): string
    {
        return collect([
            $prefix,
            $periodKey,
            str_pad((string) $number, $padding, '0', STR_PAD_LEFT),
        ])->filter(fn (string $part) => $part !== '')
            ->implode($separator);
    }
}
