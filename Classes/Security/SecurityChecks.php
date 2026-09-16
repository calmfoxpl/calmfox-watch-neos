<?php

declare(strict_types=1);

namespace Calmfox\Watch\Security;

use Calmfox\Watch\Core\DevPackages;
use Calmfox\Watch\Core\FixCommand;
use Calmfox\Watch\Core\Permissions;
use Calmfox\Watch\Service\AdminAccounts;
use Calmfox\Watch\Service\PackageVersions;
use Calmfox\Watch\Service\SiteUrl;
use Calmfox\Watch\Service\StateProvider;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Utility\Environment;

/**
 * Podstawowa higiena bezpieczeństwa. Świadomie NIE audyt i NIE skaner
 * złośliwego kodu: tanie odczyty konfiguracji i uprawnień, których wynik
 * klient może naprawić sam albo zlecić hostingodawcy. Nie obiecujemy sum
 * kontrolnych plików ani wykrywania włamań.
 *
 * Sekcja liczy się drożej niż zdrowie usług, więc hub pyta o nią raz na dobę,
 * a my trzymamy wynik w pamięci podręcznej przez dziesięć minut.
 */
#[Flow\Scope('singleton')]
class SecurityChecks
{
    #[Flow\Inject]
    protected AdminAccounts $adminAccounts;

    #[Flow\Inject]
    protected Environment $environment;

    #[Flow\Inject]
    protected ConfigurationManager $configurationManager;

    #[Flow\Inject]
    protected SiteUrl $siteUrl;

    #[Flow\Inject]
    protected PackageVersions $packageVersions;

    #[Flow\Inject]
    protected StateProvider $stateProvider;

    /** @return list<array<string, mixed>> */
    public function run(): array
    {
        $checks = [];
        foreach ([
            'checkAdminCount', 'checkAdminLogin', 'checkFlowContext', 'checkDebugDisplay',
            'checkHttps', 'checkPhpVersion', 'checkConfigPerms', 'checkDirPerms',
            'checkEncryptionKey', 'checkDevPackages', 'checkPendingUpdates',
        ] as $method) {
            try {
                $result = $this->{$method}();
                if (\is_array($result)) {
                    $checks[] = $result;
                }
            } catch (\Throwable) {
                // Jedno sprawdzenie higieny nie może zabrać ze sobą pozostałych
                // dziesięciu ani całej odpowiedzi adresu kontrolnego.
                continue;
            }
        }

        return $checks;
    }

    /** @return array<string, mixed> */
    private function checkAdminCount(): array
    {
        $survey = $this->adminAccounts->survey();
        $count = \count($survey['identifiers']);
        $detail = sprintf('Kont z pełnymi uprawnieniami: %s.', $survey['truncated'] ? $count.'+' : (string) $count);

        $many = $count > 5;

        return ['id' => 'admin_count', 'status' => $many ? 'warn' : 'ok',
            'label' => 'Liczba administratorów',
            'detail' => $detail.($many ? ' Im mniej kont z pełnymi uprawnieniami, tym mniejsza powierzchnia ataku.' : ''),
            'fix' => $many ? 'Kontom, które tylko redagują treść, odbierz rolę Neos.Neos:Administrator i zostaw Neos.Neos:Editor.' : null,
            'command' => $many ? './flow user:removerole <login> Neos.Neos:Administrator' : null];
    }

    /** @return array<string, mixed> */
    private function checkAdminLogin(): array
    {
        $exists = $this->adminAccounts->survey()['defaultLogin'];

        return ['id' => 'admin_login', 'status' => $exists ? 'warn' : 'ok',
            'label' => 'Konto o loginie „admin”',
            'detail' => $exists
                ? 'Istnieje konto administratora o loginie „admin”, pierwszy cel ataków słownikowych na hasła.'
                : 'Brak konta o domyślnym loginie.',
            // Polecenia tu nie ma świadomie: kasowanie konta administratora zanim
            // zastępcze naprawdę działa to prosta droga do zamknięcia się na zewnątrz.
            'fix' => $exists ? 'Załóż konto administratora z własnym loginem, sprawdź logowanie na nie i dopiero wtedy skasuj konto „admin”.' : null,
            'command' => null];
    }

