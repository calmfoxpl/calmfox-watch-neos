<?php

declare(strict_types=1);

namespace Calmfox\Watch\Controller\Module;

use Calmfox\Watch\Core\ScoreRing;
use Calmfox\Watch\Core\StatusSummary;
use Calmfox\Watch\Core\Version;
use Calmfox\Watch\Service\HubClient;
use Calmfox\Watch\Service\PayloadProvider;
use Calmfox\Watch\Service\ScoreProvider;
use Calmfox\Watch\Service\SiteUrl;
use Calmfox\Watch\Service\StateProvider;
use Calmfox\Watch\Service\UpdateCounter;
use Calmfox\Watch\Service\VersionHistory;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Utility\Environment;
use Neos\Neos\Controller\Module\AbstractModuleController;

/**
 * Moduł w panelu Neosa. Dwie ścieżki startu, tak samo jak we wtyczce
 * WordPressa: aktywacja pakietu Free wprost stąd albo połączenie kluczem
 * instalacyjnym z panelu Calmfox Watch. Po połączeniu widok stanu, podgląd
 * obu sekcji sprawdzeń, historia zmian wersji i wymiana klucza.
 *
 * Samokontrola adresu kontrolnego jest pokazywana PRZED próbą połączenia,
 * bo najczęstszym powodem nieudanego parowania nie jest zły klucz, tylko
 * reguła serwera albo zapora sieciowa blokująca nietypową ścieżkę.
 */
class WatchController extends AbstractModuleController
{
    #[Flow\Inject]
    protected StateProvider $stateProvider;

    #[Flow\Inject]
    protected HubClient $hubClient;

    #[Flow\Inject]
    protected PayloadProvider $payloadProvider;

    #[Flow\Inject]
    protected ScoreProvider $scoreProvider;

    #[Flow\Inject]
    protected VersionHistory $versionHistory;

    #[Flow\Inject]
    protected SiteUrl $siteUrl;

    #[Flow\Inject]
    protected UpdateCounter $updateCounter;

    #[Flow\Inject]
    protected Environment $environment;

    public function indexAction(): void
    {
        // Powrót z panelu czytamy z SUROWYCH parametrów żądania, nie z argumentów
        // akcji: panel odsyła zwykłe ?cw_token=…&cw_state=…, a moduły Neosa
        // mapują na argumenty wyłącznie parametry ze swojej przestrzeni nazw.
        // Te same nazwy co we wtyczce WordPressa, bo panel jest jeden.
        $query = $this->queryParams();
        if (isset($query['cw_token']) || isset($query['cw_state'])) {
            $this->finishConnect((string) ($query['cw_token'] ?? ''), (string) ($query['cw_state'] ?? ''));
        }

        $state = $this->stateProvider->state();
        $connected = (bool) $state->get('connected');

        $this->view->assignMultiple([
            'version' => Version::NUMBER,
            'connected' => $connected,
            'domain' => $this->siteUrl->domain(),
            'secure' => $this->siteUrl->isHttps(),
            'apiUrl' => $this->hubClient->apiUrl(),
            'panelUrl' => rtrim((string) $state->get('panelUrl', ''), '/'),
            'siteId' => (string) $state->get('siteId', ''),
            'plan' => (string) $state->get('plan', ''),
            // Pakiet z chwili połączenia. Na Free ekran zaprasza wyżej, ale mówi wprost,
            // co Free obejmuje: obietnica bez granicy jest gorsza niż brak zaproszenia.
            'isFreePlan' => '' === trim((string) $state->get('plan', '')) || 'free' === mb_strtolower(trim((string) $state->get('plan', ''))),
            'planUrl' => $this->panelUrl().'/app/plan'.('' !== (string) $state->get('siteId', '') ? '?site='.rawurlencode((string) $state->get('siteId', '')) : ''),
            'lastPollAt' => (int) $state->get('lastPollAt', 0),
            'diskQuotaGb' => (float) $state->get('diskQuotaGb', 0.0),
            'healthUrl' => $this->hubClient->healthUrl(),
            'loopback' => $this->hubClient->loopbackCheck(),
            'updates' => $state->updates(),
            'updatesStale' => $this->updatesAreStale($state->updates()),
            // Stany kafli liczymy TUTAJ, a nie zagnieżdżonymi warunkami w szablonie:
            // Fluid z warunkiem w atrybucie klasy jest kruchy i cicho renderuje pustkę.
            'connectionState' => $connected ? 'ok' : 'warn',
            'contextState' => $this->environment->getContext()->isProduction() ? 'ok' : 'fail',
            'updatesState' => null === $state->updates() || $this->updatesAreStale($state->updates()) ? 'warn' : 'ok',
            'connectUrl' => $this->connectUrlAvailable(),
            // Tryb pracy aplikacji na wierzchu, nie schowany w sekcji bezpieczeństwa:
            // strona na produkcji w trybie Development działa wolniej i pokazuje
            // odwiedzającym ślad wykonania, a po samym wyglądzie tego nie widać.
            'context' => (string) $this->environment->getContext(),
            'production' => $this->environment->getContext()->isProduction(),
            // Powitanie po powrocie z panelu. Znacznik w adresie jest jawny i nic
            // nie odblokowuje, więc może spokojnie zostać w historii przeglądarki.
            'justConnected' => 'connected' === ($this->queryParams()['cw'] ?? ''),
        ]);

        if ($connected) {
            $health = $this->payloadProvider->health();
            $security = $this->payloadProvider->security();
            $score = $this->scoreProvider->score();
            $this->view->assignMultiple([
                'health' => $health,
                'security' => $security,
                'history' => \array_slice($this->versionHistory->all(), 0, 10),
                // Trzy liczby zamiast zdania, tak samo jak na kafelkach pozostałych pakietów.
                'summary' => StatusSummary::of($health, $security),
                'score' => $score,
                'ring' => $this->ringForView($score),
                // Tor bez wypełnienia stoi tam, gdzie oceny nie ma (Free albo jeszcze nie
                // policzona): pokazuje KSZTAŁT tego, co wchodzi od progu Start, i nie udaje
                // pomiaru — żadnej liczby o stanie strony przy nim nie ma.
                'ringPlaceholder' => ScoreRing::placeholder(),
                'ringBox' => ScoreRing::BOX,
                'ringWidth' => ScoreRing::WIDTH,
                'ringTrack' => ScoreRing::TRACK_COLOR,
                'scoreTone' => null !== $score ? (string) ($score['tone'] ?? 'muted') : 'muted',
                // Podpis rysunku dla czytnika ekranu składamy tutaj: Fluid nie umie wstawić
                // argumentu w atrybut bez wywołania w wywołaniu, a pierścień bez podpisu
                // jest dla czytnika pustym obrazkiem.
                'scoreAria' => null !== $score ? sprintf('Kondycja strony: %s na 100.', (string) ($score['overall'] ?? '')) : '',
            ]);
        }
    }

