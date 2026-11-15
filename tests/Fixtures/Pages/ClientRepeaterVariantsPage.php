<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Zvizvi\Villament\Forms\ClientRepeater;

/**
 * `ClientRepeater`s configured to hit every branch of the "which actions run
 * in the browser" decision.
 *
 * @property-read Schema $form
 */
class ClientRepeaterVariantsPage extends Page
{
    protected static ?string $slug = 'client-repeater-variants';

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
        $field = fn (): array => [TextInput::make('name')->default('x'), TextInput::make('label')->default('outer')];

        return $schema
            ->statePath('data')
            ->components([
                // Presentation only: every action stays in the browser.
                ClientRepeater::make('restyled')
                    ->schema($field())
                    ->addAction(fn (Action $action): Action => $action->label('Add a row')->icon('heroicon-m-plus'))
                    ->deleteAction(fn (Action $action): Action => $action->color('warning')->tooltip('Remove')),
                // Behaviour: those two go back to Livewire, the rest stay.
                ClientRepeater::make('confirmed')
                    ->schema($field())
                    ->deleteAction(fn (Action $action): Action => $action->requiresConfirmation())
                    ->addAction(fn (Action $action): Action => $action->after(fn () => null)),
                ClientRepeater::make('replaced')
                    ->schema($field())
                    ->addAction(fn (Action $action): Action => $action->action(fn () => null)),
                ClientRepeater::make('live')->schema($field())->live(),
                ClientRepeater::make('hooked')->schema($field())->afterStateUpdated(fn () => null),
                ClientRepeater::make('simple')->simple(TextInput::make('name')),
                ClientRepeater::make('integer_keys')->schema($field())->generateUuidUsing(false),
                // Nested repeaters: the first two can share their template
                // with the outer one; a closure, or a stock outer, cannot.
                ClientRepeater::make('outer')->schema([
                    ClientRepeater::make('shared')->schema($field()),
                    ClientRepeater::make('closured')->schema($field())->addActionLabel(fn (): string => 'Per row'),
                    // A closure on a DESCENDANT: its default reads the row.
                    ClientRepeater::make('row_default')->schema([
                        TextInput::make('copy')->default(fn (Get $get): ?string => $get('../../label')),
                    ]),
                ]),
                Repeater::make('stock_outer')->schema([
                    ClientRepeater::make('inner')->schema($field()),
                ]),
                ClientRepeater::make('hidden_table')
                    ->table([TableColumn::make('Name')])
                    ->schema([TextInput::make('name'), Hidden::make('secret')]),
                // Server units and nothing else said: client rendering stays off.
                ClientRepeater::make('mixed_auto')->schema([
                    TextInput::make('name'),
                    Select::make('owner')->searchable()->getSearchResultsUsing(fn (): array => []),
                ]),
                // An app `afterStateHydrated()` would run on every render.
                ClientRepeater::make('hydrating')->schema([
                    TextInput::make('name')->afterStateHydrated(fn () => null),
                ]),
                ClientRepeater::make('hydrating_opted_in')->clientStructure()->schema([
                    TextInput::make('name')->afterStateHydrated(fn () => null),
                ]),
                // UUID-shaped keys in DATA stay; nested item keys are renamed.
                ClientRepeater::make('uuid_data')->schema([
                    // An array default keyed by UUIDs: the app's data.
                    Hidden::make('meta')->default(['0a8d7c1e-1111-4a2b-8c3d-000000000001' => 'kept']),
                    ClientRepeater::make('nested')->defaultItems(1)->schema($field()),
                ]),
                ClientRepeater::make('disabled_moves')->schema($field())
                    ->moveUpAction(fn (Action $action): Action => $action->disabled(fn (): bool => true)),
                ClientRepeater::make('server_rendered')->schema($field())->clientRendering(false),
                ClientRepeater::make('opted_out')->schema($field())->clientStructure(false),
                ClientRepeater::make('empty')->schema($field())->defaultItems(0),
                ClientRepeater::make('full')->schema($field())->defaultItems(2)->maxItems(2)->cloneable()->labelBetweenItems('or'),
            ]);
    }

    public function save(): void
    {
        $this->saved = $this->form->getState();
    }
}
