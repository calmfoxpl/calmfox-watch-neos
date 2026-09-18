<?php

declare(strict_types=1);

namespace Calmfox\Watch\Service;

use Calmfox\Watch\Core\SimpleHttp;
use Neos\Flow\Annotations as Flow;

/**
 * Rozmowa z API Calmfox Watch. Podczas register i pair hub wykonuje challenge:
 * pobiera nasz adres kontrolny na domenie strony i oczekuje echa nonce'a. To
 * dowód, że pakiet naprawdę działa na tej domenie (token instalacji jest jawny,
 * więc sam z siebie niczego nie dowodzi).
 *
 * Stąd kolejność, której nie wolno odwrócić: nonce zapisujemy PRZED wysłaniem
 * żądania, bo hub odpyta nas w trakcie jego obsługi, i kasujemy dopiero po
 * odpowiedzi.
 */
#[Flow\Scope('singleton')]
class HubClient
{
    /** Hub w trakcie obsługi robi challenge na naszą stronę, więc czekamy dłużej niż zwykle. */
    private const CALL_TIMEOUT = 25.0;

    /**
     * Ocena to zwykłe pytanie o gotową liczbę, bez challenge'u — a czeka na nią ekran
     * modułu, który ma się otworzyć od razu. Dłuższe czekanie zamieniłoby ciszę huba
     * w zawieszony panel.
     */
    private const SCORE_TIMEOUT = 8.0;

    #[Flow\InjectConfiguration(path: 'apiUrl', package: 'Calmfox.Watch')]
    protected string $configuredApiUrl = '';

    #[Flow\InjectConfiguration(path: 'healthPath', package: 'Calmfox.Watch')]
    protected string $healthPath = '';

    #[Flow\Inject]
    protected StateProvider $stateProvider;

    #[Flow\Inject]
    protected SiteUrl $siteUrl;

    /** Adres API. Zmienna środowiskowa wygrywa, bo staging i produkcja dzielą ten sam plik ustawień. */
    public function apiUrl(): string
    {
        $fromEnvironment = trim((string) (getenv('CALMFOX_WATCH_API_URL') ?: ''));
        $url = rtrim('' !== $fromEnvironment ? $fromEnvironment : trim($this->configuredApiUrl), '/');

        // Przeprowadzka panelu na watch.calmfox.net. Adres bywa wpisany w Settings.yaml
        // instalacji z czasów parowania i zmiana wartości domyślnej go nie rusza. Stary
        // host oddaje 308, więc podmiana nie naprawia awarii — zdejmuje skok przy każdym
        // żądaniu i adres, którego już nie używamy, z modułu w backendzie.
        if ('https://watch.calmfox.pl' === $url) {
            $url = '';
        }

        return rtrim('' !== $url ? $url : 'https://watch.calmfox.net', '/');
    }

    public function healthUrl(): string
    {
        $path = '' !== trim($this->healthPath) ? trim($this->healthPath) : '/calmfox-watch/health';

        return $this->siteUrl->baseUri().'/'.ltrim($path, '/').'?key='.$this->stateProvider->state()->ensureSecret();
    }

    /**
     * Aktywacja pakietu Free wprost z pakietu: konto, strona i monitoring po
     * stronie huba, a na podany adres leci link logowania.
     *
     * @return array{ok: bool, message: string, data: array<string, mixed>}
     */
    public function register(string $email): array
    {
        $state = $this->stateProvider->state();
        $result = $this->call('/api/public/plugin/register', [
            'domain' => $this->siteUrl->domain(),
            'email' => $email,
            'healthUrl' => $this->healthUrl(),
            'nonce' => $state->makePairingNonce(),
            'cms' => 'Neos',
        ], 201);
        $state->clearPairingNonce();

        if ($result['ok']) {
            $this->savePaired($result['data']);
            $result['message'] = 'Konto Free jest aktywne. Sprawdź skrzynkę, wysłaliśmy link logowania do panelu.';
        }

        return $result;
    }

    /**
     * Parowanie z istniejącą stroną kluczem instalacyjnym z ekranu Integracje.
     * Tą samą drogą idzie wymiana sekretu: hub podmienia zapamiętany adres.
     *
     * @return array{ok: bool, message: string, data: array<string, mixed>}
     */
    public function pair(string $token = ''): array
    {
        $state = $this->stateProvider->state();
        $token = '' !== trim($token) ? trim($token) : (string) $state->get('installToken', '');
        if ('' === $token) {
            return ['ok' => false, 'data' => [], 'message' => 'Brak klucza instalacyjnego. Skopiuj go z ekranu Integracje w panelu.'];
        }

        $result = $this->call('/api/public/plugin/pair', [
            'token' => $token,
            'healthUrl' => $this->healthUrl(),
            'nonce' => $state->makePairingNonce(),
            'cms' => 'Neos',
        ], 200);
        $state->clearPairingNonce();

        if ($result['ok']) {
            $this->savePaired($result['data']);
            $result['message'] = 'Połączono z Calmfox Watch, monitoring wnętrza Neosa działa.';
        }

        return $result;
    }

