<?php

namespace App\Filament\Resources\AllegroOfferMappings\Schemas;

use App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AllegroOfferMappingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Mapowanie oferty Allegro')
                ->description('ID oferty znajdziesz w adresie URL oferty na Allegro (allegro.pl/oferta/nazwa-ID) albo w panelu sprzedawcy przy liście ofert.')
                ->columns(2)
                ->schema([
                    TextInput::make('allegro_offer_id')
                        ->label('ID oferty Allegro')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->columnSpanFull(),
                    TextInput::make('label')
                        ->label('Opis (tylko dla Ciebie)')
                        ->placeholder('np. Kubek z nadrukiem - oferta 2+1')
                        ->columnSpanFull(),
                    Select::make('product_id')
                        ->label('Produkt w sklepie')
                        ->options(Product::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                    TextInput::make('quantity_per_unit')
                        ->label('Ile sztuk produktu za 1 sztukę tej oferty')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(999)
                        ->default(1)
                        ->required(),
                ]),
        ]);
    }
}
