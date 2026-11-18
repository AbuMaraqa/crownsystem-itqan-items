<?php

declare(strict_types=1);

namespace Modules\Core\Support\Itqan;

use InvalidArgumentException;

/**
 * سجل مراحل نقل إتقان. كل وحدة تسجل مراحلها من مزوّد خدماتها، والامر يرتبها حسب الاعتماد.
 */
final class ItqanStages
{
    /** @var array<string, class-string<ItqanImportStage>> */
    private array $stages = [];

    /** @param class-string<ItqanImportStage> $class */
    public function register(string $key, string $class): void
    {
        $this->stages[$key] = $class;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->stages);
    }

    public function make(string $key): ItqanImportStage
    {
        if (! isset($this->stages[$key])) {
            throw new InvalidArgumentException("Unknown Itqan stage [{$key}]. Available: ".implode(', ', $this->keys()));
        }

        return app($this->stages[$key]);
    }

    /**
     * المراحل المطلوبة مرتبة بحيث تسبق كل مرحلة ما يعتمد عليها. المرحلة السابقة لا تُضاف تلقائياً:
     * إن لم تُطلب فهي منقولة من قبل.
     *
     * @param  array<int, string>  $requested
     * @return array<int, ItqanImportStage>
     */
    public function ordered(array $requested): array
    {
        $requested = $requested === [] ? $this->keys() : array_values(array_unique($requested));
        $stages = [];

        foreach ($requested as $key) {
            $stages[$key] = $this->make($key);
        }

        $ordered = [];
        $visiting = [];

        $visit = function (string $key) use (&$visit, &$ordered, &$visiting, $stages): void {
            if (isset($ordered[$key]) || ! isset($stages[$key])) {
                return;
            }

            if (isset($visiting[$key])) {
                throw new InvalidArgumentException("Circular Itqan stage dependency at [{$key}].");
            }

            $visiting[$key] = true;

            foreach ($stages[$key]->dependsOn() as $dependency) {
                $visit($dependency);
            }

            $ordered[$key] = $stages[$key];
        };

        foreach (array_keys($stages) as $key) {
            $visit($key);
        }

        return array_values($ordered);
    }
}
