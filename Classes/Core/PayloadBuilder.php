<?php

declare(strict_types=1);

namespace Calmfox\Watch\Core;

/**
 * Budowa payloadu obu sekcji. Jedno miejsce, w którym powstaje kształt
 * wysyłany na łącze — dzięki temu próbki w Documentation/ są realnym wyjściem
 * tego kodu, a nie ręcznie pisanym JSON-em, który zdąży się zestarzeć.
 *
 * Nazw pakietów w sekcji `health` NIE ma i być nie może: to gotowy rekonesans
 * dla atakującego. Same liczby. Nazwy jadą wyłącznie w `history`, bo tam są
 * całą wartością funkcji („awaria zaczęła się godzinę po aktualizacji X").
 */
final class PayloadBuilder
{
    /** Tyle nazw pakietów przyjmuje hub. Odcisk liczymy z całej listy, więc zmiana poza setką też się wykryje. */
    private const MAX_PACKAGE_NAMES = 100;

    public const SCHEMA = 1;

    /**
     * `signals` POMIJAMY, gdy nie udało się odczytać kont (padnięta baza).
     * To nie jest kosmetyka: hub porównuje odciski między odpytaniami, więc
     * wysłanie pustego zbioru wyglądałoby jak podmiana wszystkich kont naraz
     * i otworzyłoby incydent o przejęciu strony w chwili, w której naprawdę
     * padła tylko baza.
     *
     * @param iterable<mixed>           $checks
     * @param array<string, mixed>      $site
     * @param array<string, mixed>|null $signals
     *
     * @return array<string, mixed>
     */
    public static function health(iterable $checks, array $site, ?array $signals): array
    {
        $normalized = CheckNormalizer::normalize($checks);

        $payload = [
            'schema' => self::SCHEMA,
            'plugin' => Version::NUMBER,
            'status' => CheckNormalizer::aggregate($normalized),
            'checks' => $normalized,
            'site' => $site,
        ];
        if (null !== $signals) {
            $payload['signals'] = $signals;
        }

        return $payload;
    }

    /**
     * @param iterable<mixed>                $checks
     * @param list<array<string, mixed>> $history
     *
     * @return array<string, mixed>
     */
    public static function security(iterable $checks, array $history): array
    {
        $normalized = CheckNormalizer::normalize($checks);

        return [
            'schema' => self::SCHEMA,
            'plugin' => Version::NUMBER,
            'status' => CheckNormalizer::aggregate($normalized),
            'checks' => $normalized,
            'history' => \array_slice(array_values($history), 0, InstallationState::HISTORY_MAX),
        ];
    }

    /**
     * Wersje platformy. Pole nazywa się `wp` ze względów historycznych (hub
     * obsługuje wszystkie platformy tym samym kodem) i tak zostaje; dla nas
     * jest tam wersja Neosa.
     *
     * `updates` POMIJAMY W CAŁOŚCI, gdy liczb nie znamy. To nie jest detal:
     * zero znaczy „sprawdzone, nie ma czego aktualizować", a brak pola znaczy
     * „nie wiemy" i panel powie to wprost, zamiast pokazywać uspokajające zero.
     *
     * @param array{core: int, plugins: int, themes: int}|null $updates
     *
     * @return array<string, mixed>
     */
    public static function site(string $platformVersion, string $phpVersion, ?array $updates): array
    {
        $site = [
            'wp' => $platformVersion,
            'php' => $phpVersion,
            'plugin' => Version::NUMBER,
        ];
        if (null !== $updates) {
            $site['updates'] = [
                'core' => max(0, (int) ($updates['core'] ?? 0)),
                'plugins' => max(0, (int) ($updates['plugins'] ?? 0)),
                'themes' => 0, // Neos nie ma motywów: zero jest tu prawdą, nie atrapą
            ];
        }

        return $site;
    }

    /**
     * @param iterable<string> $adminIdentifiers
     *
     * @return array{adminCount: int, adminsFingerprint: string, newestAdminAt: ?string}
     */
    public static function signals(iterable $adminIdentifiers, ?string $newestAdminAt, string $secret): array
    {
        $identifiers = [];
        foreach ($adminIdentifiers as $identifier) {
            $identifiers[] = (string) $identifier;
        }

        return [
            'adminCount' => \count(array_unique($identifiers)),
            'adminsFingerprint' => AdminFingerprint::of($identifiers, $secret),
            'newestAdminAt' => $newestAdminAt,
        ];
    }

    /**
     * Skład zainstalowanych rozszerzeń: liczba, jednokierunkowy odcisk zbioru
     * i nazwy (do stu, tyle przyjmuje hub). Nazwy jadą świadomie: bez nich
     * zdarzenie o zniknięciu pakietu brzmiałoby „coś się zmieniło", a jedyna
     * sensowna reakcja wymaga wiedzy, KTÓREGO pakietu zabrakło.
     *
     * Pola `autoUpdates` tu nie ma i nie będzie: Neos nie aktualizuje się sam,
     * a wartość w tym polu znaczyłaby „sprawdzone", zamiast „nie ma czego
     * sprawdzać".
     *
     * @param list<string> $names
     *
     * @return array<string, mixed> pusta tablica, gdy spisu nie udało się odczytać
     */
    public static function packageSignals(array $names, string $secret): array
    {
        if ([] === $names) {
            // Pusty spis znaczy „nie udało się odczytać", a nie „strona nie ma
            // pakietów": Neos bez pakietów nie istnieje. Zero wysłane jako fakt
            // kazałoby hubowi ogłosić, że zniknęły wszystkie naraz.
            return [];
        }

        return [
            'pluginCount' => \count($names),
            'pluginsFingerprint' => AdminFingerprint::of($names, $secret),
            'activePlugins' => \array_slice($names, 0, self::MAX_PACKAGE_NAMES),
        ];
    }
}
