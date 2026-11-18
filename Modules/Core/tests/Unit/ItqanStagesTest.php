<?php

namespace Modules\Core\tests\Unit;

use InvalidArgumentException;
use Modules\Core\Support\Itqan\ItqanImportStage;
use Modules\Core\Support\Itqan\ItqanSource;
use Modules\Core\Support\Itqan\ItqanStages;
use Tests\TestCase;

/**
 * ترتيب مراحل نقل إتقان: كل مرحلة بعد ما تعتمد عليه، والمرحلة غير المطلوبة لا تُضاف.
 */
class ItqanStagesTest extends TestCase
{
    public function test_stages_run_after_their_dependencies_whatever_the_requested_order(): void
    {
        $stages = $this->registry();

        $keys = array_map(fn (ItqanImportStage $stage): string => $stage->key(), $stages->ordered(['prices', 'items']));

        $this->assertSame(['items', 'prices'], $keys);
    }

    public function test_no_request_runs_every_registered_stage(): void
    {
        $keys = array_map(fn (ItqanImportStage $stage): string => $stage->key(), $this->registry()->ordered([]));

        $this->assertSame(['items', 'prices'], $keys);
    }

    public function test_a_dependency_that_was_not_requested_is_not_run_again(): void
    {
        $keys = array_map(fn (ItqanImportStage $stage): string => $stage->key(), $this->registry()->ordered(['prices']));

        $this->assertSame(['prices'], $keys);
    }

    public function test_an_unknown_stage_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry()->ordered(['nothing']);
    }

    private function registry(): ItqanStages
    {
        $stages = new ItqanStages;
        $stages->register('prices', FakePricesStage::class);
        $stages->register('items', FakeItemsStage::class);

        return $stages;
    }
}

class FakeItemsStage implements ItqanImportStage
{
    public function key(): string
    {
        return 'items';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function run(ItqanSource $source, bool $dryRun, callable $log): array
    {
        return [];
    }
}

class FakePricesStage extends FakeItemsStage
{
    public function key(): string
    {
        return 'prices';
    }

    public function dependsOn(): array
    {
        return ['items'];
    }
}
