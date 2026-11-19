<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations;

use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The notes table, shared by the notes relation manager and both
 * ManageRelatedRecords pages.
 */
final class NotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->columns([
                TextColumn::make('body')->sortable()->searchable(),
                TextColumn::make('kind')->badge(),
            ])
            ->filters([
                SelectFilter::make('kind')->options(['client' => 'Client', 'stock' => 'Stock']),
            ])
            ->recordActions([DeleteAction::make()])
            ->defaultSort('body');
    }
}