    /**
     * Ocena kondycji strony policzona przez hub (0-100 z pięciu obszarów).
     *
     * To JEDYNE miejsce, w którym pakiet pyta hub o coś dla siebie: reszta kontraktu
     * jest pull, ale ocena bierze pod uwagę uptime, przeglądy podstron i pomiary
     * wydajności, o których ta instalacja nie ma pojęcia. Buforuje ScoreProvider.
     *
     * @return array{ok: bool, message: string, data: array<string, mixed>}
     */
    public function score(): array
    {
        $token = (string) $this->stateProvider->state()->get('installToken', '');
        if ('' === $token) {
            return ['ok' => false, 'message' => 'Strona nie jest połączona z Calmfox Watch.', 'data' => []];
        }

        return $this->call('/api/public/plugin/score', ['token' => $token], 200, self::SCORE_TIMEOUT);
    }

    /**
     * Rozłączenie. Sam klucz instalacyjny jest jawny, więc hub żąda też sekretu
     * z adresu kontrolnego: zna go wyłącznie ta instalacja. Robimy to najlepszym
     * staraniem, bo przy braku sieci hub i tak zauważy milczący adres.
     */
    public function disconnect(): void
    {
        $state = $this->stateProvider->state();
        $token = (string) $state->get('installToken', '');
        if ('' === $token) {
            return;
        }
        SimpleHttp::request(
            'POST',
            $this->apiUrl().'/api/public/plugin/disconnect',
            (string) json_encode(['token' => $token, 'key' => $state->secret()]),
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            8.0
        );
    }

    /**
     * Samokontrola adresu kontrolnego pętlą zwrotną. Wyłapuje zaporę sieciową
     * i reguły serwera tnące nietypowe ścieżki, zanim klient utknie na parowaniu.
     * Uczciwie: to sprawdzenie od środka serwera, dostęp z zewnątrz weryfikuje
     * dopiero challenge huba.
     *
     * @return array{ok: bool, message: string}
     */
    public function loopbackCheck(): array
    {
        $response = SimpleHttp::request('GET', $this->healthUrl(), null, ['Accept' => 'application/json'], 5.0);
        if ('' !== $response['error']) {
            return ['ok' => false, 'message' => $response['error']];
        }
        $body = json_decode($response['body'], true);
        if (\in_array($response['status'], [200, 503], true) && \is_array($body) && isset($body['status'])) {
            return ['ok' => true, 'message' => ''];
        }

        return ['ok' => false, 'message' => sprintf('adres kontrolny odpowiada kodem %d albo obcym formatem', $response['status'])];
    }

    /** @param array<string, mixed> $data */
    private function savePaired(array $data): void
    {
        $state = $this->stateProvider->state();
        $panel = (string) ($data['panelUrl'] ?? '');
        $state->update([
            'connected' => true,
            'installToken' => (string) ($data['installToken'] ?? $state->get('installToken', '')),
            'siteId' => (string) ($data['siteId'] ?? ''),
            'plan' => (string) ($data['plan'] ?? ''),
            'panelUrl' => '' !== $panel ? $panel : (string) $state->get('panelUrl', ''),
            'pairedAt' => gmdate('c'),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{ok: bool, message: string, data: array<string, mixed>}
     */
    private function call(string $path, array $body, int $expected, ?float $timeout = null): array
    {
        $response = SimpleHttp::request(
            'POST',
            $this->apiUrl().$path,
            (string) json_encode($body),
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            $timeout ?? self::CALL_TIMEOUT
        );
        if ('' !== $response['error']) {
            return ['ok' => false, 'data' => [], 'message' => 'Nie udało się połączyć z Calmfox Watch: '.$response['error']];
        }

        $data = json_decode($response['body'], true);
        $data = \is_array($data) ? $data : [];
        if ($response['status'] === $expected) {
            return ['ok' => true, 'data' => $data, 'message' => ''];
        }

        foreach (['detail', 'message', 'error'] as $key) {
            if (!empty($data[$key]) && \is_string($data[$key])) {
                return ['ok' => false, 'data' => [], 'message' => $data[$key]];
            }
        }

        return ['ok' => false, 'data' => [], 'message' => sprintf('Serwer Calmfox Watch odpowiedział kodem %d.', $response['status'])];
    }
}
