# Calmfox Watch dla Neos CMS

Monitoring wnętrza strony opartej na Neosie (Flow) dla panelu
[watch.calmfox.net](https://watch.calmfox.net). Pakiet realizuje ten sam kontrakt
z hubem, co wtyczka WordPressa (`wp-plugin/calmfox-watch`, specyfikacja
w `WTYCZKI.md` w korzeniu repozytorium): wystawia sekretny adres kontrolny,
który hub odpytuje w modelu pull.

Trzy rzeczy, które daje:

- **stan usług** — baza danych, miejsce na dysku, połączenie z serwerem poczty,
  pamięć podręczna Flow, publikacja zasobów, repozytorium treści, opcjonalnie
  kolejki zadań i wyszukiwarka;
- **podstawowa higiena bezpieczeństwa** — tryb pracy aplikacji, wyciek
  szczegółów błędów, uprawnienia plików i katalogów, konta administratorów,
  pakiety deweloperskie na produkcji, zaległe aktualizacje;
- **historia zmian wersji pakietów** — żeby dało się powiedzieć „awaria zaczęła
  się godzinę po aktualizacji pakietu X".

Czego pakiet **nie** robi i obiecywać nie będzie: nie skanuje złośliwego kodu,
nie liczy sum kontrolnych plików, nie robi kopii zapasowych i nie wysyła
dzienników zdarzeń.

---

## Wymagania i zakres wersji

```json
"require": {
    "php": ">=8.1",
    "neos/flow": "^8.3 || ^9.0",
    "neos/neos": "^8.3 || ^9.0"
}
```

Zapis `^8.3 || ^9.0` znaczy „8.3 i nowsze w linii ósemki albo dowolne 9.x".
Świadomie NIE piszemy `>=8.3`, bo to obiecywałoby zgodność z każdą przyszłą
wersją główną, której nikt nie widział. I świadomie nie piszemy samego `^8.3`,
bo Neos 9 jest wersją, na którą wdrożenia właśnie przechodzą.

Uczciwie o dwóch wersjach głównych: Neos 9 przepisał API repozytorium treści
od nowa. Kod trzyma się tego, co jest stabilne w obu (`SiteRepository`,
`CacheManager`, Flow Security, Doctrine), a tam, gdzie API się rozjeżdża,
sprawdzenie **degraduje się do mniejszej informacji zamiast się wywracać**:
check `content_repository` na Neosie 9 poda liczbę aktywnych stron bez liczby
węzłów, zamiast rzucić wyjątkiem. Pakiet był budowany i uruchamiany przeciwko
API Neosa 8.3; na 9.0 ścieżki zgodności są napisane, ale nie były przez nas
przejechane na żywej instalacji.

## Instalacja

Pakiet nie jest opublikowany w publicznym katalogu pakietów Composera
(Packagist), więc samo `composer require calmfox/watch-neos` kończy się błędem
„could not be found". Instaluje się go z paczki `calmfox-watch-neos.zip`, którą
podaje panel Calmfox Watch (Integracje, przycisk „Pobierz dla Neos CMS").
W paczce jest jeden katalog: `Calmfox.Watch/`. Obie drogi niżej prowadzą do tego
samego wyniku.

### Droga 1: Composerem z rozpakowanej paczki (zalecana)

Composer uruchamia wtedy instalator Neosa, przelicza autoloader i publikuje
zasoby, czyli robi to samo, co przy pakiecie pobranym z Packagista:

```bash
mkdir -p PakietyCalmfox && unzip calmfox-watch-neos.zip -d PakietyCalmfox
composer config repositories.calmfox-watch '{"type":"path","url":"./PakietyCalmfox/Calmfox.Watch","options":{"symlink":false}}'
composer require calmfox/watch-neos:@dev
FLOW_CONTEXT=Production ./flow flow:cache:flush --force
```

Trzy miejsca, w których łatwo się potknąć:

- **`"symlink": false`** każe Composerowi skopiować pliki. Bez tego pakiet
  w `Packages/Application` jest wyłącznie dowiązaniem do `PakietyCalmfox`
  i zniknie razem z tym katalogiem.
- **Rozpakowany katalog zostaje w projekcie** (i w repozytorium, jeżeli wdrożenie
  idzie z gita). Composer czyta go przy każdym `composer install`, więc jego
  skasowanie wywróci następne wdrożenie.
- **`@dev` przy nazwie pakietu jest konieczne.** `composer.json` paczki świadomie
  nie ma pola `version` (Composer wylicza wersję z tagu repozytorium, a paczka
  tagu nie ma), więc repozytorium typu `path` melduje ją jako `dev-main`.

Aktualizacja: rozpakowanie nowszej paczki w to samo miejsce i
`composer update calmfox/watch-neos`.

### Droga 2: ręczne wgranie plików

```bash
unzip calmfox-watch-neos.zip -d Packages/Application
FLOW_CONTEXT=Production ./flow flow:cache:flush --force
FLOW_CONTEXT=Production ./flow resource:publish
```

Flow znajduje pakiety, przeszukując katalog `Packages` w poszukiwaniu plików
`composer.json`, i sam dokłada przestrzenie nazw z sekcji `autoload` pakietu do
swojego class loadera, więc pakiet działa również bez wpisu w `composer.json`
projektu. Oba polecenia po rozpakowaniu są przy tej drodze obowiązkowe: wynik
przeszukiwania leży w pamięci podręcznej (bez przeczyszczenia Flow nie zobaczy
ani pakietu, ani jego poleceń), a zasoby publiczne nie mają tu Composera, który
opublikowałby je skryptem po instalacji.

Jedno ostrzeżenie przy wdrożeniach z gita: bazowa dystrybucja Neosa trzyma cały
katalog `Packages/` poza repozytorium (jego `.gitignore` ma wpis `/Packages/`),
więc ręcznie wgrany pakiet albo trzeba z tego wpisu wyjąć, albo wgrywać po każdym
wdrożeniu. Droga 1 tego kłopotu nie ma, bo pakiet wraca z `composer install`.

### Wpięcie trasy adresu kontrolnego

Neos ma dwa mechanizmy tras i to on decyduje, czy musisz cokolwiek robić.

**Dystrybucja bez `Configuration/Routes.yaml` w projekcie** (tak wygląda bazowa
dystrybucja Neosa 9): trasy powstają wyłącznie z ustawienia `Neos.Flow.mvc.routes`,
a pakiet rejestruje się tam sam, tak samo jak robi to `Neos.Media`. Nie musisz
zmieniać niczego w projekcie. Sprawdzone na żywej instalacji Neos 9.1.5.

**Dystrybucja z `Configuration/Routes.yaml` w projekcie**: dopisz podtrasę
**przed** trasami `Neos.Neos`, bo one łapią każdy pozostały adres jako podstronę:

```yaml
-
  name: 'Calmfox Watch'
  uriPattern: '<CalmfoxWatchSubroutes>'
  subRoutes:
    CalmfoxWatchSubroutes:
      package: 'Calmfox.Watch'

# ...poniżej trasy, które już masz, w tym Neos:
-
  name: 'Neos'
  uriPattern: '<NeosSubroutes>'
  subRoutes:
    NeosSubroutes:
      package: 'Neos.Neos'
```

Samodzielna rejestracja z ustawień zostaje wtedy bezczynna i **nie przeszkadza**:
Flow dokleja trasy z ustawień ZA trasami z pliku (`RoutesLoader`: „Routes from
settings will always be appended to existing route definitions"), więc lądują za
łapaczem Neosa i nigdy nie pasują. Nie ma tu żadnego wykluczania się mechanizmów,
wcześniejsze wydanie tego pakietu ostrzegało przed tym błędnie.

Jeżeli chcesz inną ścieżkę niż `/calmfox-watch/health`, zmień `uriPattern`
w `Configuration/Routes.yaml` pakietu i tę samą wartość w ustawieniu
`Calmfox.Watch.healthPath`, bo z niego budujemy adres zgłaszany hubowi.

### Uruchamianie ./flow i przeczyszczanie pamięci podręcznej

Sprawdzone na hostingu współdzielonym (cyber-Folks, LiteSpeed, Neos 9.1.5):

- **Podaj kontekst jawnie**: `FLOW_CONTEXT=Production ./flow ...`. Bez tego Flow
  startuje w kontekście Development i wywala się na pierwszym poleceniu.
- **Użyj tej binarki PHP, którą ma skonfigurowaną Flow.** Gdy `php` w PATH jest
  inne (u nas CLI dawało 8.2, a aplikacja chodzi na 8.4), Flow przerywa
  polecenie i podaje właściwą ścieżkę, np.
  `FLOW_CONTEXT=Production /opt/alt/php84/usr/bin/php ./flow calmfoxwatch:status`.
- **Po instalacji trzeba przeczyścić pamięć podręczną**, inaczej Flow nie widzi
  ani nowych poleceń, ani trasy: `FLOW_CONTEXT=Production ./flow flow:cache:flush --force`.
- **Po podmianie plików pakietu opublikuj zasoby**: `FLOW_CONTEXT=Production ./flow resource:publish`.
  Arkusz stylów modułu leży w `Resources/Public`, a Flow serwuje takie pliki z kopii
  w `Web/_Resources`. Bez publikacji ekran modułu wstaje bez kolorów, a arkusz oddaje 404
  (sprawdzone: po samym rozpakowaniu paczki adres arkusza zwracał 404, po `resource:publish`
  kod 200). `composer require` robi to za Ciebie, ręczna podmiana plików nie.
- **Licz się z krótkim oknem HTTP 503 zaraz po przeczyszczeniu.** Flow przebudowuje
  wtedy klasy proxy i odbicia; na średniej wielkości stronie trwało to kilkanaście
  sekund, a żądania w tym czasie dostawały 503. Rób to poza szczytem, a zaraz po
  przeczyszczeniu odpal `FLOW_CONTEXT=Production ./flow flow:cache:warmup` albo
  po prostu wejdź na stronę, żeby to Ty zapłacił za pierwsze żądanie, nie klient.

Po wpięciu sprawdź:

```bash
./flow calmfoxwatch:status
```

Polecenie wypisze adres kontrolny i wynik samokontroli (żądanie z serwera do
własnego adresu). Jeżeli samokontrola zgłasza problem, parowanie też się nie
uda: najczęstsze powody to niewpięta trasa i zapora sieciowa blokująca
nietypową ścieżkę.

## Moduł w panelu

Pakiet zakłada WŁASNĄ grupę modułów w menu panelu, nad „Zarządzaniem", zamiast
chować się w jego podmodułach: monitoring, do którego trzeba się doklikać przez
dwa poziomy, ogląda wyłącznie ten, kto go szuka. Grupa i jej pozycja „Kondycja
strony" prowadzą do tego samego ekranu, tak samo jak w grupach Neosa.

Kafelka na pulpicie, który dostają WordPress, Sylius i Magento, w Neosie nie ma
i to nie jest przeoczenie: panel Neosa nie ma pulpitu, na którym dałoby się go
powiesić (po zalogowaniu ląduje się wprost w module treści). Zamiast tego
pozycja w menu jest jedno kliknięcie od każdego ekranu panelu.

## Połączenie z panelem

Trzy drogi, ta sama co we wtyczce WordPressa kolejność od najprostszej.

**1. Przez panel Calmfox Watch (zalecane).** W module klikasz „Połącz przez
Calmfox Watch". Przechodzisz do panelu, logujesz się albo zakładasz konto,
wybierasz organizację, a panel odsyła Cię z powrotem na ten ekran i pakiet
paruje się sam. Nie przepisujesz żadnych kluczy.

Zabezpieczenie tej drogi: przed wyjściem pakiet zapisuje jednorazowy znacznik
i wysyła go w adresie, a przy powrocie porównuje w stałym czasie i kasuje
NIEZALEŻNIE od wyniku. Panel ze swojej strony wraca wyłącznie pod adres na
domenie łączonej strony, którego ścieżka zawiera `/neos/`. Bez obu tych bramek
wystarczyłoby podrzucić administratorowi link z cudzym kluczem, żeby podpiąć
stronę pod obce konto.

Adres powrotny bierzemy z bieżącego żądania, a nie z nazwy modułu, więc działa
także wtedy, gdy prefiks panelu Neosa jest w projekcie zmieniony. Jeśli adresu
nie da się ustalić, przycisk po prostu się nie pokazuje i zostają dwie drogi niżej.

**2. Kluczem instalacyjnym.** Skopiuj klucz `fxp_live_…` z ekranu Integracje
w panelu i wklej w module albo podaj poleceniu konsoli.

**3. Z konsoli, bez klikania** (wdrożenia z repozytorium):

```bash
# nowe konto w pakiecie Free
FLOW_CONTEXT=Production ./flow calmfoxwatch:register wlasciciel@example.com

# albo dopięcie do istniejącej strony w panelu
FLOW_CONTEXT=Production ./flow calmfoxwatch:pair fxp_live_0123456789abcdef
```

## Polecenia konsoli

| Polecenie | Do czego |
|---|---|
| `./flow calmfoxwatch:status` | stan połączenia, adres kontrolny, samokontrola |
| `./flow calmfoxwatch:register <email>` | aktywacja pakietu Free na podany adres |
| `./flow calmfoxwatch:pair <token>` | połączenie z istniejącą stroną w panelu |
| `./flow calmfoxwatch:disconnect` | zakończenie monitoringu wnętrza |
| `./flow calmfoxwatch:updates` | przeliczenie zaległych aktualizacji (do zadań cyklicznych) |
| `./flow calmfoxwatch:health [--section security]` | wypisanie payloadu lokalnie |
| `./flow calmfoxwatch:rotate` | wymiana sekretu i przepięcie monitoringu |

### Zadanie cykliczne dla aktualizacji

`composer outdated` chodzi po sieci i potrafi trwać kilkanaście sekund, więc
adres kontrolny go NIE uruchamia. Liczby powstają w poleceniu i lądują w pliku
stanu:

```cron
17 4 * * * cd /var/www/strona && ./flow calmfoxwatch:updates >/dev/null 2>&1
```

Dopóki nikt tego nie uruchomi, pakiet mówi wprost „nie sprawdzamy" i **pomija
pole `updates` w payloadzie w całości**. To nie jest niedoróbka: zero znaczy
„sprawdzone, nie ma czego aktualizować", a brak pola znaczy „nie wiemy" i tak
to opisuje panel.

## Ustawienia

`Configuration/Settings.yaml` projektu, sekcja `Calmfox.Watch`:

| Ustawienie | Domyślnie | Do czego |
|---|---|---|
| `apiUrl` | `https://watch.calmfox.net` | adres API; zmienna środowiskowa `CALMFOX_WATCH_API_URL` ma pierwszeństwo |
| `healthPath` | `/calmfox-watch/health` | ścieżka adresu kontrolnego; musi zgadzać się z `uriPattern` z tras |
| `statePath` | `%FLOW_PATH_DATA%Persistent/CalmfoxWatch/state.json` | plik stanu |
| `smtp.host`, `smtp.port`, `smtp.encryption` | puste | jawne nadpisanie konfiguracji poczty |
| `composerBinary` | puste | ścieżka do Composera dla polecenia `:updates` |

### Dlaczego stan siedzi w pliku, a nie w bazie

Bo cała wartość tego monitoringu ujawnia się dokładnie wtedy, gdy baza leży.
Przy padniętej bazie adres kontrolny ma odpowiedzieć `db: fail` i kodem 503,
a nie zamilknąć. Gdyby sekret trzymała tabela, strona przestałaby odpowiadać
w tym jednym momencie, w którym naprawdę zarabia.

Plik zapisujemy atomowo (zapis do pliku tymczasowego i podmiana), z prawami 600.
Przy kilku instancjach aplikacji za load balancerem wskaż `statePath` na wspólny
wolumen: sekret musi być dla nich jeden, inaczej hub trafi raz na jedną, raz na
drugą i dostanie 403.

## Adres kontrolny

```
GET https://domena/calmfox-watch/health?key=<32 znaki hex>
GET https://domena/calmfox-watch/health?key=…&section=security
GET https://domena/calmfox-watch/health?key=…&nonce=<znacznik jednorazowy>
```

- zły albo brak klucza: `403` i suche `{"error":"forbidden"}`,
- `200` przy `ok` i `warn`, `503` przy `fail`, nic innego,
- nagłówki `Cache-Control: no-store, max-age=0` i `X-Robots-Tag: noindex, nofollow`,
- przy `nonce` odpowiedź jest podpisana nagłówkami `X-Calmfox-Proof`
  i `X-Calmfox-Generated-At`.

Podpis idzie nagłówkiem, a nie w treści, bo liczymy go nad **dokładnie tymi
bajtami**, które wychodzą na łącze. Kontroler świadomie sam składa JSON
i pomija warstwę widoku, żeby między `json_encode` a łączem nie stanął żaden
renderer.

Granica ochrony, mówimy o niej wprost: kto ma sekret z serwera, może podpisać
kłamstwo. Podpis odcina tanie ataki (podstawiony plik statyczny, odpowiedź
z pamięci podręcznej, powtórka sprzed przejęcia strony), a nie zastępuje
odzyskiwania serwera.

Rotacja sekretu: nowy działa od razu, poprzedni jeszcze przez 15 minut, żeby
nieudane przepięcie po stronie panelu nie zerwało monitoringu.

## Sprawdzenia

### Sekcja `health` (sonda odpytuje co 60 s, wynik żyje 60 s)

| Identyfikator | Co sprawdza |
|---|---|
| `db` | `SELECT 1` przez Doctrine, z pomiarem czasu. Brak bazy to `fail`, nie wyjątek |
| `disk` | zapisywalność `Data/Persistent` i `Web/_Resources` oraz zajętość względem limitu |
| `smtp` | POŁĄCZENIE z serwerem poczty (TCP, powitanie 220, EHLO). Wynik żyje 15 minut |
| `flow_cache` | zapis i odczyt klucza kontrolnego przez `CacheManager` |
| `resources` | zapisywalność katalogu publikacji zasobów |
| `content_repository` | czy jest aktywna strona i węzeł główny w gałęzi live |
| `queue` | zaległości w kolejkach `Flowpack.JobQueue` (opcjonalny) |
| `elasticsearch` | stan klastra przez `/_cluster/health` (opcjonalny, wynik żyje 5 minut) |

Checki opcjonalne są **pomijane w całości**, gdy strona nie ma danego pakietu.
Nie wysyłamy „ok" o usłudze, której nie ma.

O dwóch rzeczach mówimy wprost, bo inaczej byłoby to zmyślanie:

- **`smtp` to test połączenia, nie doręczenia.** Nie wysyłamy wiadomości
  próbnych. Gdy poczta wychodzi przez API dostawcy (SES, SendGrid, Mailgun,
  Postmark), piszemy to w opisie i nie udajemy testu SMTP.
- **`disk` na hostingu współdzielonym nie zna limitu konta.** `disk_free_space()`
  raportuje tam cały wolumen serwera, więc takiej liczby nie pokazujemy. Podaj
  limit w module (albo w ustawieniach), a zaczniemy pilnować zajętości.

### Sekcja `security` (hub pyta raz na dobę, wynik żyje 10 minut)

`admin_count`, `admin_login`, `flow_context`, `debug_display`, `https`,
`php_version`, `config_perms`, `dir_perms`, `encryption_key`, `dev_packages`,
`pending_updates`.

Konta administratorów czytamy przez repozytoria Flow Security, a nie zapytaniem
SQL, bo rola administratora bywa **dziedziczona** przez rolę własną klienta.
Zapytanie po nazwie roli przegapiłoby takie konto, czyli dokładnie to, na czym
zależy atakującemu.

Na zewnątrz nie idą loginy. Idzie liczba kont, jednokierunkowy odcisk ich zbioru
(HMAC z sekretu instalacji) i data najnowszego konta. Hub wykrywa ZMIANĘ składu,
nie tożsamość. Gdy kont nie da się odczytać (padnięta baza), pole `signals`
znika z payloadu w całości: pusty zbiór wyglądałby jak podmiana wszystkich kont
naraz i otworzyłby incydent o przejęciu strony w chwili, gdy padła tylko baza.

## Historia zmian wersji

Neos nie ma hooka aktualizacji: pakiety wymienia Composer poza aplikacją.
Dlatego robimy migawkę wersji z `vendor/composer/installed.php` (plus wersja PHP)
i porównujemy ją przy każdym budowaniu sekcji `security`, czyli najwyżej co
10 minut, oraz przy poleceniu `./flow calmfoxwatch:updates`.

Trzy konsekwencje, które trzeba znać:

1. **`at` to czas WYKRYCIA różnicy, nie czas wdrożenia.** Wdrożenie o 2:00
   i pierwsze sprawdzenie o 7:30 dadzą wpis z godziną 7:30. Do zdania „awaria
   zaczęła się godzinę po aktualizacji pakietu X" to wystarcza, a udawanie
   dokładniejszego czasu byłoby zmyślaniem.
2. **Historia zaczyna się od instalacji pakietu.** Pierwsze uzgodnienie zapisuje
   tylko migawkę, bez wpisów. Wcześniejszych zmian nie da się odtworzyć.
3. **`mode` zawsze `manual`, `by` zawsze puste.** Composer nie mówi nam, kto
   i czym uruchomił wdrożenie, więc nie zgadujemy autora.

`kind: core` dostają `neos/neos` i PHP (platforma), `kind: plugin` cała reszta.
Bufor: 200 wpisów. Nazwy pakietów z WERSJAMI jadą **wyłącznie** w historii, bo
lista „co i w jakiej wersji" jest gotową mapą dziur dla atakującego; w sekcji
`health` przy liczbach jedzie sam SKŁAD zainstalowanych pakietów Flow i Neosa
(`signals.activePlugins`, bez wersji i bez bibliotek). Bez nazw zdarzenie
o zniknięciu pakietu brzmiałoby „coś się zmieniło", a wtedy nie da się na nie
zareagować. Pola `signals.autoUpdates` nie wysyłamy w ogóle: Neos nie
aktualizuje się sam, a wartość w tym polu znaczyłaby „sprawdzone".

## Własne sprawdzenia

Odpowiednik filtra `calmfox_watch_health_checks` z wtyczki WordPressa. W Flow
naturalne jest zbieranie implementacji interfejsu przez `ReflectionService`
(dzieje się w czasie kompilacji, więc na produkcji nic nie kosztuje):

```php
<?php
namespace Twoj\Pakiet\Monitoring;

use Calmfox\Watch\Health\HealthCheckInterface;
use Neos\Flow\Annotations as Flow;

#[Flow\Scope('singleton')]
class BrokerCheck implements HealthCheckInterface
{
    public function run(): ?array
    {
        $socket = @fsockopen('127.0.0.1', 5672, $number, $text, 2);
        if (!is_resource($socket)) {
            return ['id' => 'rabbitmq', 'status' => 'fail', 'label' => 'Kolejka RabbitMQ',
                    'detail' => 'Broker nie przyjmuje połączeń.'];
        }
        fclose($socket);

        return ['id' => 'rabbitmq', 'status' => 'ok', 'label' => 'Kolejka RabbitMQ'];
    }
}
```

Po dodaniu klasy: `./flow flow:cache:flush`. Nasze sprawdzenia idą pierwsze
w ustalonej kolejności, obce po nich alfabetycznie po nazwie klasy.

Klasa nie może być `final`: Flow opakowuje wstrzykiwane obiekty klasą
pośredniczącą przez dziedziczenie, a finalnej nie da się odziedziczyć.

Dwie zasady dla własnych sprawdzeń:

1. **Zwróć `null`, gdy usługi na tej instalacji nie ma.** Nie wysyłamy „ok"
   o czymś, czego nie ma.
2. **Trzymaj krótki, twardy limit czasu.** Wynik wchodzi w odpowiedź adresu
   odpytywanego co minutę.

Identyfikatory spoza katalogu z kontraktu panel pokaże z etykietą z payloadu
i notką „usługa dopięta własnym rozszerzeniem".

## Prywatność

Do Calmfox jadą: domena strony, adres e-mail podany przy zakładaniu konta oraz
dane diagnostyczne opisane wyżej (statusy usług, wersje, liczby zaległych
aktualizacji, historia zmian wersji, liczba kont administratorów i odcisk ich
zbioru, skład zainstalowanych pakietów Flow i Neosa wraz z odciskiem). Nie jadą:
treści, dane użytkowników, loginy, hasła. Adres kontrolny bez klucza odpowiada 403.

## Rozłączenie

`./flow calmfoxwatch:disconnect` (albo przycisk w module) mówi hubowi wprost,
że kończymy. Robimy to świadomie, zamiast zostawiać hubowi głuchy adres:
milczenie pakietu hub traktuje jak sygnał i po trzech nieudanych odpytaniach
otwiera zdarzenie. Wyciszenie monitoringu bywa pierwszym krokiem po przejęciu
panelu, więc klient ma o tym wiedzieć.

Rozłączenie wymaga sekretu z adresu kontrolnego, nie samego klucza
instalacyjnego: klucz jest jawny.

## Próbki payloadu

`Documentation/sample-health.json` i `Documentation/sample-security.json` są
wyjściem naszego buildera (`Documentation/generate-samples.php`), nie plikami
pisanymi ręcznie. Hub opiera na nich test kontraktowy, a plik pisany z pamięci
zestarzałby się przy pierwszej zmianie kształtu payloadu.

Po zmianie payloadu: `php Documentation/generate-samples.php`.

## Testy

Rdzeń pakietu (`Classes/Core`) jest świadomie bez żadnej zależności od Flow:
normalizacja i agregacja sprawdzeń, podpis odpowiedzi, rotacja sekretu, różnica
migawek wersji, odcisk kont, budowa payloadu. Dzięki temu testy chodzą bez
bootstrapu frameworka, bez bazy i bez sieci:

```bash
cd neos-plugin/Calmfox.Watch
../../api/vendor/bin/phpunit -c phpunit.xml.dist
```

Sprawdzenia zależne od Flow (`Classes/Health`, `Classes/Security`) testuje się
na żywej instalacji poleceniem `./flow calmfoxwatch:health`.

## Wersja pakietu

Jedno źródło prawdy: stała `Calmfox\Watch\Core\Version::NUMBER`. `composer.json`
świadomie NIE ma pola `version` (Composer wylicza je z tagów repozytorium,
a ręcznie wpisane pole zawsze prędzej czy później się rozjeżdża). Skrypt paczki
`scripts/build-neos-zip.sh` czyta tę stałą.
