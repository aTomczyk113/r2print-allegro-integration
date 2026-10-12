<?php

namespace App\Filament\Resources\AllegroOfferMappings;

use App\Filament\Resources\AllegroOfferMappings\Pages\CreateAllegroOfferMapping;
use App\Filament\Resources\AllegroOfferMappings\Pages\EditAllegroOfferMapping;
use App\Filament\Resources\AllegroOfferMappings\Pages\ListAllegroOfferMappings;
use App\Filament\Resources\AllegroOfferMappings\Schemas\AllegroOfferMappingForm;
use App\Filament\Resources\AllegroOfferMappings\Tables\AllegroOfferMappingsTable;
use App\Models\AllegroOfferMapping;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AllegroOfferMappingResource extends Resource
{
    protected static ?string $model = AllegroOfferMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'Allegro';

    protected static ?string $navigationLabel = 'Mapowanie ofert';

    protected static ?string $modelLabel = 'mapowanie oferty';

    protected static ?string $pluralModelLabel = 'mapowania ofert';

    protected static ?string $recordTitleAttribute = 'allegro_offer_id';

    public static function form(Schema $schema): Schema
    {
        return AllegroOfferMappingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AllegroOfferMappingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListAllegroOfferMappings::route('/'),
            'create' => CreateAllegroOfferMapping::route('/create'),
            'edit'   => EditAllegroOfferMapping::route('/{record}/edit'),
        ];
    }
}