    /**
     * Łączenie przez panel Calmfox Watch, ta sama droga co we wtyczce WordPressa:
     * wychodzimy do panelu, tam użytkownik loguje się albo zakłada konto i wybiera
     * organizację, a wracamy tutaj z kluczem instalacyjnym i parujemy się sami.
     *
     * Znacznik jednorazowy powstaje PRZED wyjściem i jest jedynym dowodem, że
     * powrót jest odpowiedzią na to konkretne kliknięcie. Bez niego wystarczyłoby
     * podrzucić administratorowi link z cudzym kluczem, żeby podpiąć tę stronę
     * pod obce konto.
     */
    public function connectAction(): void
    {
        $state = $this->stateProvider->state();
        $return = $this->moduleUri();
        if ('' === $return) {
            $this->addFlashMessage('Nie znam adresu tego ekranu, więc nie mam dokąd wrócić z panelu. Połącz kluczem instalacyjnym z ekranu Integracje.', '', Message::SEVERITY_ERROR);
            $this->redirect('index');
        }

        $query = http_build_query([
            'domain' => $this->siteUrl->domain(),
            'return' => $return,
            'state' => $state->makeConnectState(),
        ]);

        // Cel jest na naszym panelu, nie na tej stronie, więc świadomie wychodzimy
        // poza aplikację (Flow domyślnie puszcza tylko przekierowania wewnętrzne).
        $this->redirectToUri($this->panelUrl().'/polacz/neos?'.$query);
    }