    /**
     * Tryb pracy aplikacji. Development na produkcji to nie jest drobiazg:
     * Flow przelicza wtedy konfigurację przy każdym żądaniu (strona zwalnia
     * wielokrotnie) i pokazuje pełne ślady wykonania razem ze ścieżkami plików.
     *
     * @return array<string, mixed>
     */
    private function checkFlowContext(): array
    {
        $context = (string) $this->environment->getContext();
        if ($this->environment->getContext()->isProduction()) {
            return ['id' => 'flow_context', 'status' => 'ok', 'label' => 'Tryb pracy aplikacji',
                'detail' => sprintf('Tryb %s.', $context)];
        }
        $fix = 'FLOW_CONTEXT ma mieć wartość Production: w Apache SetEnv FLOW_CONTEXT Production, w nginx z php-fpm fastcgi_param FLOW_CONTEXT Production.';
        $command = 'FLOW_CONTEXT=Production ./flow flow:cache:flush --force';

        if ($this->environment->getContext()->isDevelopment()) {
            return ['id' => 'flow_context', 'status' => 'fail', 'label' => 'Tryb pracy aplikacji',
                'detail' => sprintf('Aplikacja pracuje w trybie %s. Strona działa wolniej i pokazuje odwiedzającym szczegóły techniczne błędów. Ustaw FLOW_CONTEXT na Production.', $context),
                'fix' => $fix, 'command' => $command];
        }

        return ['id' => 'flow_context', 'status' => 'warn', 'label' => 'Tryb pracy aplikacji',
            'detail' => sprintf('Aplikacja pracuje w trybie %s, a nie Production.', $context),
            'fix' => $fix, 'command' => $command];
    }

    /**
     * Wyciek szczegółów technicznych. Poza produkcją to stan oczekiwany, więc
     * nie robimy z tego awarii: ostrzegamy i mówimy, dlaczego to normalne.
     *
     * @return array<string, mixed>
     */
    private function checkDebugDisplay(): array
    {
        $renderTechnical = (bool) $this->setting('Neos.Flow.error.exceptionHandler.defaultRenderingOptions.renderTechnicalDetails');
        $displayErrors = filter_var((string) ini_get('display_errors'), \FILTER_VALIDATE_BOOL) || '1' === (string) ini_get('display_errors');
        $production = $this->environment->getContext()->isProduction();

        if (!$renderTechnical && !$displayErrors) {
            return ['id' => 'debug_display', 'status' => 'ok', 'label' => 'Wyświetlanie szczegółów błędów',
                'detail' => 'Odwiedzający widzi stronę błędu, a nie ślad wykonania i ścieżki plików.'];
        }

        $reasons = [];
        $fixes = [];
        if ($renderTechnical) {
            $reasons[] = 'Flow pokazuje pełne szczegóły wyjątków';
            $fixes[] = 'renderTechnicalDetails ustaw na false w Configuration/Production/Settings.yaml.';
        }
        if ($displayErrors) {
            $reasons[] = 'PHP wypisuje komunikaty błędów na stronę';
            $fixes[] = 'display_errors ustaw na Off w php.ini (błędy mają iść do logu, nie na stronę).';
        }
        $detail = ucfirst(implode(', ', $reasons)).'. Ujawnia to ścieżki plików, nazwy klas i fragmenty konfiguracji każdemu odwiedzającemu.';

        return ['id' => 'debug_display', 'status' => $production ? 'fail' : 'warn',
            'label' => 'Wyświetlanie szczegółów błędów',
            'detail' => $production ? $detail : $detail.' Poza trybem produkcyjnym jest to zachowanie oczekiwane.',
            'fix' => implode(' ', $fixes),
            // Przy błędach PHP najpierw trzeba wiedzieć, KTÓRY php.ini jest wczytany
            // (hostingi mają ich po kilka), przy samym Flow — co naprawdę siedzi w konfiguracji.
            'command' => $displayErrors ? 'php --ini'
                : './flow configuration:show --path Neos.Flow.error.exceptionHandler.defaultRenderingOptions'];
    }

    /** @return array<string, mixed> */
    private function checkHttps(): array
    {
        $secure = $this->siteUrl->isHttps();

        return ['id' => 'https', 'status' => $secure ? 'ok' : 'fail', 'label' => 'Szyfrowanie HTTPS',
            'detail' => $secure ? null
                : 'Strona działa po protokole HTTP. Dane logowania i formularze przesyłane są bez szyfrowania, a przeglądarki ostrzegają odwiedzających.',
            'fix' => $secure ? null
                : 'Włącz certyfikat u hostingodawcy, dodaj stałe przekierowanie z http na https i ustaw Neos.Flow.http.baseUri na adres z https.',
            'command' => null];
    }

