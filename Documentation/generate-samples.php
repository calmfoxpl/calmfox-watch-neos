<?php

declare(strict_types=1);

/**
 * Generator próbek payloadu: php Documentation/generate-samples.php
 *
 * Próbki w tym katalogu MUSZĄ być wyjściem naszego buildera, a nie ręcznie
 * pisanym JSON-em. Hub opiera na nich test kontraktowy, więc plik napisany
 * z pamięci zestarzałby się przy pierwszej zmianie kształtu payloadu i test
 * zacząłby pilnować fikcji.
 *
 * Wyniki sprawdzeń są tu przykładowe (nie da się ich policzyć bez działającej
 * instalacji Neosa), ale przechodzą przez tę samą normalizację i agregację,
 * co na produkcji, a wersje pól i kolejność kluczy pochodzą z kodu.
 */

use Calmfox\Watch\Core\PayloadBuilder;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Calmfox\\Watch\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = \dirname(__DIR__).'/Classes/'.str_replace('\\', '/', substr($class, \strlen($prefix))).'.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$healthChecks = [
    ['id' => 'db', 'status' => 'ok', 'label' => 'Baza danych', 'detail' => null, 'ms' => 3],
    ['id' => 'disk', 'status' => 'ok', 'label' => 'Miejsce na dysku',
        'detail' => 'Sama instalacja zajmuje 4,1 GB z podanego limitu konta 20 GB (20%). Poza tym miejsce zajmują poczta i pozostałe strony na koncie.'],
    ['id' => 'smtp', 'status' => 'ok', 'label' => 'Wysyłka e-mail (SMTP)',
        'detail' => 'Serwer smtp.example.com:587 odpowiada poprawnie. To sprawdzenie połączenia, nie doręczenia wiadomości.', 'ms' => 84],
    ['id' => 'flow_cache', 'status' => 'ok', 'label' => 'Pamięć podręczna Flow (Redis)', 'detail' => null, 'ms' => 2],
    ['id' => 'resources', 'status' => 'ok', 'label' => 'Publikacja zasobów strony',
        'detail' => 'Katalog publikacji Web/_Resources jest zapisywalny.'],
    ['id' => 'content_repository', 'status' => 'ok', 'label' => 'Repozytorium treści',
        'detail' => 'Aktywnych stron: 1. Węzłów treści w gałęzi live: 1842.', 'ms' => 11],
    ['id' => 'queue', 'status' => 'warn', 'label' => 'Kolejki zadań',
        'detail' => 'Zadań zakończonych błędem: 2, oczekujących: 17. Zadania z błędem nie wykonają się same.', 'ms' => 6],
    ['id' => 'elasticsearch', 'status' => 'ok', 'label' => 'Wyszukiwarka Elasticsearch',
        'detail' => 'Klaster w pełni sprawny.', 'ms' => 39],
];

$health = PayloadBuilder::health(
    $healthChecks,
    PayloadBuilder::site('8.3.14', '8.3.14', ['core' => 0, 'plugins' => 3, 'themes' => 0]),
    array_merge(
        PayloadBuilder::signals(
            ['admin@Neos.Neos:Backend', 'redakcja@Neos.Neos:Backend', 'wdrozenie@Neos.Neos:Backend'],
            '2026-08-01T10:00:00+00:00',
            '0123456789abcdef0123456789abcdef'
        ),
        // Skrócona lista pakietów: próbka pokazuje KSZTAŁT pola, a nie inwentarz strony.
        PayloadBuilder::packageSignals(
            ['calmfox/watch-neos', 'flowpack/searchplugin', 'neos/neos', 'neos/redirecthandler', 'neos/seo', 'vendor/site'],
            '0123456789abcdef0123456789abcdef'
        )
    )
);

