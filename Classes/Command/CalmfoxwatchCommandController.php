<?php

declare(strict_types=1);

namespace Calmfox\Watch\Command;

use Calmfox\Watch\Core\Version;
use Calmfox\Watch\Service\HubClient;
use Calmfox\Watch\Service\PayloadProvider;
use Calmfox\Watch\Service\StateProvider;
use Calmfox\Watch\Service\UpdateCounter;
use Calmfox\Watch\Service\VersionHistory;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;

/**
 * Polecenia konsoli. Na Neosie to nie jest dodatek do modułu w panelu, tylko
 * główna droga: wdrożenia idą tu z repozytorium i skryptu, a nie z klikania.
 * Parowanie z poziomu potoku wdrożeniowego ma działać bez otwierania
 * przeglądarki.
 *
 * Polecenie updates jest osobno i celowo: liczy zaległe aktualizacje przez
 * Composera, czyli chodzi po sieci. Wpuszcza się je w zadania cykliczne raz
 * na dobę, a adres kontrolny czyta gotowy wynik z pliku stanu.
 */
class CalmfoxwatchCommandController extends CommandController
{
    #[Flow\Inject]
    protected StateProvider $stateProvider;

    #[Flow\Inject]
    protected HubClient $hubClient;

    #[Flow\Inject]
    protected PayloadProvider $payloadProvider;

    #[Flow\Inject]
    protected UpdateCounter $updateCounter;

    #[Flow\Inject]
    protected VersionHistory $versionHistory;

    /**
     * W konsoli nie ma żądania HTTP, więc host bierze się z ustawienia albo
     * z domen strony w bazie. Gdy nie ma żadnego z tych źródeł, adres kontrolny
     * wychodzi bez hosta i hub odrzuca parowanie komunikatem o niepoprawnym
     * adresie. Mówimy to wprost i podajemy, co ustawić, zamiast pozwolić
     * użytkownikowi zgadywać z błędu po drugiej stronie.
     */
    private function warnAboutMissingBaseUri(): bool
    {
        if (str_starts_with($this->hubClient->healthUrl(), 'http')) {
            return true;
        }

        $this->outputLine('<error>Nie znam adresu tej strony, więc adres kontrolny nie ma hosta.</error>');
        $this->outputLine('Ustaw go w Configuration/Settings.yaml:');
        $this->outputLine('');
        $this->outputLine('  Calmfox:');
        $this->outputLine('    Watch:');
        $this->outputLine("      baseUri: 'https://twojadomena.pl'");
        $this->outputLine('');
        $this->outputLine('Alternatywnie ustaw Neos.Flow.http.baseUri albo dopisz domenę strony w panelu Neosa.');

        return false;
    }

    /**
     * Stan połączenia z Calmfox Watch.
     */
    public function statusCommand(): void
    {
        $state = $this->stateProvider->state();
        $this->outputLine('Calmfox Watch %s', [Version::NUMBER]);
        $this->warnAboutMissingBaseUri();
        $this->outputLine('Adres kontrolny: %s', [$this->hubClient->healthUrl()]);
        $this->outputLine('Adres API: %s', [$this->hubClient->apiUrl()]);
        $this->outputLine('Plik stanu: %s', [$state->path()]);
        $this->outputLine('Połączenie: %s', [$state->get('connected') ? 'aktywne' : 'brak']);

        if ($state->get('connected')) {
            $this->outputLine('Pakiet: %s', [(string) $state->get('plan', '') ?: 'nieznany']);
            $lastPoll = (int) $state->get('lastPollAt', 0);
            $this->outputLine('Ostatnie odpytanie monitoringu: %s', [
                $lastPoll > 0 ? gmdate('Y-m-d H:i:s', $lastPoll).' UTC' : 'jeszcze nie było',
            ]);
        }

        $updates = $state->updates();
        $this->outputLine('Zaległe aktualizacje: %s', [
            null === $updates
                ? 'nie sprawdzamy (uruchom ./flow calmfoxwatch:updates)'
                : sprintf('Neos %d, pozostałe pakiety %d (stan z %s)', $updates['core'], $updates['plugins'], $updates['at']),
        ]);

        $loopback = $this->hubClient->loopbackCheck();
        $this->outputLine('Samokontrola adresu kontrolnego: %s', [$loopback['ok'] ? 'odpowiada' : 'problem, '.$loopback['message']]);
    }

