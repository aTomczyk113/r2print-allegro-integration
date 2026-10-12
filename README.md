# R2print — integracja z Allegro REST API

Dokumentacja i kod źródłowy wewnętrznej integracji sklepu internetowego **[r2print.pl](https://r2print.pl)** (drukarnia) z [Allegro REST API](https://developer.allegro.pl), przygotowane na potrzeby weryfikacji aplikacji produkcyjnej w panelu [apps.developer.allegro.pl](https://apps.developer.allegro.pl).

To repozytorium zawiera **wyłącznie fragment kodu odpowiedzialny za integrację z Allegro**, wyodrębniony z prywatnego repozytorium całego sklepu (Laravel 13 + Filament 5). Reszta platformy (katalog produktów, płatności, konfiguratory druku itd.) pozostaje prywatna.

## Do czego służy ta aplikacja

Sklep sprzedaje część swoich produktów (ulotki, naklejki, kalendarze, wizytówki itd.) również jako aukcje na Allegro. Po stronie Allegro nie ma webhooków na nowe zamówienia, więc aplikacja **cyklicznie odpytuje Allegro o nowe, opłacone zamówienia** i dla każdego z nich automatycznie generuje wewnętrzny kod rabatowy, wysyłany mailem do kupującego — kod pozwala mu zaprojektować i zamówić swój produkt bezpośrednio w sklepie, bez dodatkowej płatności (już opłacił przez Allegro).

Przebieg:
1. Zadanie cykliczne (co 5 minut, `allegro:sync-orders`) odpytuje `GET /order/events`.
2. Dla każdego nowego zdarzenia typu `READY_FOR_PROCESSING` (zamówienie opłacone) pobiera szczegóły przez `GET /order/checkout-forms/{id}`.
3. Na podstawie wcześniej skonfigurowanego mapowania oferta Allegro → produkt w sklepie, tworzy kod rabatowy i wysyła go mailem na adres kupującego (pobrany z danych zamówienia).
4. Zapamiętuje ID przetworzonego zamówienia, żeby nie wygenerować kodu dwa razy dla tego samego zamówienia.

Panel administracyjny sklepu (Filament) pozwala właścicielowi: połączyć konto Allegro (OAuth2 Device Flow), zarządzać mapowaniem ofert na produkty oraz włączać/wyłączać automatyzację.

To jest **żywy, rozwijany back-office sprzedawcy** dla zamówień z Allegro, nie jednorazowy skrypt — integracja z API Allegro jest rozbudowywana sukcesywnie w miarę kolejnych potrzeb sklepu. Obecnie (stan opisany wyżej) działa wyłącznie odczyt zamówień. W bezpośrednim planie rozwoju:

- **Drukowanie etykiet nadawczych i zlecanie odbioru kuriera** bezpośrednio z panelu sklepu, bez przełączania się na stronę Allegro, dla zamówień realizowanych przez Allegro.
- **Zarządzanie statusem zamówienia** z panelu: numer śledzenia, oznaczanie jako wysłane.
- **Zarządzanie ofertami** (tworzenie/edycja/publikacja) z tego samego panelu co reszta katalogu produktów sklepu, zamiast osobno na Allegro.
- **Obsługa wiadomości, ocen i sporów** z kupującymi z poziomu jednego, wspólnego back-office, zamiast osobno na Allegro.

## Jakich uprawnień (scope) używa / o jakie wnioskuje

| Scope | Status | Zastosowanie |
|---|---|---|
| `allegro:api:orders:read` | **Używane dziś** | `GET /order/events` + `GET /order/checkout-forms/{id}` — jedyne wywołania w aktualnie działającej automatyzacji (patrz wyżej). |
| `allegro:api:orders:write` | Planowane | Numer śledzenia, status zamówienia, tworzenie/usuwanie przesyłki z poziomu panelu sklepu. |
| `allegro:api:shipments:read` | Planowane | Odczyt danych przesyłek i etykiet nadawczych. |
| `allegro:api:shipments:write` | Planowane | Tworzenie przesyłki i zlecanie odbioru kuriera — drukowanie etykiety bez wychodzenia z panelu sklepu. |
| `allegro:api:sale:offers:read` / `:write` | Planowane | Zarządzanie ofertami Allegro z tego samego panelu co reszta katalogu produktów. |
| `allegro:api:sale:settings:read` / `:write` | Planowane | Cenniki dostaw i polityki zwrotów współdzielone z ustawieniami dostawy w sklepie. |
| `allegro:api:profile:read` / `:write` | Planowane | Podgląd statystyk/ocen konta sprzedawcy w panelu sklepu. |
| `allegro:api:ratings` | Planowane | Obsługa ocen kupujących z jednego back-office. |
| `allegro:api:disputes` | Planowane | Obsługa sporów transakcyjnych z jednego back-office. |
| `allegro:api:messaging` | Planowane | Obsługa wiadomości z kupującymi z jednego back-office. |
| `allegro:api:billing:read` | Planowane | Podgląd rozliczeń Allegro w panelu sklepu. |
| `allegro:api:payments:read` / `:write` | Planowane | Podgląd płatności i obsługa zwrotów z poziomu panelu sklepu. |
| `allegro:api:campaigns` | Planowane | Zgłaszanie ofert do oznaczeń i programów specjalnych Allegro. |
| `allegro:api:affiliate:read` / `:write` | Planowane | Program partnerski Allegro. |

Celowo pominięte:
- `allegro:api:fulfillment:*` — dotyczy wyłącznie usługi magazynowej "One Fulfillment by Allegro", z której sklep nie korzysta (towar pakowany i wysyłany jest samodzielnie).
- `allegro:api:bids` — licytowanie jako kupujący w aukcjach; sklep występuje tu wyłącznie jako sprzedawca.
- `allegro:api:ads` — zarządzanie kampaniami Allegro Ads; nieistotne dla tej integracji, a samo uprawnienie jest zapowiedziane przez Allegro do wycofania.

Autoryzacja: **OAuth2 Device Flow** (typ aplikacji „bez przeglądarki" / no-browser) — jednorazowa, interaktywna zgoda właściciela konta, dalej działa bez udziału człowieka na tokenach odświeżających.

## Struktura kodu w tym repozytorium

```
app/Services/AllegroClient.php                        Klient OAuth2 Device Flow + wywołania Order API
app/Console/Commands/SyncAllegroOrders.php             Zadanie cykliczne (co 5 min) tworzące kody z opłaconych zamówień
app/Filament/Pages/AllegroSettings.php                 Panel: połączenie konta (Device Flow UI)
app/Filament/Resources/AllegroOfferMappings/           Panel: mapowanie oferta Allegro -> produkt w sklepie
app/Models/AllegroOfferMapping.php                     Model mapowania
app/Mail/AllegroCouponCreated.php                      Mail do kupującego z kodem
resources/views/emails/allegro/coupon-created.blade.php Szablon maila
database/migrations/                                   Migracje: ustawienia + tabela mapowań
```

Kod korzysta z wewnętrznych klas sklepu (`App\Settings\ShopSettings` — przechowuje dane uwierzytelniające i tokeny, `App\Models\ProductCoupon` — istniejący, niezależny system kodów rabatowych), które nie są częścią tego repozytorium, ponieważ dotyczą całej platformy, a nie samej integracji z Allegro.

## Środowiska

Sandbox i produkcja Allegro to dwa osobne hosty i dwa osobne konta/aplikacje (nie tylko inny klucz) — przełączane niezależnie w panelu, z osobnymi tokenami dla każdego środowiska.
