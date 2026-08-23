<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures;

use Closure;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Zvizvi\Villament\Forms\VillamentRepeater;

/**
 * A bare Livewire form host, so repeater tests exercise the real schema
 * lifecycle (keys, state paths, Livewire ids) rather than a hand-built graph.
 *
 * The repeater under test is supplied per test through `$build`, a static hook
 * rather than a mount argument because Livewire serializes mount arguments and
 * a closure cannot survive that.
 *
 * @var Closure(VillamentRepeater): VillamentRepeater|null
 */
class RepeaterForm extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var (Closure(VillamentRepeater): VillamentRepeater)|null */
    public static ?Closure $build = null;

    /** @var array<string, mixed> */
    public static array $initialState = [];

    public function mount(): void
    {
        $this->form->fill(static::$initialState);
    }

    public function form(Schema $schema): Schema
    {
        $repeater = VillamentRepeater::make('members')
            ->schema([
                TextInput::make('name'),
            ]);

        if (static::$build instanceof Closure) {
            $repeater = (static::$build)($repeater);
        }

        return $schema
            ->statePath('data')
            ->components([$repeater]);
    }

    public function render(): View
    {
        return view('fixtures.repeater-form');
    }

    public static function resetFixture(): void
    {
        static::$build = null;
        static::$initialState = [];
    }
}
