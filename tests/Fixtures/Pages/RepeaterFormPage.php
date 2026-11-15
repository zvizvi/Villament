<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Pages;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Zvizvi\Villament\Forms\ClientRepeater;

/**
 * One form, two repeaters with the same definition: `client_items` is a
 * `ClientRepeater`, `stock_items` the stock `Repeater`. Parity tests run the
 * same operations on both and compare what `save()` stores.
 *
 * @property-read Schema $form
 */
class RepeaterFormPage extends Page
{
    protected static ?string $slug = 'repeater-form';

    protected string $view = 'fixtures.repeater-form-page';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $saved = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                static::repeater(ClientRepeater::class, 'client_items'),
                static::repeater(Repeater::class, 'stock_items'),
                static::flatRepeater(ClientRepeater::class, 'client_flat'),
                static::flatRepeater(Repeater::class, 'stock_flat'),
                static::simpleRepeater(ClientRepeater::class, 'client_simple'),
                static::simpleRepeater(Repeater::class, 'stock_simple'),
                static::tableRepeater(ClientRepeater::class, 'client_table'),
                static::tableRepeater(Repeater::class, 'stock_table'),
            ]);
    }

    public function save(): void
    {
        $this->saved = $this->form->getState();
    }

    /**
     * The `table()` layout: rows of cells, a reorder column from two rows.
     *
     * @param  class-string<Repeater>  $class
     */
    public static function tableRepeater(string $class, string $name): Repeater
    {
        return $class::make($name)
            ->table([
                TableColumn::make('SKU'),
                TableColumn::make('Size'),
                TableColumn::make('Active'),
            ])
            ->schema([
                TextInput::make('sku')->default('NEW')->rule('not_in:bad'),
                Select::make('size')->native()->options(['s' => 'S', 'm' => 'M'])->default('s'),
                Toggle::make('active')->default(true),
            ])
            ->defaultItems(1)
            ->cloneable()
            ->reorderableWithButtons()
            ->maxItems(4);
    }

    /**
     * The `simple()` layout: one field per item, state saved as a flat list.
     *
     * @param  class-string<Repeater>  $class
     */
    public static function simpleRepeater(string $class, string $name): Repeater
    {
        return $class::make($name)
            ->simple(TextInput::make('email')->default('new@example.test'))
            ->defaultItems(2)
            ->cloneable()
            ->reorderableWithButtons()
            ->maxItems(4);
    }

    /**
     * Only fields whose markup follows the state on the client, so even
     * cloning runs in the browser.
     *
     * @param  class-string<Repeater>  $class
     */
    public static function flatRepeater(string $class, string $name): Repeater
    {
        return $class::make($name)
            ->defaultItems(2)
            ->cloneable()
            ->reorderableWithButtons()
            ->itemNumbers()
            ->itemLabel('Row')
            ->minItems(1)
            ->maxItems(5)
            ->schema([
                // `not_in` is server-only: `required` alone would be stopped by
                // the browser's own validation before the request.
                TextInput::make('label')->default('Row')->required()->rule('not_in:bad'),
                Select::make('size')->native()->options(['s' => 'S', 'm' => 'M'])->default('m'),
                Toggle::make('enabled')->default(false),
            ]);
    }

    /**
     * @param  class-string<Repeater>  $class
     */
    public static function repeater(string $class, string $name): Repeater
    {
        $nested = $class === ClientRepeater::class ? ClientRepeater::class : Repeater::class;

        return $class::make($name)
            ->defaultItems(1)
            ->cloneable()
            ->collapsible()
            ->maxItems(6)
            ->addBetweenAction(fn ($action) => $action->visible())
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')->default('New')->required(),
                    Select::make('role')
                        ->native()
                        ->options(['admin' => 'Admin', 'editor' => 'Editor'])
                        ->default('editor'),
                    Toggle::make('active')->default(true)->live(),
                    // Shown per row from the server: a client-rendered row
                    // places it from its slot after every request.
                    TextInput::make('vip_note')->visible(fn (Get $get): bool => (bool) $get('active')),
                    DatePicker::make('due'),
                    Select::make('owner')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => ['u1' => 'Jane', 'u2' => 'John'])
                        ->getOptionLabelUsing(fn ($value): ?string => ['u1' => 'Jane', 'u2' => 'John'][$value] ?? null),
                    Textarea::make('note')
                        ->afterStateHydrated(fn (Textarea $component, $state) => $component->state($state ?? 'hydrated')),
                    FileUpload::make('attachment')->disk('local'),
                ]),
                $nested::make('tasks')
                    ->defaultItems(1)
                    ->schema([
                        TextInput::make('title')->default('Task'),
                    ]),
            ]);
    }
}
