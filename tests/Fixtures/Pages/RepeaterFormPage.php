<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Pages;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
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
                static::fieldsRepeater(ClientRepeater::class, 'client_fields'),
                static::fieldsRepeater(Repeater::class, 'stock_fields'),
                static::tableRepeater(ClientRepeater::class, 'client_table'),
                static::tableRepeater(Repeater::class, 'stock_table'),
            ]);
    }

    public function save(): void
    {
        $this->saved = $this->form->getState();
    }

    /**
     * One of every field class `Renderability` lets the browser render, each
     * holding a non-empty value: cloning a row in the browser must show what
     * stock's clone shows, field type by field type.
     *
     * @param  class-string<Repeater>  $class
     */
    public static function fieldsRepeater(string $class, string $name): Repeater
    {
        $options = ['a' => 'Alpha', 'b' => 'Beta'];

        return $class::make($name)
            ->defaultItems(1)
            ->cloneable()
            ->schema([
                Checkbox::make('checkbox')->default(true),
                CheckboxList::make('checkbox_list')->options($options)->default(['b']),
                ColorPicker::make('color')->default('#ff0000'),
                DatePicker::make('date')->default('2026-01-02'),
                DateTimePicker::make('date_time')->default('2026-01-02 03:04:05'),
                Hidden::make('hidden')->default('secret'),
                KeyValue::make('key_value')->default(['k' => 'v']),
                Radio::make('radio')->options($options)->default('b'),
                Slider::make('slider')->range(minValue: 0, maxValue: 10)->default(7),
                TagsInput::make('tags')->default(['one', 'two']),
                Textarea::make('textarea')->default('Some text'),
                TextInput::make('text')->default('Value'),
                TimePicker::make('time')->default('03:04:05'),
                Toggle::make('toggle')->default(true),
                ToggleButtons::make('toggle_buttons')->options($options)->default('b'),
                Select::make('select')->native()->options($options)->default('b'),
            ]);
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

        $repeater = $class::make($name);

        if ($repeater instanceof ClientRepeater) {
            // Both explicit: the item has an `afterStateHydrated()` of the
            // app's own (see `note`), and server units (searchable select,
            // file upload, nested repeater).
            $repeater->clientStructure()->clientRendering();
        }

        return $repeater
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
