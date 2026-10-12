<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mapowanie "oferta na Allegro -> produkt w sklepie" dla automatycznego
 * tworzenia kodow "Allegro" (App\Models\ProductCoupon) z zamowien Allegro -
 * patrz App\Console\Commands\SyncAllegroOrders. allegro_offer_id to ID
 * oferty/aukcji z Allegro (widoczne w adresie URL oferty, np.
 * allegro.pl/oferta/NAZWA-ID), NIE id zamowienia.
 *
 * quantity_per_unit: ile sztuk PRODUKTU w sklepie przysluguje za KAZDA
 * zakupiona sztuke tej oferty Allegro - najczesciej 1, ale np. oferta
 * "zestaw 2 kubkow" mapowalaby sie na quantity_per_unit=2.
 */
class AllegroOfferMapping extends Model
{
    protected $fillable = ['allegro_offer_id', 'label', 'product_id', 'quantity_per_unit'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
