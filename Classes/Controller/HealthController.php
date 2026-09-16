<?php

declare(strict_types=1);

namespace Calmfox\Watch\Controller;

use Calmfox\Watch\Core\ResponseSigner;
use Calmfox\Watch\Service\PayloadProvider;
use Calmfox\Watch\Service\StateProvider;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;

/**
 * Sekretny adres kontrolny: GET /calmfox-watch/health?key=<sekret>
 *
 * Kontrakt z hubem: 200 = ok albo warn, 503 = fail, nic innego. Sekcja
 * bezpieczeństwa przez ?section=security. Bez ważnego klucza suche 403,
 * bez żadnej podpowiedzi, co jest po drugiej stronie.
 *
 * Odpowiedź składamy sami, bajt po bajcie: akcja zwraca gotowy łańcuch znaków,
 * a Flow ustawia go jako treść odpowiedzi z pominięciem widoku (widok renderuje
 * się wyłącznie wtedy, gdy akcja nie zwróci nic). Podpis HMAC musi obejmować DOKŁADNIE
 * te bajty, które wyjdą na łącze, a hub liczy go nad treścią, którą dostał.
 * Gdybyśmy zostawili składanie JSON-a frameworkowi, każda zmiana w jego
 * serializacji zamieniałaby nasz dowód świeżości w fałszywy alarm o podmianie
 * treści.
 */
class HealthController extends ActionController
{
    /**
     * Hub odpytuje nas z nagłówkiem „Accept: application/json", a ActionController
     * domyślnie obsługuje wyłącznie text/html i na taki nagłówek odpowiada 406.
     * Bez tej deklaracji monitoring dostawał kod 406 zamiast danych, czyli stronę
     * uznawał za milczącą (sprawdzone na żywej instalacji Neos 9.1.5).
     * text/html zostaje, żeby ten sam adres dało się otworzyć w przeglądarce.
     *
     * @var array<int, string>
     */
    protected $supportedMediaTypes = ['application/json', 'text/html'];

    #[Flow\Inject]
    protected StateProvider $stateProvider;

    #[Flow\Inject]
    protected PayloadProvider $payloadProvider;

    public function indexAction(string $key = '', string $section = '', string $nonce = ''): string
    {
        $state = $this->stateProvider->state();
        if (!$state->keyIsValid($key)) {
            // Suche 403 bez podpisu: komuś bez klucza nie potwierdzamy nawet tego,
            // że pakiet tu jest.
            return $this->emit(['error' => 'forbidden'], 403, '', '');
        }

        try {
            $state->touchLastPoll();
        } catch (\Throwable) {
            // Niezapisywalny plik stanu odbiera nam tylko znacznik ostatniego
            // odpytania. Sama odpowiedź jest ważniejsza.
        }

        try {
            $payload = 'security' === $section
                ? $this->payloadProvider->security()
                : $this->payloadProvider->health();
            $payload = $this->payloadProvider->withPairing($payload);
        } catch (\Throwable $exception) {
            // Nawet całkowita awaria budowy payloadu ma skończyć się uczciwą
            // odpowiedzią w kontrakcie, a nie stroną błędu frameworka: hub
            // rozpozna 503 i status „fail", a nie obcy format.
            $payload = [
                'schema' => 1,
                'status' => 'fail',
                'checks' => [[
                    'id' => 'check_error',
                    'status' => 'fail',
                    'label' => 'Sprawdzenia nie dały się wykonać',
                    'detail' => mb_substr($exception->getMessage(), 0, 300),
                    'ms' => null,
                ]],
            ];
        }

        return $this->emit($payload, 'fail' === ($payload['status'] ?? 'fail') ? 503 : 200, $nonce, $state->secret());
    }

    /**
     * Akcja zwraca gotowy łańcuch znaków, a Flow ustawia go jako treść odpowiedzi
     * bez udziału warstwy widoku. To celowe: między json_encode a łączem nie
     * stoi żaden renderer, więc podpisujemy dokładnie to, co dostanie hub.
     *
     * @param array<string, mixed> $payload
     */
    private function emit(array $payload, int $status, string $nonce, string $secret): string
    {
        $body = (string) json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        $this->response->setStatusCode($status);
        $this->response->setHttpHeader('Content-Type', 'application/json; charset=utf-8');
        // To kanał danych, nie podstrona: żadnego pośredniczącego zapisu
        // w pamięci podręcznej i żadnego indeksowania.
        $this->response->setHttpHeader('Cache-Control', 'no-store, max-age=0');
        $this->response->setHttpHeader('X-Robots-Tag', 'noindex, nofollow');

        if ('' !== $nonce && '' !== $secret) {
            $generatedAt = ResponseSigner::generatedAt();
            $this->response->setHttpHeader(ResponseSigner::HEADER_GENERATED_AT, $generatedAt);
            $this->response->setHttpHeader(ResponseSigner::HEADER_PROOF, ResponseSigner::proof($nonce, $generatedAt, $body, $secret));
        }

        return $body;
    }
}
