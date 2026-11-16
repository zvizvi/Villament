<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\Stages;

use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Zvizvi\Villament\Tests\Fixtures\Models\Stage;
use Zvizvi\Villament\Tests\Fixtures\Resources\Stages\Pages\ListStages;

class StageResource extends Resource
{
    protected static ?string $model = Stage::class;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
            ])
            ->defaultSort('sort')
            ->reorderable('sort');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListStages::route('/'),
        ];
    }
}