    /** @return array<string, mixed> */
    private function checkPhpVersion(): array
    {
        $label = 'Wersja PHP';
        // Stan wsparcia PHP na sierpień 2026: poniżej 8.2 bez wsparcia,
        // 8.2 już tylko poprawki bezpieczeństwa.
        // Wersję PHP przestawia się w panelu hostingu, nie poleceniem w konsoli:
        // `php -v` w SSH pokazuje wersję CLI, która bywa inna niż wersja strony.
        $fix = 'W panelu hostingu przestaw wersję PHP dla tej domeny na 8.3 lub nowszą, a po zmianie sprawdź stronę i panel Neosa.';
        if (version_compare(\PHP_VERSION, '8.2', '<')) {
            return ['id' => 'php_version', 'status' => 'fail', 'label' => $label,
                'detail' => sprintf('PHP %s nie dostaje już nawet poprawek bezpieczeństwa. Konieczna aktualizacja u hostingodawcy.', \PHP_VERSION),
                'fix' => $fix, 'command' => null];
        }
        if (version_compare(\PHP_VERSION, '8.3', '<')) {
            return ['id' => 'php_version', 'status' => 'warn', 'label' => $label,
                'detail' => sprintf('PHP %s dostaje już tylko poprawki bezpieczeństwa. Zaplanuj przejście wyżej.', \PHP_VERSION),
                'fix' => $fix, 'command' => null];
        }

        return ['id' => 'php_version', 'status' => 'ok', 'label' => $label,
            'detail' => sprintf('PHP %s, wersja wspierana.', \PHP_VERSION)];
    }

    /**
     * Pliki ustawień z danymi dostępu do bazy. 644 to norma na hostingach
     * współdzielonych, więc alarmujemy dopiero przy prawach dla grupy i świata.
     *
     * @return array<string, mixed>|null
     */
    private function checkConfigPerms(): ?array
    {
        $worst = 'ok';
        $offenders = [];
        $paths = [];
        foreach ($this->configurationFiles() as $path) {
            $perms = @fileperms($path);
            if (false === $perms) {
                continue;
            }
            $verdict = Permissions::secretFileVerdict($perms & 0777);
            if ('ok' !== $verdict) {
                $offenders[] = $this->relative($path).' ('.Permissions::octal($perms).')';
                $paths[] = $this->relative($path);
                $worst = 'fail' === $verdict ? 'fail' : ('fail' === $worst ? 'fail' : 'warn');
            }
        }
        if ([] === $offenders) {
            return ['id' => 'config_perms', 'status' => 'ok', 'label' => 'Uprawnienia plików konfiguracji',
                'detail' => 'Pliki z danymi dostępu do bazy są czytelne tylko dla właściciela konta.'];
        }

        return ['id' => 'config_perms', 'status' => $worst, 'label' => 'Uprawnienia plików konfiguracji',
            'detail' => sprintf('Zbyt szerokie prawa: %s. Plik z hasłem do bazy jest dostępny dla innych użytkowników serwera. Ustaw 640 albo 600.', implode(', ', $offenders)),
            'fix' => 'Docelowe prawa to 640: właściciel czyta i zapisuje, grupa serwera WWW tylko czyta, reszta serwera nic. Gdy PHP działa na tym samym użytkowniku co pliki, wystarczy 600.',
            'command' => FixCommand::chmod('640', $paths)];
    }

    /** @return array<string, mixed> */
    private function checkDirPerms(): array
    {
        $world = [];
        $dirs = [];
        foreach ([
            'Data' => $this->dataPath(),
            'Data/Persistent' => $this->dataPath().'Persistent',
            'Web/_Resources' => $this->webPath().'_Resources',
        ] as $name => $path) {
            if (!is_dir($path)) {
                continue;
            }
            $perms = @fileperms($path);
            if (false !== $perms && Permissions::worldWritable($perms)) {
                $world[] = $name.' ('.Permissions::octal($perms).')';
                $dirs[] = $name;
            }
        }
        if ([] === $world) {
            return ['id' => 'dir_perms', 'status' => 'ok', 'label' => 'Uprawnienia katalogów',
                'detail' => 'Katalogi zapisywalne przez aplikację nie są otwarte dla całego serwera.'];
        }

        // -R świadomie: prawa 777 zwykle siedzą też w podkatalogach, a zmiana samego
        // katalogu nadrzędnego zostawiłaby otwarte dokładnie te miejsca, w których
        // lądują wgrywane pliki.
        return ['id' => 'dir_perms', 'status' => 'fail', 'label' => 'Uprawnienia katalogów',
            'detail' => sprintf('Zapisywalne dla wszystkich (777): %s. Dowolny proces na serwerze może umieścić tam własny plik.', implode(', ', $world)),
            'fix' => 'Katalogom zapisywalnym przez aplikację wystarczy 755, a gdy serwer WWW pracuje na innym użytkowniku niż właściciel plików, 775 przy wspólnej grupie.',
            'command' => FixCommand::chmod('755', $dirs, recursive: true)];
    }

