<?php

declare(strict_types=1);

namespace Calmfox\Watch\Core;

/**
 * Historia zmian wersji. Neos, w odróżnieniu od WordPressa, nie ma hooka
 * aktualizacji — pakiety wymienia Composer poza aplikacją, często z konsoli
 * wdrożeniowej. Nie da się więc podpiąć pod zdarzenie; robimy migawkę wersji
 * i porównujemy ją przy każdym budowaniu sekcji security (czyli maks. co
 * 10 minut) oraz przy poleceniach CLI.
 *
 * Konsekwencja, którą nazywamy wprost w README i w panelu: `at` to czas
 * WYKRYCIA różnicy, nie czas wdrożenia. Przy wdrożeniu w nocy i pierwszym
 * odpytaniu security rano wpis dostanie poranną datę. Do zdania „awaria
 * zaczęła się godzinę po aktualizacji pakietu X" to wystarcza, a udawanie
 * dokładniejszego czasu byłoby zmyślaniem.
 */
final class VersionSnapshot
{
    /**
     * Pakiety liczone jako `core`. Kontrakt zna trzy rodzaje (core, plugin,
     * theme); na platformach composerowych `theme` nie występuje, a `core` to
     * platforma: sam Neos i PHP, na którym stoi. Reszta to `plugin`.
     */
    public const PLATFORM = ['neos/neos', 'php'];

    /**
     * Migawka z tablicy z vendor/composer/installed.php plus wersja PHP.
     * Pakiety bez wersji (metapaczki, ścieżkowe repozytoria) pomijamy —
     * inaczej generowałyby wpis „dodano/usunięto" przy każdym odczycie.
     *
     * @param array<string, mixed> $installed
     *
     * @return array<string, string>
     */
    public static function fromInstalled(array $installed, string $phpVersion): array
    {
        $out = [];
        $versions = $installed['versions'] ?? [];
        if (\is_array($versions)) {
            foreach ($versions as $name => $info) {
                if (!\is_string($name) || !\is_array($info)) {
                    continue;
                }
                $version = (string) ($info['pretty_version'] ?? $info['version'] ?? '');
                if ('' === $version) {
                    continue;
                }
                $out[mb_strtolower($name)] = mb_substr($version, 0, 32);
            }
        }
        // Sama wersja PHP to nie jest migawka. Gdyby tak ją potraktować, brak
        // odczytu listy pakietów zapisałby FAŁSZYWY punkt odniesienia, a pierwszy
        // udany odczyt wyglądałby jak jednoczesna instalacja wszystkich pakietów
        // (widziane na żywo: 134 wpisy „dodano" w historii jednym ciągiem).
        if ([] === $out) {
            return [];
        }

        $out['php'] = mb_substr($phpVersion, 0, 32);
        ksort($out);

        return $out;
    }

    /**
     * Różnice między migawkami: podbicie wersji, dołożenie pakietu, usunięcie.
     * Wszystkie trzy są dla nas równie ciekawe — usunięcie pakietu potrafi
     * wywrócić stronę tak samo jak aktualizacja.
     *
     * @param array<string, string> $before
     * @param array<string, string> $after
     *
     * @return list<array{kind: string, name: string, from: ?string, to: ?string, at: string, mode: string, by: null}>
     */
    public static function diff(array $before, array $after, string $at): array
    {
        $entries = [];
        foreach ($after as $name => $version) {
            $old = $before[$name] ?? null;
            if (null === $old) {
                $entries[] = self::entry($name, null, $version, $at);
            } elseif ($old !== $version) {
                $entries[] = self::entry($name, $old, $version, $at);
            }
        }
        foreach ($before as $name => $version) {
            if (!\array_key_exists($name, $after)) {
                $entries[] = self::entry($name, $version, null, $at);
            }
        }
        usort($entries, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $entries;
    }

    /**
     * @return array{kind: string, name: string, from: ?string, to: ?string, at: string, mode: string, by: null}
     */
    private static function entry(string $name, ?string $from, ?string $to, string $at): array
    {
        return [
            'kind' => \in_array($name, self::PLATFORM, true) ? 'core' : 'plugin',
            'name' => mb_substr('php' === $name ? 'PHP' : $name, 0, 120),
            'from' => null !== $from ? mb_substr($from, 0, 32) : null,
            'to' => null !== $to ? mb_substr($to, 0, 32) : null,
            'at' => $at,
            // Composer nie mówi nam, kto i czym uruchomił wdrożenie, więc nie
            // zgadujemy: zawsze „ręczna" i bez autora, zamiast wymyślonego loginu.
            'mode' => 'manual',
            'by' => null,
        ];
    }
}
