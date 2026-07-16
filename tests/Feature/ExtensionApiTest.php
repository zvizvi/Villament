<?php

declare(strict_types=1);

use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ViewColumn;
use Zvizvi\FilamentVueTable\Support\ColumnSerializers\ColumnSerializer;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * Custom serializer fixture: takes over the fixture's email ViewColumn and
 * emits a bespoke `email-card` cell (the JS side pairs it with a component
 * registered via window.FilamentVueTable.registerCellComponent).
 */
class EmailCardColumnSerializer implements ColumnSerializer
{
    public function canSerialize(Column $column): bool
    {
        return $column instanceof ViewColumn && $column->getName() === 'email';
    }

    public function type(Column $column): string
    {
        return 'email-card';
    }

    public function serialize(Column $column): array
    {
        $record = $column->getRecord();

        return [
            'type' => 'email-card',
            'email' => $record?->email,
        ];
    }
}

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));
});

it('routes cells through custom column serializers registered in the config', function (): void {
    config()->set('filament-vue-table.column_serializers', [EmailCardColumnSerializer::class]);

    $user = User::factory()->create(['name' => 'Extension Target']);

    $record = getJson(ENDPOINT.'?columnSearches[name]=Extension+Target')->assertOk()->json('records.0');

    expect($record['cells']['email'])->toBe(['type' => 'email-card', 'email' => $user->email]);

    // The schema column type follows the same chain.
    $config = vueTableConfig();
    $emailColumn = collect($config['schema']['columns'])->firstWhere('key', 'email');
    expect($emailColumn['type'])->toBe('email-card');
});

it('ships the configured debounce and applies the cache TTL header', function (): void {
    config()->set('filament-vue-table.debounce_ms', 150);
    config()->set('filament-vue-table.cache_ttl', 30);

    User::factory()->create();

    expect(vueTableConfig()['schema']['debounceMs'])->toBe(150);

    getJson(ENDPOINT)->assertOk()->assertHeader('Cache-Control', 'max-age=30, private');
});

it('does not send a cache header by default', function (): void {
    User::factory()->create();

    expect(getJson(ENDPOINT)->assertOk()->headers->get('Cache-Control'))->not->toContain('max-age');
});
