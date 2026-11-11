<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Testing;

use Closure;
use Illuminate\Support\Collection;
use Livewire\Features\SupportTesting\Testable;
use PHPUnit\Framework\Assert;

/**
 * Filament's record assertions, taught about the island.
 *
 * Stock `assertCanSeeTableRecords()` / `assertCanNotSeeTableRecords()` look
 * for each row's `wire:key` in the rendered HTML. A Villament page renders no
 * rows on the server — the island paints them from the inlined first page —
 * so on such a page both assertions answer from that same first page instead:
 * the records `getTableRecords()` returns for the component's current state,
 * which is exactly what `getVillamentTableConfig()` inlines. Any other
 * component keeps Filament's own HTML-based behaviour, unchanged.
 *
 * @mixin Testable
 */
final class TestsVillamentRecords
{
    public function assertCanSeeTableRecords(): Closure
    {
        return function (array|Collection $records, bool $inOrder = false): static {
            /** @var Testable $this */
            $component = $this->instance();

            if (! VillamentRecordKeys::isVillament($component)) {
                $html = array_map(
                    fn (mixed $record): string => "{$component->getId()}.table.records.".VillamentRecordKeys::keyOf($component, $record),
                    $records instanceof Collection ? $records->all() : $records,
                );

                $inOrder ? $this->assertSeeHtmlInOrder($html) : $this->assertSeeHtml($html);

                return $this;
            }

            $expected = VillamentRecordKeys::keysOf($component, $records);
            $shown = VillamentRecordKeys::shownKeys($component);

            foreach ($expected as $key) {
                Assert::assertContains($key, $shown, "Failed asserting that the Villament table shows the record [{$key}].");
            }

            if ($inOrder) {
                $positions = array_map(fn (string $key): int => (int) array_search($key, $shown, true), $expected);
                $sorted = $positions;
                sort($sorted);

                Assert::assertSame($sorted, $positions, 'Failed asserting that the Villament table shows the records in the given order.');
            }

            return $this;
        };
    }

    public function assertCanNotSeeTableRecords(): Closure
    {
        return function (array|Collection $records): static {
            /** @var Testable $this */
            $component = $this->instance();

            if (! VillamentRecordKeys::isVillament($component)) {
                $this->assertDontSeeHtml(array_map(
                    fn (mixed $record): string => "wire:key=\"{$component->getId()}.table.records.".VillamentRecordKeys::keyOf($component, $record).'"',
                    $records instanceof Collection ? $records->all() : $records,
                ));

                return $this;
            }

            $shown = VillamentRecordKeys::shownKeys($component);

            foreach (VillamentRecordKeys::keysOf($component, $records) as $key) {
                Assert::assertNotContains($key, $shown, "Failed asserting that the Villament table does not show the record [{$key}].");
            }

            return $this;
        };
    }
}