    /**
     * Klucz szyfrujący Flow. Podpisuje między innymi identyfikatory w adresach
     * i dane formularzy, więc jego wyciek pozwala podrobić to, co aplikacja
     * uznaje za własne.
     *
     * @return array<string, mixed>
     */
    private function checkEncryptionKey(): array
    {
        $label = 'Klucz szyfrujący aplikacji';
        $path = $this->dataPath().'Persistent/EncryptionKey';
        if (!is_file($path)) {
            return ['id' => 'encryption_key', 'status' => 'warn', 'label' => $label,
                'detail' => 'Klucz jeszcze nie powstał. Flow utworzy go przy pierwszym użyciu, ale do tego czasu nie mamy czego sprawdzać.'];
        }
        $perms = @fileperms($path);
        if (false === $perms) {
            return ['id' => 'encryption_key', 'status' => 'warn', 'label' => $label,
                'detail' => 'Klucz istnieje, ale nie udało się odczytać jego uprawnień.'];
        }
        $verdict = Permissions::secretFileVerdict($perms & 0777);

        return ['id' => 'encryption_key', 'status' => $verdict, 'label' => $label,
            'detail' => 'ok' === $verdict
                ? sprintf('Klucz istnieje, prawa %s.', Permissions::octal($perms))
                : sprintf('Klucz ma prawa %s, czyli jest dostępny dla innych użytkowników serwera. Ustaw 600.', Permissions::octal($perms)),
            'fix' => 'ok' === $verdict ? null
                : 'Plik klucza ma mieć prawa 600: czyta go wyłącznie użytkownik, na którym pracuje aplikacja. Samego klucza NIE podmieniaj, bo zmiana unieważnia podpisane nim dane.',
            'command' => 'ok' === $verdict ? null : FixCommand::chmod('600', [$this->relative($path)])];
    }

    /**
     * Pakiety deweloperskie na produkcji. Wskazują na wdrożenie bez opcji
     * --no-dev, a razem z nimi na serwerze ląduje kod, którego nikt nie
     * przegląda pod kątem bezpieczeństwa, bo „to tylko narzędzia".
     *
     * @return array<string, mixed>
     */
    private function checkDevPackages(): array
    {
        $label = 'Pakiety deweloperskie';
        $installed = $this->packageVersions->installed();
        if ([] === $installed) {
            return ['id' => 'dev_packages', 'status' => 'warn', 'label' => $label,
                'detail' => 'Nie udało się odczytać listy pakietów Composera, więc nie sprawdzamy tego punktu.'];
        }
        $found = DevPackages::detect($installed);
        if (!$this->environment->getContext()->isProduction()) {
            return ['id' => 'dev_packages', 'status' => 'ok', 'label' => $label,
                'detail' => 'Aplikacja nie pracuje w trybie produkcyjnym, więc obecność pakietów deweloperskich jest oczekiwana.'];
        }
        $fix = 'Na produkcji instaluj bez wymagań deweloperskich (composer install --no-dev) i po każdym wdrożeniu przeładuj autoloader. Pakiety dev zostają wtedy wyłącznie na maszynie programisty.';
        $command = 'composer install --no-dev --optimize-autoloader';
        if ([] !== $found['risky']) {
            return ['id' => 'dev_packages', 'status' => 'fail', 'label' => $label,
                'detail' => sprintf('Na produkcji są pakiety, które potrafią zapisywać kod w katalogu aplikacji: %s. Wdrażaj poleceniem „composer install --no-dev".', implode(', ', $found['risky'])),
                'fix' => $fix, 'command' => $command];
        }
        if ($found['devMode'] || [] !== $found['names']) {
            return ['id' => 'dev_packages', 'status' => 'warn', 'label' => $label,
                'detail' => sprintf('Wdrożenie zawiera %d pakietów z wymagań deweloperskich. Na produkcji są zbędne i powiększają powierzchnię ataku.', \count($found['names'])),
                'fix' => $fix, 'command' => $command];
        }

        return ['id' => 'dev_packages', 'status' => 'ok', 'label' => $label,
            'detail' => 'Wdrożenie bez wymagań deweloperskich.'];
    }