    /**
     * Aktywacja pakietu Free na podany adres e-mail.
     *
     * @param string $email adres e-mail właściciela strony
     */
    public function registerCommand(string $email): void
    {
        // Bez hosta hub odrzuci parowanie, więc nie wysyłamy żądania w ciemno.
        if (!$this->warnAboutMissingBaseUri()) {
            $this->quit(1);
        }
        $result = $this->hubClient->register($email);
        $this->outputLine($result['ok'] ? $result['message'] : 'Nie udało się: '.$result['message']);
        if (!$result['ok']) {
            $this->quit(1);
        }
    }

    /**
     * Połączenie z istniejącą stroną kluczem instalacyjnym z panelu.
     *
     * @param string $token klucz instalacyjny w postaci fxp_live_...
     */
    public function pairCommand(string $token = ''): void
    {
        // Bez hosta hub odrzuci parowanie, więc nie wysyłamy żądania w ciemno.
        if (!$this->warnAboutMissingBaseUri()) {
            $this->quit(1);
        }
        $result = $this->hubClient->pair($token);
        $this->outputLine($result['ok'] ? $result['message'] : 'Nie udało się: '.$result['message']);
        if (!$result['ok']) {
            $this->quit(1);
        }
    }

    /**
     * Zakończenie monitoringu wnętrza strony.
     */
    public function disconnectCommand(): void
    {
        $this->hubClient->disconnect();
        $this->stateProvider->state()->update(['connected' => false]);
        $this->outputLine('Monitoring wnętrza wstrzymany. Klucz zostaje zapisany, więc ponowne połączenie to jedno polecenie.');
    }

    /**
     * Przelicza zaległe aktualizacje pakietów i zapisuje wynik.
     *
     * To polecenie idzie do zadań cyklicznych, raz na dobę wystarczy.
     * Dopóki nikt go nie uruchomi, panel uczciwie mówi „brak danych" zamiast
     * pokazywać uspokajające zero.
     */
    public function updatesCommand(): void
    {
        $result = $this->updateCounter->refresh();
        $this->outputLine($result['message']);
        $this->payloadProvider->flush();

        $entries = $this->versionHistory->reconcile();
        if ([] !== $entries) {
            $this->outputLine('Wykryte zmiany wersji: %d.', [\count($entries)]);
        }

        if (!$result['ok']) {
            $this->quit(1);
        }
    }

    /**
     * Wypisuje payload tak, jak zobaczy go monitoring.
     *
     * @param string $section health albo security
     * @param bool   $fresh   pomija pamięć podręczną i liczy sprawdzenia od nowa
     */
    public function healthCommand(string $section = 'health', bool $fresh = true): void
    {
        $payload = 'security' === $section
            ? $this->payloadProvider->security($fresh)
            : $this->payloadProvider->health($fresh);

        $this->outputLine((string) json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        if ('fail' === ($payload['status'] ?? '')) {
            // Kod wyjścia zgodny z tym, co zobaczy monitoring: skrypt wdrożeniowy
            // może na tym oprzeć decyzję o wycofaniu zmian.
            $this->quit(1);
        }
    }

    /**
     * Wymienia sekret adresu kontrolnego i przepina monitoring na nowy adres.
     *
     * Poprzedni sekret działa jeszcze kwadrans, więc nieudane przepięcie
     * po stronie panelu nie zrywa monitoringu.
     */
    public function rotateCommand(): void
    {
        $this->stateProvider->state()->rotateSecret();
        $result = $this->hubClient->pair();
        $this->outputLine($result['ok']
            ? 'Klucz wymieniony, monitoring korzysta już z nowego adresu.'
            : 'Klucz wymieniony na stronie, ale nie udało się zaktualizować go w panelu: '.$result['message'].' Poprzedni klucz działa jeszcze 15 minut.');
        if (!$result['ok']) {
            $this->quit(1);
        }
    }
}
