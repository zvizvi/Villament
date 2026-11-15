<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
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
        $field = fn (): array => [TextInput::make('name')->default('x')];

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
                ]),
                Repeater::make('stock_outer')->schema([
                    ClientRepeater::make('inner')->schema($field()),
                ]),
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
