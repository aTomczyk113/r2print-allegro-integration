<?php

namespace App\Filament\Resources\AllegroOfferMappings\Pages;

use App\Filament\Resources\AllegroOfferMappings\AllegroOfferMappingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAllegroOfferMapping extends EditRecord
{
    protected static string $resource = AllegroOfferMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
