<?php

namespace App\Services;

use App\Settings\ShopSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klient do Allegro REST API (https://developer.allegro.pl) — autoryzacja
 * urzadzenia bez przegladarki (OAuth2 Device Flow, typ aplikacji
 * "no-browser"), uzywana do automatycznego wykrywania zamowien Allegro i
 * tworzenia na ich podstawie kodow "Allegro" (App\Models\ProductCoupon) —
 * patrz App\Console\Commands\SyncAllegroOrders.
 *
 * Allegro NIE udostepnia webhookow na nowe zamowienia (stan na 2026-10,
 * patrz developer.allegro.pl/tutorials/jak-obslugiwac-zamowienia) - trzeba
 * cyklicznie odpytywac GET /order/events (kursor po ID ostatniego
 * przetworzonego zdarzenia, do 60 dni wstecz).
 *
 * Test i produkcja to dwa OSOBNE hosty (nie tylko osobny sekret jak w
 * Antigro) - sterowane przelacznikiem allegro_sandbox w Ustawieniach ->
 * Allegro w panelu. Token dostepu zyje 12h, token odswiezajacy ~3 miesiace
 * i jest JEDNORAZOWY (rotuje przy kazdym uzyciu) - KAZDE odswiezenie MUSI
 * zapisac nowy refresh_token z powrotem do ustawien, inaczej kolejne
 * odswiezenie juz sie nie uda.
 */
class AllegroClient
{
    private ShopSettings $settings;

    private bool $sandbox;

    private string $authHost;

    private string $apiHost;

    private string $clientId;

    private string $clientSecret;

    /**
     * $forceSandbox pozwala wymusic konkretne srodowisko niezaleznie od
     * biezacego przelacznika allegro_sandbox w ustawieniach - uzywane przez
     * AllegroSettings (panel), gdzie sekcje "Test"/"Produkcja" musza dzialac
     * niezaleznie od tego, ktory przelacznik jest akurat wlaczony. Test i
     * produkcja maja ZUPELNIE OSOBNE tokeny (osobne konta Allegro) - stad
     * settingKey() dobiera wlasciwe pole ustawien wg tego, ktore srodowisko
     * jest akurat aktywne dla TEJ instancji klienta.
     */
    public function __construct(?ShopSettings $settings = null, ?bool $forceSandbox = null)
    {
        $this->settings = $settings ?? app(ShopSettings::class);
        $this->sandbox  = $forceSandbox ?? $this->settings->allegro_sandbox;

        $this->authHost = $this->sandbox ? 'https://allegro.pl.allegrosandbox.pl' : 'https://allegro.pl';
        $this->apiHost  = $this->sandbox ? 'https://api.allegro.pl.allegrosandbox.pl' : 'https://api.allegro.pl';

        $this->clientId     = (string) ($this->sandbox ? $this->settings->allegro_client_id_sandbox : $this->settings->allegro_client_id);
        $this->clientSecret = (string) ($this->sandbox ? $this->settings->allegro_client_secret_sandbox : $this->settings->allegro_client_secret);
    }

    private function settingKey(string $base): string
    {
        return $this->sandbox ? $base . '_sandbox' : $base;
    }

    private function assertCredentials(): void
    {
        if (!$this->clientId || !$this->clientSecret) {
            throw new \RuntimeException('Brak client_id/client_secret Allegro — uzupełnij w panelu: Ustawienia → Allegro.');
        }
    }

    // --- OAuth2 Device Flow --------------------------------------------

    /**
     * Krok 1: uruchamia proces logowania urzadzenia. Zwraca m.in. user_code
     * i verification_uri_complete do pokazania wlascicielowi w panelu —
     * ma je wpisac/otworzyc i zatwierdzic dostep na stronie Allegro.
     * device_code trzeba zapamietac do kroku 2 (pollDeviceToken()).
     */
    public function requestDeviceCode(): array
    {
        $this->assertCredentials();

        $response = Http::asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post($this->authHost . '/auth/oauth/device', [
                'client_id' => $this->clientId,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Allegro: nie udało się rozpocząć logowania urządzenia — ' . $response->status() . ' ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Krok 2: sprawdza, czy wlasciciel juz zatwierdzil dostep w przegladarce.
     * Zwraca ['status' => 'pending'|'granted'|'error', ...]. Przy 'granted'
     * tokeny sa JUZ zapisane do ustawien (nie trzeba nic dalej robic).
     */
    public function pollDeviceToken(string $deviceCode): array
    {
        $this->assertCredentials();

        $response = Http::asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post($this->authHost . '/auth/oauth/token', [
                'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
                'device_code' => $deviceCode,
            ]);

        $body = $response->json() ?? [];

        if ($response->successful() && !empty($body['access_token'])) {
            $this->storeTokens($body);
            return ['status' => 'granted'];
        }

        // Allegro zwraca 400 z error=authorization_pending dopoki wlasciciel
        // nie zatwierdzil jeszcze dostepu w przegladarce - to NORMALNY,
        // oczekiwany stan przy pollowaniu, nie blad.
        if (($body['error'] ?? null) === 'authorization_pending') {
            return ['status' => 'pending'];
        }

        Log::warning('Allegro: device token poll nieudany', ['status' => $response->status(), 'body' => $body]);

        return ['status' => 'error', 'error' => $body['error'] ?? ('HTTP ' . $response->status())];
    }

    /**
     * Odswieza access_token (wazny 12h) przy uzyciu refresh_token. Refresh
     * token jest JEDNORAZOWY i ROTUJE — zapisujemy ZAWSZE nowa pare zaraz
     * po udanym odswiezeniu (patrz docblock klasy).
     */
    private function refreshAccessToken(): void
    {
        $this->assertCredentials();

        $refreshToken = $this->settings->{$this->settingKey('allegro_refresh_token')};

        if (!$refreshToken) {
            throw new \RuntimeException('Allegro: brak refresh_token — trzeba ponownie połączyć konto w panelu: Ustawienia → Allegro.');
        }

        $response = Http::asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post($this->authHost . '/auth/oauth/token', [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Allegro: odświeżenie tokenu nie powiodło się — ' . $response->status() . ' ' . $response->body() . '. Trzeba ponownie połączyć konto w panelu: Ustawienia → Allegro.');
        }

        $this->storeTokens($response->json());
    }

    private function storeTokens(array $body): void
    {
        $accessKey  = $this->settingKey('allegro_access_token');
        $refreshKey = $this->settingKey('allegro_refresh_token');
        $expiresKey = $this->settingKey('allegro_token_expires_at');

        $this->settings->{$accessKey}  = $body['access_token'] ?? null;
        $this->settings->{$refreshKey} = $body['refresh_token'] ?? $this->settings->{$refreshKey};
        $this->settings->{$expiresKey} = now()->addSeconds((int) ($body['expires_in'] ?? 3600))->toIso8601String();
        $this->settings->save();
    }

    /**
     * Woluje automatycznego odswiezenia PRZED kazdym zapytaniem do API, jesli
     * token wygasl albo wygasa w ciagu najblizszej minuty (margines na czas
     * samego zapytania) - polling command wywoluje to na poczatku kazdego
     * przebiegu.
     */
    public function ensureValidToken(): void
    {
        $accessToken = $this->settings->{$this->settingKey('allegro_access_token')};
        $expiresAt   = $this->settings->{$this->settingKey('allegro_token_expires_at')};

        if (!$accessToken || !$expiresAt || now()->addMinute()->greaterThan($expiresAt)) {
            $this->refreshAccessToken();
        }
    }

    // --- Order API -------------------------------------------------------

    private function request(string $method, string $path, array $query = []): array
    {
        $response = Http::withToken($this->settings->{$this->settingKey('allegro_access_token')})
            ->withHeaders(['Accept' => 'application/vnd.allegro.public.v1+json'])
            ->{$method}($this->apiHost . $path, $query);

        if ($response->failed()) {
            Log::error('Allegro API: żądanie nieudane', [
                'method' => $method,
                'path'   => $path,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException("Allegro API {$method} {$path} zwróciło {$response->status()}: " . $response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Zdarzenia zamowien (kursor po ID, nie czas) - do 1000 na strone, do 60
     * dni wstecz. $from = null przy PIERWSZYM uruchomieniu (zwraca najstarsze
     * dostepne - command od razu zapisuje kursor, zeby NIE przetwarzac calej
     * 60-dniowej historii przy kolejnych przebiegach).
     */
    public function getOrderEvents(?string $from = null, int $limit = 100): array
    {
        $query = ['limit' => $limit];
        if ($from) {
            $query['from'] = $from;
        }

        return $this->request('get', '/order/events', $query);
    }

    public function getCheckoutForm(string $id): array
    {
        return $this->request('get', '/order/checkout-forms/' . $id);
    }
}
