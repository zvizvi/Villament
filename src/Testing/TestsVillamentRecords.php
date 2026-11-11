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

    public function assertCanRenderTableColumn(): Closure
    {
        return function (string $name): static {
            /** @var Testable $this */
            $component = $this->instance();

            /** @phpstan-ignore-next-line */
            $this->assertTableColumnVisible($name);

            if (! VillamentRecordKeys::isVillament($component)) {
                $this->assertSeeHtml(array_map(
                    fn (mixed $record): string => "wire:key=\"{$component->getId()}.table.record.{$component->getTableRecordKey($record)}.column.{$name}\"",
                    $component->getTableRecords()->all(),
                ));

                return $this;
            }

            // Each row the island paints carries a cell per VISIBLE column.
            Assert::assertArrayHasKey($name, $component->getTable()->getVisibleColumns(), "Failed asserting that the Villament table renders the column [{$name}].");

            return $this;
        };
    }

    public function assertCanNotRenderTableColumn(): Closure
    {
        return function (string $name): static {
            /** @var Testable $this */
            $component = $this->instance();

            /** @phpstan-ignore-next-line */
            $this->assertTableColumnExists($name);

            if (! VillamentRecordKeys::isVillament($component)) {
                $this->assertDontSeeHtml(array_map(
                    fn (mixed $record): string => "wire:key=\"{$component->getId()}.table.record.{$component->getTableRecordKey($record)}.column.{$name}\"",
                    $component->getTableRecords()->all(),
                ));

                return $this;
            }

            if (VillamentRecordKeys::shownKeys($component) !== []) {
                Assert::assertArrayNotHasKey($name, $component->getTable()->getVisibleColumns(), "Failed asserting that the Villament table does not render the column [{$name}].");
            }

            return $this;
        };
    }

    /**
     * What the rows SAY: every string the island would paint for the current
     * page (cell text, cell HTML, tooltips, descriptions, action labels), the
     * Villament counterpart of `assertSee()` on a stock table. `escape`
     * behaves as in `assertSee()`: by default a value is also found in its
     * HTML-escaped form, which is how text sits inside an HTML cell.
     *
     * On a page without the island it is plain `assertSee()`.
     *
     * @param  string|array<int, string>  $values
     */
    public function assertSeeInTable(): Closure
    {
        return function (string|array $values, bool $escape = true): static {
            /** @var Testable $this */
            $component = $this->instance();

            if (! VillamentRecordKeys::isVillament($component)) {
                $this->assertSee($values, $escape);

                return $this;
            }

            $haystack = VillamentRecordKeys::tableText($component);

            foreach ((array) $values as $value) {
                Assert::assertTrue(
                    VillamentRecordKeys::contains($haystack, (string) $value, $escape),
                    "Failed asserting that the Villament table shows [{$value}].",
                );
            }

            return $this;
        };
    }

    /**
     * @param  string|array<int, string>  $values
     */
    public function assertDontSeeInTable(): Closure
    {
        return function (string|array $values, bool $escape = true): static {
            /** @var Testable $this */
            $component = $this->instance();

            if (! VillamentRecordKeys::isVillament($component)) {
                $this->assertDontSee($values, $escape);

                return $this;
            }

            $haystack = VillamentRecordKeys::tableText($component);

            foreach ((array) $values as $value) {
                Assert::assertFalse(
                    VillamentRecordKeys::contains($haystack, (string) $value, $escape),
                    "Failed asserting that the Villament table does not show [{$value}].",
                );
            }

            return $this;
        };
    }

    /**
     * What `assertSeeInTable()` searches, for an assertion it cannot express
     * (counting, a regular expression). On a page without the island it is
     * the rendered HTML, as `html()` would return it.
     */
    public function getTableText(): Closure
    {
        return function (): string {
            /** @var Testable $this */
            $component = $this->instance();

            return VillamentRecordKeys::isVillament($component)
                ? VillamentRecordKeys::tableText($component)
                : $this->html();
        };
    }
}
