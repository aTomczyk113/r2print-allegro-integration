<?php

namespace App\Filament\Pages;

use App\Services\AllegroClient;
use App\Settings\ShopSettings;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * Polaczenie z Allegro (OAuth2 Device Flow, typ aplikacji "no-browser") do
 * automatycznego tworzenia kodow "Allegro" z oplaconych zamowien Allegro -
 * patrz App\Services\AllegroClient i App\Console\Commands\SyncAllegroOrders.
 * Mapowanie konkretnych ofert Allegro na produkty w sklepie: osobny ekran
 * (Filament\Resources\AllegroOfferMappings).
 *
 * Device Flow nie da sie dokonczyc w jednym zadaniu HTTP (wlasciciel musi
 * zdazyc zatwierdzic dostep na stronie Allegro miedzy krokiem 1 a 2) - kod
 * urzadzenia trzymamy w Cache (nie w ustawieniach - to stan TYMCZASOWY,
 * nie konfiguracja), osobno dla kazdego srodowiska.
 */
class AllegroSettings extends SettingsPage
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;
    protected static ?string $navigationLabel = 'Ustawienia';
    protected static string|UnitEnum|null $navigationGroup = 'Allegro';
    protected static ?string $title = 'Ustawienia Allegro';
    protected static ?int $navigationSort = 1;

    protected static string $settings = ShopSettings::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ogólne')
                ->description('Mapowanie konkretnych ofert Allegro na produkty w sklepie ustawia się osobno, w zakładce "Mapowanie ofert".')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->columns(2)
                ->schema([
                    Toggle::make('allegro_enabled')
                        ->label('Automatyczne tworzenie kodów z zamówień Allegro')
                        ->helperText('Włącz dopiero po podłączeniu konta i uzupełnieniu mapowania ofert — wcześniej nic się nie stanie, nawet jeśli włączysz.')
                        ->columnSpanFull(),
                    Toggle::make('allegro_sandbox')
                        ->label('Używaj środowiska testowego (Sandbox)')
                        ->onColor('warning')
                        ->default(true)
                        ->helperText('Włączone = sprawdzanie zamówień idzie przez sandbox Allegro, nic nie trafia na Twoje prawdziwe konto. Wyłącz dopiero po przetestowaniu całej ścieżki na sandboxie.')
                        ->columnSpanFull(),
                ]),

            Section::make('Środowisko testowe (Sandbox)')
                ->description('Osobne konto i osobna aplikacja niż produkcja — zarejestruj ją na https://apps.developer.allegro.pl.allegrosandbox.pl (typ aplikacji: "Aplikacja bez przeglądarki" / no-browser).')
                ->icon(Heroicon::OutlinedBeaker)
                ->columns(2)
                ->schema([
                    TextInput::make('allegro_client_id_sandbox')
                        ->label('Client ID (test)'),
                    TextInput::make('allegro_client_secret_sandbox')
                        ->label('Client Secret (test)')
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password'),
                    Placeholder::make('status_sandbox')
                        ->label('Status połączenia')
                        ->content(fn () => $this->connectionStatus(true))
                        ->columnSpanFull(),
                    Actions::make([
                        Action::make('connect_sandbox')
                            ->label('Połącz konto (test)')
                            ->icon(Heroicon::OutlinedLink)
                            ->color('gray')
                            ->action(fn () => $this->startDeviceFlow(true)),
                        Action::make('check_sandbox')
                            ->label('Sprawdź, czy zatwierdzono')
                            ->icon(Heroicon::OutlinedArrowPath)
                            ->color('gray')
                            ->action(fn () => $this->checkDeviceFlow(true)),
                    ]),
                ]),

            Section::make('Środowisko produkcyjne')
                ->description('Zarejestruj aplikację na https://apps.developer.allegro.pl (typ aplikacji: "Aplikacja bez przeglądarki" / no-browser). Konto Allegro musi mieć włączone uwierzytelnianie dwuskładnikowe (2FA).')
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->columns(2)
                ->schema([
                    TextInput::make('allegro_client_id')
                        ->label('Client ID (produkcja)'),
                    TextInput::make('allegro_client_secret')
                        ->label('Client Secret (produkcja)')
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password'),
                    Placeholder::make('status_production')
                        ->label('Status połączenia')
                        ->content(fn () => $this->connectionStatus(false))
                        ->columnSpanFull(),
                    Actions::make([
                        Action::make('connect_production')
                            ->label('Połącz konto (produkcja)')
                            ->icon(Heroicon::OutlinedLink)
                            ->color('gray')
                            ->requiresConfirmation()
                            ->modalDescription('To połączy Twoje PRAWDZIWE konto Allegro. Upewnij się, że wszystko działa najpierw na środowisku testowym.')
                            ->action(fn () => $this->startDeviceFlow(false)),
                        Action::make('check_production')
                            ->label('Sprawdź, czy zatwierdzono')
                            ->icon(Heroicon::OutlinedArrowPath)
                            ->color('gray')
                            ->action(fn () => $this->checkDeviceFlow(false)),
                    ]),
                ]),
        ]);
    }

    private function cacheKey(bool $sandbox): string
    {
        return 'allegro_device_flow_' . ($sandbox ? 'sandbox' : 'production');
    }

    private function connectionStatus(bool $sandbox): string
    {
        $settings = app(ShopSettings::class);
        $refreshToken = $sandbox ? $settings->allegro_refresh_token_sandbox : $settings->allegro_refresh_token;
        $expiresAt    = $sandbox ? $settings->allegro_token_expires_at_sandbox : $settings->allegro_token_expires_at;

        if (!$refreshToken) {
            return '❌ Nie połączono.';
        }

        return '✅ Połączono. Token dostępu ważny do: ' . ($expiresAt ? \Illuminate\Support\Carbon::parse($expiresAt)->format('Y-m-d H:i') : '—') . ' (odświeża się automatycznie).';
    }

    private function startDeviceFlow(bool $sandbox): void
    {
        try {
            $client = new AllegroClient(forceSandbox: $sandbox);
            $result = $client->requestDeviceCode();
        } catch (\Throwable $e) {
            Notification::make()->title('Błąd')->body($e->getMessage())->danger()->send();
            return;
        }

        Cache::put($this->cacheKey($sandbox), $result, now()->addSeconds((int) ($result['expires_in'] ?? 600)));

        Notification::make()
            ->title('Krok 1/2: zatwierdź dostęp na stronie Allegro')
            ->body('Kod: ' . ($result['user_code'] ?? '—') . ' — otwórz stronę poniżej, zaloguj się na Allegro i zatwierdź dostęp, a potem wróć tutaj i kliknij "Sprawdź, czy zatwierdzono".')
            ->actions([
                Action::make('open')
                    ->label('Otwórz stronę Allegro')
                    ->url($result['verification_uri_complete'] ?? $result['verification_uri'] ?? '#')
                    ->openUrlInNewTab(),
            ])
            ->persistent()
            ->warning()
            ->send();
    }

    private function checkDeviceFlow(bool $sandbox): void
    {
        $pending = Cache::get($this->cacheKey($sandbox));

        if (!$pending || empty($pending['device_code'])) {
            Notification::make()->title('Najpierw kliknij "Połącz konto"')->warning()->send();
            return;
        }

        try {
            $client = new AllegroClient(forceSandbox: $sandbox);
            $result = $client->pollDeviceToken($pending['device_code']);
        } catch (\Throwable $e) {
            Notification::make()->title('Błąd')->body($e->getMessage())->danger()->send();
            return;
        }

        if ($result['status'] === 'granted') {
            Cache::forget($this->cacheKey($sandbox));
            Notification::make()->title('Połączono z Allegro!')->success()->send();
            return;
        }

        if ($result['status'] === 'pending') {
            Notification::make()->title('Jeszcze nie zatwierdzono')->body('Zatwierdź dostęp na stronie Allegro, a potem spróbuj ponownie.')->warning()->send();
            return;
        }

        Cache::forget($this->cacheKey($sandbox));
        Notification::make()->title('Nie udało się połączyć')->body($result['error'] ?? 'Nieznany błąd.')->danger()->send();
    }
}
