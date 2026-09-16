<?php

declare(strict_types=1);

namespace Calmfox\Watch\Core;

/**
 * Przykładowe polecenia naprawcze dopinane do checków. Osobna klasa z trzech
 * powodów, z których każdy sam w sobie wystarczy.
 *
 * Cytowanie: ścieżka ze spacją albo apostrofem wklejona do terminala bez
 * cudzysłowów wykona coś innego, niż pokazuje opis. Limit kontraktu: polecenie
 * dłuższe niż 200 znaków wypada w CAŁOŚCI (CheckNormalizer), więc przy długiej
 * liście plików wolimy pokazać polecenie na jeden plik niż stracić podpowiedź.
 * I trzeci: to jest przykład do skopiowania, a nie naprawa wykonywana przez nas
 * — pakiet niczego tu nie uruchamia i nie ma prawa uruchomić.
 */
final class FixCommand
{
    /**
     * Zmiana praw dostępu. Ścieżki podajemy względem katalogu strony, bo tam
     * stoi ten, kto będzie to wklejał.
     *
     * @param list<string> $paths
     */
    public static function chmod(string $mode, array $paths, bool $recursive = false): ?string
    {
        $paths = array_values(array_filter($paths, static fn (string $p): bool => '' !== $p));
        if ([] === $paths) {
            return null;
        }

        $full = self::build($mode, $paths, $recursive);
        if (null !== $full) {
            return $full;
        }

        // Komplet się nie mieści: jeden plik jako przykład jest uczciwszy niż
        // lista ucięta w połowie ścieżki albo brak jakiejkolwiek podpowiedzi.
        return self::build($mode, [$paths[0]], $recursive);
    }

    /** @param list<string> $paths */
    private static function build(string $mode, array $paths, bool $recursive): ?string
    {
        $command = 'chmod '.($recursive ? '-R ' : '').$mode.' '.implode(' ', array_map(self::quote(...), $paths));

        return mb_strlen($command) <= CheckNormalizer::MAX_COMMAND ? $command : null;
    }

    /** Cudzysłowy tylko tam, gdzie są potrzebne — zwykła ścieżka ma zostać czytelna. */
    public static function quote(string $path): string
    {
        if (1 === preg_match('#^[A-Za-z0-9._/-]+$#', $path)) {
            return $path;
        }

        return "'".str_replace("'", "'\\''", $path)."'";
    }
}
