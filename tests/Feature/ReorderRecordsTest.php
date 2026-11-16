<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\Stage;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Stages\Pages\ListStages;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\VueListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['status' => 'active']));

    Stage::query()->insert([
        ['name' => 'Draft', 'sort' => 1],
        ['name' => 'Review', 'sort' => 2],
        ['name' => 'Published', 'sort' => 3],
    ]);
});

/**
 * @return array<string, mixed>
 */
function reorderIslandConfig(string $html): array
{
    preg_match('/<script type="application\/json" data-villament-config>(.*?)<\/script>/s', $html, $matches);

    return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
}

it('renders the island with the stock reorder trigger teleported into its toolbar', function (): void {
    $html = livewire(ListStages::class)->html();

    $config = reorderIslandConfig($html);

    expect($config['schema']['reorderable'])->toBeTrue()
        ->and($config['isReordering'])->toBeFalse()
        ->and($config['initialData']['records'])->toHaveCount(3)
        ->and($html)->toContain('data-villament-reorder-slot="toolbar"')
        ->and($html)->toContain('wire:click="toggleTableReordering"')
        ->and($html)->not->toContain('fi-ta-table');
});

it('hands the page to the stock table while reordering, keeping the island mounted', function (): void {
    $component = livewire(ListStages::class)->call('toggleTableReordering');

    $html = $component->html();

    // The stock table, in its reordering mode, with its own "done" trigger.
    expect($html)->toContain('fi-ta-table')
        ->and($html)->toContain('x-sortable-handle')
        ->and($html)->toContain('fi-ta-reorder-indicator')
        ->and($html)->toContain('Draft')
        // The island is still there, hidden, and was not fed any records.
        ->and($html)->toContain('data-villament')
        ->and($html)->toContain('style="display: none;"')
        ->and(reorderIslandConfig($html)['isReordering'])->toBeTrue()
        ->and(reorderIslandConfig($html)['initialData'])->toBeNull();
});

it('saves the order through the stock table and returns to the island', function (): void {
    [$draft, $review, $published] = Stage::query()->orderBy('sort')->pluck('id')->all();

    $component = livewire(ListStages::class)
        ->call('toggleTableReordering')
        ->call('reorderTable', [(string) $published, (string) $draft, (string) $review]);

    expect(Stage::query()->orderBy('sort')->pluck('name')->all())->toBe(['Published', 'Draft', 'Review']);

    $html = $component->call('toggleTableReordering')->html();

    expect($html)->not->toContain('fi-ta-table')
        ->and(reorderIslandConfig($html)['isReordering'])->toBeFalse()
        ->and(array_map(
            fn (array $record): string => $record['cells']['name']['formatted'] ?? '',
            reorderIslandConfig($html)['initialData']['records'],
        ))->toBe(['Published', 'Draft', 'Review']);
});

it('renders no reorder trigger on a table that is not reorderable', function (): void {
    $html = livewire(VueListUsers::class)->html();

    expect(reorderIslandConfig($html)['schema']['reorderable'])->toBeFalse()
        ->and($html)->not->toContain('data-villament-reorder-slot');
});