$securityChecks = [
    ['id' => 'admin_count', 'status' => 'ok', 'label' => 'Liczba administratorów', 'detail' => 'Kont z pełnymi uprawnieniami: 3.'],
    // Ten wpis pokazuje podpowiedź naprawczą bez polecenia: skasowanie konta
    // administratora zanim zastępcze działa to prosta droga do zamknięcia się na zewnątrz.
    ['id' => 'admin_login', 'status' => 'warn', 'label' => 'Konto o loginie „admin”',
        'detail' => 'Istnieje konto administratora o loginie „admin”, pierwszy cel ataków słownikowych na hasła.',
        'fix' => 'Załóż konto administratora z własnym loginem, sprawdź logowanie na nie i dopiero wtedy skasuj konto „admin”.'],
    ['id' => 'flow_context', 'status' => 'ok', 'label' => 'Tryb pracy aplikacji', 'detail' => 'Tryb Production.'],
    ['id' => 'debug_display', 'status' => 'ok', 'label' => 'Wyświetlanie szczegółów błędów',
        'detail' => 'Odwiedzający widzi stronę błędu, a nie ślad wykonania i ścieżki plików.'],
    ['id' => 'https', 'status' => 'ok', 'label' => 'Szyfrowanie HTTPS', 'detail' => null],
    ['id' => 'php_version', 'status' => 'ok', 'label' => 'Wersja PHP', 'detail' => 'PHP 8.3.14, wersja wspierana.'],
    // A ten komplet: opis, konkret naprawczy i gotowe polecenie do skopiowania.
    ['id' => 'config_perms', 'status' => 'warn', 'label' => 'Uprawnienia plików konfiguracji',
        'detail' => 'Zbyt szerokie prawa: Configuration/Production/Settings.yaml (660). Plik z hasłem do bazy jest dostępny dla innych użytkowników serwera. Ustaw 640 albo 600.',
        'fix' => 'Docelowe prawa to 640: właściciel czyta i zapisuje, grupa serwera WWW tylko czyta, reszta serwera nic.',
        'command' => 'chmod 640 Configuration/Production/Settings.yaml'],
    ['id' => 'dir_perms', 'status' => 'ok', 'label' => 'Uprawnienia katalogów',
        'detail' => 'Katalogi zapisywalne przez aplikację nie są otwarte dla całego serwera.'],
    ['id' => 'encryption_key', 'status' => 'ok', 'label' => 'Klucz szyfrujący aplikacji', 'detail' => 'Klucz istnieje, prawa 600.'],
    ['id' => 'dev_packages', 'status' => 'warn', 'label' => 'Pakiety deweloperskie',
        'detail' => 'Wdrożenie zawiera 12 pakietów z wymagań deweloperskich. Na produkcji są zbędne i powiększają powierzchnię ataku.',
        'fix' => 'Na produkcji instaluj bez wymagań deweloperskich i po każdym wdrożeniu przeładuj autoloader.',
        'command' => 'composer install --no-dev --optimize-autoloader'],
    ['id' => 'pending_updates', 'status' => 'ok', 'label' => 'Zaległe aktualizacje', 'detail' => 'Pakietów do aktualizacji: 3.'],
];

$security = PayloadBuilder::security($securityChecks, [
    ['kind' => 'plugin', 'name' => 'flowpack/jobqueue-common', 'from' => '2.1.0', 'to' => '2.2.0',
        'at' => '2026-08-18T21:35:00+00:00', 'mode' => 'manual', 'by' => null],
    ['kind' => 'core', 'name' => 'neos/neos', 'from' => '8.3.13', 'to' => '8.3.14',
        'at' => '2026-08-18T21:35:00+00:00', 'mode' => 'manual', 'by' => null],
    ['kind' => 'core', 'name' => 'PHP', 'from' => '8.2.20', 'to' => '8.3.14',
        'at' => '2026-07-02T05:12:00+00:00', 'mode' => 'manual', 'by' => null],
]);

$flags = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;
file_put_contents(__DIR__.'/sample-health.json', json_encode($health, $flags)."\n");
file_put_contents(__DIR__.'/sample-security.json', json_encode($security, $flags)."\n");

echo "Zapisane: sample-health.json (status {$health['status']}), sample-security.json (status {$security['status']})\n";