    /**
     * Zaległe aktualizacje. Liczby biorą się z polecenia CLI, bo `composer
     * outdated` chodzi po sieci i nie ma prawa spowalniać adresu kontrolnego.
     * Gdy nikt go nigdy nie uruchomił, mówimy to wprost, zamiast pokazywać zero.
     *
     * @return array<string, mixed>
     */
    private function checkPendingUpdates(): array
    {
        $label = 'Zaległe aktualizacje';
        $updates = $this->stateProvider->state()->updates();
        if (null === $updates) {
            return ['id' => 'pending_updates', 'status' => 'warn', 'label' => $label,
                'detail' => 'Nie sprawdzamy aktualizacji: nikt jeszcze nie uruchomił polecenia „./flow calmfoxwatch:updates". Dodaj je do zadań cyklicznych raz na dobę, a zaczniemy pilnować.',
                'fix' => 'Uruchom polecenie raz ręcznie, a potem dopisz je do crona raz na dobę (wpis w cronie: 0 4 * * * cd /sciezka/do/strony && ./flow calmfoxwatch:updates).',
                'command' => './flow calmfoxwatch:updates'];
        }

        $age = $this->daysSince((string) $updates['at']);
        $stale = null !== $age && $age > 7 ? sprintf(' Ostatnie sprawdzenie: %d dni temu.', $age) : '';

        if ($updates['core'] > 0) {
            return ['id' => 'pending_updates', 'status' => 'warn', 'label' => $label,
                'detail' => 'Dostępna aktualizacja Neosa. Aktualizacja platformy jest najpilniejsza.'.$stale,
                'fix' => 'Zacznij od sprawdzenia, co ma nowsze wersje, i aktualizuj najpierw platformę, na kopii albo na środowisku testowym, nie od razu na produkcji.',
                'command' => 'composer outdated --direct'];
        }

        $many = $updates['plugins'] >= 5;

        return ['id' => 'pending_updates', 'status' => $many ? 'warn' : 'ok', 'label' => $label,
            'detail' => sprintf('Pakietów do aktualizacji: %d.', $updates['plugins']).$stale,
            'fix' => $many ? 'Przejrzyj listę zaległych pakietów i zaplanuj aktualizację, najpierw na środowisku testowym.' : null,
            'command' => $many ? 'composer outdated --direct' : null];
    }

    // ── Pomocnicze ───────────────────────────────────────────────────────

    /** @return list<string> */
    private function configurationFiles(): array
    {
        $base = \defined('FLOW_PATH_CONFIGURATION') ? (string) \constant('FLOW_PATH_CONFIGURATION') : $this->rootPath().'Configuration/';
        $context = (string) $this->environment->getContext();
        $candidates = [$base.'Settings.yaml'];
        $prefix = '';
        foreach (explode('/', $context) as $part) {
            $prefix .= $part.'/';
            $candidates[] = $base.$prefix.'Settings.yaml';
        }

        return array_values(array_filter($candidates, static fn (string $path): bool => is_file($path)));
    }

    private function daysSince(string $iso): ?int
    {
        try {
            $then = new \DateTimeImmutable($iso);
        } catch (\Exception) {
            return null;
        }

        return (int) floor((time() - $then->getTimestamp()) / 86400);
    }

    private function setting(string $path): mixed
    {
        try {
            return $this->configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, $path);
        } catch (\Throwable) {
            return null;
        }
    }

    private function relative(string $path): string
    {
        $root = $this->rootPath();

        return '' !== $root && str_starts_with($path, $root) ? substr($path, \strlen($root)) : $path;
    }

    private function rootPath(): string
    {
        return \defined('FLOW_PATH_ROOT') ? (string) \constant('FLOW_PATH_ROOT') : getcwd().'/';
    }

    private function dataPath(): string
    {
        return \defined('FLOW_PATH_DATA') ? (string) \constant('FLOW_PATH_DATA') : $this->rootPath().'Data/';
    }

    private function webPath(): string
    {
        return \defined('FLOW_PATH_WEB') ? (string) \constant('FLOW_PATH_WEB') : $this->rootPath().'Web/';
    }
}
