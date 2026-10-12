<?php

namespace App\Console\Commands;

use App\Mail\AllegroCouponCreated;
use App\Models\AllegroOfferMapping;
use App\Models\ProductCoupon;
use App\Models\ProductCouponItem;
use App\Services\AllegroClient;
use App\Settings\ShopSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Odpytuje Allegro Order Events API (brak webhookow - polling, patrz
 * App\Services\AllegroClient) co kilka minut (routes/console.php) i dla
 * kazdego zamowienia ze statusem READY_FOR_PROCESSING (oplacone/
 * potwierdzone - patrz developer.allegro.pl/tutorials/jak-obslugiwac-
 * -zamowienia) sprawdza, czy ktoras z kupionych ofert jest zmapowana
 * (App\Models\AllegroOfferMapping) na produkt w sklepie. Jesli tak, tworzy
 * kod "Allegro" (App\Models\ProductCoupon + ProductCouponItem) i wysyla
 * kupujacemu maila z linkiem do zaprojektowania.
 *
 * allegro_order_id na ProductCoupon pilnuje, zeby to samo zamowienie nie
 * zostalo przetworzone dwa razy (np. jesli to samo zdarzenie pojawi sie
 * ponownie przy kolejnym pollu).
 */
class SyncAllegroOrders extends Command
{
    protected $signature = 'allegro:sync-orders';
    protected $description = 'Sprawdza nowe oplacone zamowienia Allegro i automatycznie tworzy kody "Allegro" dla zmapowanych ofert.';

    public function handle(AllegroClient $client, ShopSettings $settings): int
    {
        if (!$settings->allegro_enabled) {
            return self::SUCCESS;
        }

        $refreshToken = $settings->allegro_sandbox ? $settings->allegro_refresh_token_sandbox : $settings->allegro_refresh_token;

        if (!$refreshToken) {
            $this->warn('Allegro: konto nie jest jeszcze połączone (brak tokenów dla ' . ($settings->allegro_sandbox ? 'środowiska testowego' : 'produkcji') . ') — pomijam.');
            return self::SUCCESS;
        }

        try {
            $client->ensureValidToken();
        } catch (\Throwable $e) {
            Log::error('Allegro sync: nie udało się odświeżyć tokenu — ' . $e->getMessage());
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        try {
            $response = $client->getOrderEvents($settings->allegro_last_event_id ?: null);
        } catch (\Throwable $e) {
            Log::error('Allegro sync: nie udało się pobrać listy zdarzeń — ' . $e->getMessage());
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $events = $response['events'] ?? [];

        if (empty($events)) {
            return self::SUCCESS;
        }

        $created = 0;
        $lastEventId = $settings->allegro_last_event_id;

        foreach ($events as $event) {
            $lastEventId = $event['id'] ?? $lastEventId;

            if (($event['type'] ?? null) !== 'READY_FOR_PROCESSING') {
                continue;
            }

            $orderId = $event['order']['checkoutForm']['id'] ?? null;

            if (!$orderId) {
                Log::warning('Allegro sync: zdarzenie bez ID zamówienia, pomijam.', ['event' => $event]);
                continue;
            }

            if (ProductCoupon::where('allegro_order_id', $orderId)->exists()) {
                continue;
            }

            try {
                if ($this->processOrder($client, $orderId)) {
                    $created++;
                }
            } catch (\Throwable $e) {
                Log::error("Allegro sync: błąd przetwarzania zamówienia {$orderId} — " . $e->getMessage());
                $this->error("Zamówienie {$orderId}: {$e->getMessage()}");
            }
        }

        // Kursor przesuwamy ZAWSZE do ostatniego zobaczonego zdarzenia, nawet
        // jesli nic z niego nie wynikalo (np. inny typ zdarzenia) - inaczej
        // kazdy kolejny przebieg odpytywalby od nowa te sama, rosnaca historie.
        if ($lastEventId !== $settings->allegro_last_event_id) {
            $settings->allegro_last_event_id = $lastEventId;
            $settings->save();
        }

        $this->info("Sprawdzono zdarzeń: " . count($events) . ". Utworzono kodów: {$created}.");
        return self::SUCCESS;
    }

    /**
     * @return bool true jesli powstal kod (co najmniej jedna pozycja zamowienia
     *              pasowala do zmapowanej oferty)
     */
    private function processOrder(AllegroClient $client, string $orderId): bool
    {
        $checkoutForm = $client->getCheckoutForm($orderId);

        $buyerEmail = $checkoutForm['buyer']['email'] ?? null;
        $lineItems  = $checkoutForm['lineItems'] ?? [];

        if (!$buyerEmail || empty($lineItems)) {
            Log::warning("Allegro sync: zamówienie {$orderId} bez e-maila kupującego lub pozycji, pomijam.", ['checkoutForm' => $checkoutForm]);
            return false;
        }

        $matches = [];

        foreach ($lineItems as $lineItem) {
            $offerId = $lineItem['offer']['id'] ?? null;
            $qty     = (int) ($lineItem['quantity'] ?? 1);

            if (!$offerId) {
                continue;
            }

            $mapping = AllegroOfferMapping::where('allegro_offer_id', $offerId)->first();

            if ($mapping) {
                $matches[] = [
                    'product_id' => $mapping->product_id,
                    'quantity'   => $mapping->quantity_per_unit * $qty,
                ];
            }
        }

        if (empty($matches)) {
            return false;
        }

        $coupon = ProductCoupon::create([
            'code'             => ProductCoupon::generateCode(),
            'label'            => 'Allegro #' . $orderId,
            'allegro_order_id' => $orderId,
        ]);

        foreach ($matches as $match) {
            ProductCouponItem::create([
                'product_coupon_id' => $coupon->id,
                'product_id'        => $match['product_id'],
                'quantity'          => $match['quantity'],
            ]);
        }

        try {
            Mail::to($buyerEmail)->send(new AllegroCouponCreated($coupon));
        } catch (\Throwable $e) {
            Log::error("Allegro sync: kod {$coupon->code} utworzony, ale wysyłka maila do {$buyerEmail} nie powiodła się — " . $e->getMessage());
        }

        return true;
    }
}
