<?php

declare(strict_types=1);

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Zvizvi\Villament\Forms\Support\Renderability;

it('renders state-driven fields and plain layouts on the client', function ($component): void {
    expect(Renderability::isClientRenderable($component))->toBeTrue();
})->with([
    'TextInput with rules, live()' => fn () => TextInput::make('a')->email()->required()->maxLength(5)->rule('not_in:x')->live(),
    'native Select' => fn () => Select::make('a')->native()->options(['x' => 'y'])->default('x'),
    'Toggle' => fn () => Toggle::make('a')->default(true),
    // Filament's own setUp() closures (TagsInput's placeholder, …) are exempt.
    'TagsInput' => fn () => TagsInput::make('a'),
    // Lifecycle hooks never reach the markup.
    'afterStateHydrated()' => fn () => Textarea::make('a')->afterStateHydrated(fn () => null),
    'Grid' => fn () => Grid::make(2),
    'Section' => fn () => Section::make('x'),
    'Tabs' => fn () => Tabs::make('t'),
]);

it('sends to the server what only the server can render', function ($component): void {
    expect(Renderability::isClientRenderable($component))->toBeFalse();
})->with([
    'a visible() closure' => fn () => TextInput::make('a')->visible(fn () => true),
    'a label() closure' => fn () => TextInput::make('a')->label(fn () => 'Per row'),
    'an extraAttributes() closure' => fn () => TextInput::make('a')->extraAttributes(fn () => []),
    'searchable Select' => fn () => Select::make('a')->searchable(),
    'multiple Select' => fn () => Select::make('a')->native()->multiple(),
    'FileUpload' => fn () => FileUpload::make('a'),
    'nested Repeater' => fn () => Repeater::make('a')->schema([TextInput::make('b')]),
    'a Section with a closure' => fn () => Section::make('x')->visible(fn () => true),
    'every validation message' => fn () => TextInput::make('a')->showAllValidationMessages(),
    'a subclass of a known field' => fn () => new class('a') extends TextInput {},
]);