    /**
     * Powrót z panelu. Kolejność sprawdzeń nie jest przypadkowa: najpierw znacznik
     * (jednorazowy, zużywany niezależnie od wyniku), potem format klucza, dopiero
     * na końcu rozmowa z hubem. Klucz jest jawny, więc to znacznik decyduje, czy
     * ten powrót w ogóle nas dotyczy.
     */
    private function finishConnect(string $token, string $state): void
    {
        if (!$this->stateProvider->state()->consumeConnectState(trim($state))) {
            $this->addFlashMessage('Znacznik połączenia wygasł albo się nie zgadza. Kliknij „Połącz przez Calmfox Watch” jeszcze raz.', '', Message::SEVERITY_ERROR);
            $this->redirect('index');
        }
        if (1 !== preg_match('/^(fxp_live_)?[a-f0-9]{16,32}$/', trim($token))) {
            $this->addFlashMessage('Klucz z panelu ma nieoczekiwany format, spróbuj połączyć jeszcze raz.', '', Message::SEVERITY_ERROR);
            $this->redirect('index');
        }

        $result = $this->hubClient->pair(trim($token));
        $this->addFlashMessage($result['message'], '', $result['ok'] ? Message::SEVERITY_OK : Message::SEVERITY_ERROR);

        // Świadomie NIE redirect('index'): wracamy pod jawny adres tego ekranu.
        // Żądanie przyszło z zewnątrz, zwykłym GET-em bez kontekstu modułu, więc
        // budowanie adresu przez router wyrzucało użytkownika na stronę główną
        // panelu (zgłoszone z produkcji). Przy okazji ucinamy klucz z paska adresu,
        // żeby nie został w historii przeglądarki ani w logu serwera.
        $this->redirectToUri($this->moduleUri().($result['ok'] ? '?cw=connected' : ''));
    }

    /** @return array<string, mixed> */
    private function queryParams(): array
    {
        try {
            return $this->request->getMainRequest()->getHttpRequest()->getQueryParams();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Sprawdzenie zaległych aktualizacji na żądanie administratora. Normalnie robi
     * to zadanie cykliczne (./flow calmfoxwatch:updates), bo polecenie wychodzi
     * do sieci i potrafi potrwać. Przycisk jest dla tego jednego przypadku, kiedy
     * ktoś właśnie skończył aktualizować i chce zobaczyć wynik od razu.
     */
    public function checkUpdatesAction(): void
    {
        $result = $this->updateCounter->refresh();
        $this->addFlashMessage(
            $result['ok']
                ? ($result['core'] + $result['plugins'] > 0
                    ? sprintf('Nowsze wersje ma %d z bezpośrednio wymaganych pakietów. Aktualizuj przez Composera, tak samo jak przy wdrożeniu.', $result['core'] + $result['plugins'])
                    : 'Wszystkie bezpośrednio wymagane pakiety są aktualne.')
                : $result['message'],
            '',
            $result['ok'] ? Message::SEVERITY_OK : Message::SEVERITY_WARNING
        );
        $this->redirect('index');
    }

    /**
     * Czy odczyt aktualizacji zdążył się zestarzeć. Tydzień, bo tyle wystarczy,
     * żeby wyjść z poprawki bezpieczeństwa, a jednocześnie nie robimy alarmu
     * z tego, że cron nie chodził przez weekend.
     *
     * @param array{core: int, plugins: int, themes: int, at: string}|null $updates
     */
    private function updatesAreStale(?array $updates): bool
    {
        if (null === $updates) {
            return true;
        }
        try {
            $at = new \DateTimeImmutable($updates['at']);
        } catch (\Exception) {
            return true;
        }

        return $at < new \DateTimeImmutable('-7 days');
    }

    /**
     * Adres TEGO ekranu, brany z bieżącego żądania. Świadomie nie budujemy go
     * z nazwy modułu: prefiks panelu Neosa bywa zmieniony w konfiguracji, a adres
     * z żądania jest zawsze tym, pod którym administrator naprawdę stoi.
     * Parametry ucinamy, żeby nie wywieźć do panelu klucza z poprzedniej próby.
     */
    private function moduleUri(): string
    {
        try {
            $uri = $this->request->getMainRequest()->getHttpRequest()->getUri();
        } catch (\Throwable) {
            return '';
        }

        return (string) $uri->withQuery('')->withFragment('');
    }

    /** Ekran łączenia pokazujemy tylko wtedy, gdy mamy dokąd wrócić. */
    private function connectUrlAvailable(): bool
    {
        return '' !== $this->moduleUri();
    }

    private function panelUrl(): string
    {
        $stored = rtrim((string) $this->stateProvider->state()->get('panelUrl', ''), '/');

        // Przeprowadzka panelu na watch.calmfox.net: adres zapisany przy parowaniu
        // przestawiamy na nowy, bo stary host oddaje już tylko 308. Adres wpisany
        // ręcznie (środowisko testowe klienta) zostaje nietknięty.
        if ('https://watch.calmfox.pl' === $stored) {
            $stored = '';
        }

        return '' !== $stored ? $stored : 'https://watch.calmfox.net';
    }

    /**
     * Aktywacja pakietu Free. Zgoda jest wymagana świadomie: wysyłamy do
     * Calmfox adres e-mail i domenę, więc pytamy o to wprost, a nie drobnym
     * drukiem pod przyciskiem.
     */
    public function registerAction(string $email = '', bool $consent = false): void
    {
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $this->addFlashMessage('Podaj poprawny adres e-mail.', '', Message::SEVERITY_ERROR);
            $this->redirect('index');
        }
        if (!$consent) {
            $this->addFlashMessage('Do aktywacji potrzebna jest zgoda na przekazanie adresu e-mail i domeny do Calmfox.', '', Message::SEVERITY_ERROR);
            $this->redirect('index');
        }

        $result = $this->hubClient->register($email);
        $this->addFlashMessage($result['message'], '', $result['ok'] ? Message::SEVERITY_OK : Message::SEVERITY_ERROR);
        $this->redirect('index');
    }

    public function pairAction(string $token = ''): void
    {
        if (1 !== preg_match('/^(fxp_live_)?[a-f0-9]{16,32}$/', trim($token))) {
            $this->addFlashMessage('Klucz ma inny format niż fxp_live_… Skopiuj go z ekranu Integracje w panelu.', '', Message::SEVERITY_ERROR);
            $this->redirect('index');
        }

        $result = $this->hubClient->pair(trim($token));
        $this->addFlashMessage($result['message'], '', $result['ok'] ? Message::SEVERITY_OK : Message::SEVERITY_ERROR);
        $this->redirect('index');
    }

    public function rotateAction(): void
    {
        $this->stateProvider->state()->rotateSecret();
        $result = $this->hubClient->pair();
        $this->addFlashMessage(
            $result['ok']
                ? 'Klucz zabezpieczający adres kontrolny został wymieniony, monitoring korzysta już z nowego adresu.'
                : 'Klucz wymieniono na stronie, ale nie udało się zaktualizować go w panelu: '.$result['message'].' Poprzedni klucz działa jeszcze 15 minut, spróbuj przycisku „Połącz ponownie”.',
            '',
            $result['ok'] ? Message::SEVERITY_OK : Message::SEVERITY_WARNING
        );
        $this->redirect('index');
    }

    /**
     * Limit dyskowy konta podany przez klienta. Hosting współdzielony go nie
     * ujawnia, więc jedyną prawdziwą liczbę ma klient: z panelu hostingu
     * albo z umowy.
     */
    public function quotaAction(string $quota = ''): void
    {
        $raw = str_replace(',', '.', trim($quota));
        $value = '' === $raw ? 0.0 : (float) $raw;
        if ($value < 0 || $value > 100000) {
            $this->addFlashMessage('Podaj limit w gigabajtach, liczbę z zakresu od 0 do 100000 (0 oznacza, że limit nie jest znany).', '', Message::SEVERITY_ERROR);
            $this->redirect('index');
        }

        $this->stateProvider->state()->update(['diskQuotaGb' => $value]);
        $this->payloadProvider->flush();
        $this->addFlashMessage($value > 0
            ? sprintf('Zapisane, pilnujemy zajętości względem %s GB.', rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ','))
            : 'Wyczyszczone. Wróciliśmy do informowania, że limit konta nie jest znany.');
        $this->redirect('index');
    }

