<?php

namespace App\Filament\Resources\AllegroOfferMappings\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AllegroOfferMappingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('allegro_offer_id')
                    ->label('ID oferty Allegro')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('label')
                    ->label('Opis')
                    ->placeholder('—')
                    ->limit(40),
                TextColumn::make('product.name')
                    ->label('Produkt')
                    ->searchable(),
                TextColumn::make('quantity_per_unit')
                    ->label('Ilość / 1 szt. oferty')
                    ->alignCenter(),
                TextColumn::make('created_at')
                    ->label('Dodano')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
