<?php

namespace App\Filament\Resources\AllegroOfferMappings\Pages;

use App\Filament\Resources\AllegroOfferMappings\AllegroOfferMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAllegroOfferMappings extends ListRecords
{
    protected static string $resource = AllegroOfferMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