    /** Odświeżenie podglądu: kasujemy pamięć podręczną, żeby wynik był z tej chwili, a nie sprzed dziesięciu minut. */
    public function refreshAction(): void
    {
        $this->payloadProvider->flush();
        $this->addFlashMessage('Sprawdzenia zostaną policzone od nowa.');
        $this->redirect('index');
    }

    public function disconnectAction(): void
    {
        $this->hubClient->disconnect();
        $this->stateProvider->state()->update(['connected' => false]);
        // Ocena jest z huba i mówi o strony stanie: po rozłączeniu nie ma prawa
        // zostać na ekranie ani wrócić z pamięci podręcznej po ponownym połączeniu.
        $this->scoreProvider->forget();
        $this->addFlashMessage('Połączenie zakończone, monitoring wnętrza strony został wstrzymany. Klucz pozostaje zapisany, więc ponowne połączenie zajmie jedno kliknięcie.');
        $this->redirect('index');
    }

    /**
     * Łuki gotowe dla Fluida: dokładamy `gap`, bo szablon nie umie dodać jedynki
     * do długości łuku, a `stroke-dasharray` potrzebuje pary „wypełnienie przerwa".
     *
     * @param array<string, mixed>|null $score
     *
     * @return list<array<string, mixed>>
     */
    private function ringForView(?array $score): array
    {
        $segments = null !== $score && \is_array($score['ring'] ?? null) ? $score['ring'] : [];

        $ring = [];
        foreach ($segments as $segment) {
            $segment['gap'] = (float) $segment['length'] + 1;
            $ring[] = $segment;
        }

        return $ring;
    }
}
