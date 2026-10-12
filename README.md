# R2print — integracja z Allegro REST API

Dokumentacja i kod źródłowy integracji wewnętrznego **panelu firmowego R2print** (drukarnia) z [Allegro REST API](https://developer.allegro.pl), przygotowane na potrzeby weryfikacji aplikacji produkcyjnej w panelu [apps.developer.allegro.pl](https://apps.developer.allegro.pl).

Panel firmowy obsługuje całość działalności firmy — sklep internetowy **[r2print.pl](https://r2print.pl)** to jedna z jego części, Allegro to kolejna, osobna część tego samego panelu (sprzedaż na Allegro i sprzedaż w sklepie internetowym to dwa niezależne kanały obsługiwane z jednego miejsca). To repozytorium zawiera **wyłącznie fragment kodu odpowiedzialny za integrację z Allegro**, wyodrębniony z prywatnego repozytorium całego panelu (Laravel 13 + Filament 5). Reszta platformy (sklep, katalog produktów, płatności, konfiguratory druku itd.) pozostaje prywatna.

## Do czego służy ta aplikacja

Firma sprzedaje część swoich produktów (ulotki, naklejki, kalendarze, wizytówki itd.) również jako aukcje na Allegro, równolegle do sprzedaży w sklepie internetowym. Po stronie Allegro nie ma webhooków na nowe zamówienia, więc aplikacja **cyklicznie odpytuje Allegro o nowe, opłacone zamówienia** i dla każdego z nich automatycznie generuje wewnętrzny kod rabatowy, wysyłany mailem do kupującego — kod pozwala mu zaprojektować i zamówić swój produkt bezpośrednio w sklepie internetowym firmy, bez dodatkowej płatności (już opłacił przez Allegro). To jedyne miejsce, gdzie ten moduł integracji dotyka sklepu — poza tym działa niezależnie.

Przebieg:
1. Zadanie cykliczne (co 5 minut, `allegro:sync-orders`) odpytuje `GET /order/events`.
2. Dla każdego nowego zdarzenia typu `READY_FOR_PROCESSING` (zamówienie opłacone) pobiera szczegóły przez `GET /order/checkout-forms/{id}`.
3. Na podstawie wcześniej skonfigurowanego mapowania oferta Allegro → produkt w sklepie, tworzy kod rabatowy i wysyła go mailem na adres kupującego (pobrany z danych zamówienia).
4. Zapamiętuje ID przetworzonego zamówienia, żeby nie wygenerować kodu dwa razy dla tego samego zamówienia.

Panel firmowy (Filament) pozwala właścicielowi: połączyć konto Allegro (OAuth2 Device Flow), zarządzać mapowaniem ofert na produkty oraz włączać/wyłączać automatyzację.

To jest **żywy, rozwijany moduł back-office** do obsługi sprzedaży na Allegro w ramach panelu firmowego, nie jednorazowy skrypt — integracja z API Allegro jest rozbudowywana sukcesywnie w miarę kolejnych potrzeb firmy. Obecnie (stan opisany wyżej) działa wyłącznie odczyt zamówień. W bezpośrednim planie rozwoju, jako osobny moduł obsługi Allegro w panelu firmowym (niezależny od sklepu):

- **Drukowanie etykiet nadawczych i zlecanie odbioru kuriera** bezpośrednio z panelu firmowego, bez przełączania się na stronę Allegro, dla zamówień realizowanych przez Allegro.
- **Zarządzanie statusem zamówienia** z panelu: numer śledzenia, oznaczanie jako wysłane.
- **Zarządzanie ofertami Allegro** (tworzenie/edycja/publikacja) jako osobny moduł panelu firmowego, zamiast osobno na stronie Allegro.
- **Obsługa wiadomości, ocen i sporów** z kupującymi z poziomu jednego, wspólnego panelu firmowego, zamiast osobno na Allegro.

## Jakich uprawnień (scope) używa / o jakie wnioskuje

| Scope | Status | Zastosowanie |
|---|---|---|
| `allegro:api:orders:read` | **Używane dziś** | `GET /order/events` + `GET /order/checkout-forms/{id}` — jedyne wywołania w aktualnie działającej automatyzacji (patrz wyżej). |
| `allegro:api:orders:write` | Planowane | Numer śledzenia, status zamówienia, tworzenie/usuwanie przesyłki z poziomu panelu firmowego. |
| `allegro:api:shipments:read` | Planowane | Odczyt danych przesyłek i etykiet nadawczych. |
| `allegro:api:shipments:write` | Planowane | Tworzenie przesyłki i zlecanie odbioru kuriera — drukowanie etykiety bez wychodzenia z panelu firmowego. |
| `allegro:api:sale:offers:read` / `:write` | Planowane | Zarządzanie ofertami Allegro jako osobny moduł panelu firmowego. |
| `allegro:api:sale:settings:read` / `:write` | Planowane | Cenniki dostaw i polityki zwrotów Allegro, zarządzane z panelu firmowego. |
| `allegro:api:profile:read` / `:write` | Planowane | Podgląd statystyk/ocen konta sprzedawcy w panelu firmowym. |
| `allegro:api:ratings` | Planowane | Obsługa ocen kupujących z poziomu panelu firmowego. |
| `allegro:api:disputes` | Planowane | Obsługa sporów transakcyjnych z poziomu panelu firmowego. |
| `allegro:api:messaging` | Planowane | Obsługa wiadomości z kupującymi z poziomu panelu firmowego. |
| `allegro:api:billing:read` | Planowane | Podgląd rozliczeń Allegro w panelu firmowym. |
| `allegro:api:payments:read` / `:write` | Planowane | Podgląd płatności i obsługa zwrotów z poziomu panelu firmowego. |
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

Kod korzysta z wewnętrznych klas panelu firmowego (`App\Settings\ShopSettings` — przechowuje dane uwierzytelniające i tokeny, `App\Models\ProductCoupon` — istniejący, niezależny system kodów rabatowych), które nie są częścią tego repozytorium, ponieważ dotyczą całej platformy, a nie samej integracji z Allegro.

## Środowiska

Sandbox i produkcja Allegro to dwa osobne hosty i dwa osobne konta/aplikacje (nie tylko inny klucz) — przełączane niezależnie w panelu, z osobnymi tokenami dla każdego środowiska.
