<?php

declare(strict_types=1);

namespace Calmfox\Watch\Core;

/**
 * Spis zainstalowanych paczek z wersjami do sekcji `security`. Hub porównuje go
 * ze swoją kopią bazy znanych podatności, więc nowa dziura w starej wersji wychodzi
 * bez uruchamiania czegokolwiek na serwerze sklepu.
 *
 * Spis jedzie wyłącznie w `security`, a hub przyjmuje go tylko z podpisanej odpowiedzi:
 * lista „co i w jakiej wersji" to mapa ataku.
 */
final class Inventory
{
    /** Sufit huba: dłuższy spis odrzuca w całości, więc nie ma po co go wysyłać. */
    public const MAX_ENTRIES = 3000;

    /**
     * Hub odrzuca odpowiedź ponad 256 kB i czyta to jak milczenie pakietu, czyli otwiera
     * alarm o niedostępnej stronie. Reszta sekcji waży do około 40 kB (200 wpisów historii),
     * a brak spisu jest uczciwy: panel mówi wtedy „brak spisu", zamiast budzić ludzi.
     */
    public const MAX_BYTES = 200_000;

    /**
     * @param array<mixed> $installed zawartość vendor/composer/installed.php
     *
     * @return list<array{type: string, name: string, version: string}>|null null = spisu nie ma albo jest za duży
     */
    public static function fromInstalled(array $installed): ?array
    {
        $versions = $installed['versions'] ?? null;
        if (!\is_array($versions)) {
            return null;
        }
        // Paczka główna to sam projekt klienta. Nie ma jej w żadnej bazie podatności.
        $root = $installed['root']['name'] ?? null;

        $entries = [];
        foreach ($versions as $name => $info) {
            if (!\is_string($name) || !\is_array($info) || $name === $root || '__root__' === $name) {
                continue;
            }
            // Pakiety „replaced"/„provided" nie mają wersji własnej instalacji.
            $version = $info['pretty_version'] ?? $info['version'] ?? null;
            if (\is_string($version) && '' !== $version) {
                $entries[$name] = ['type' => 'package', 'name' => $name, 'version' => $version];
            }
        }
        ksort($entries);
        $entries = array_values($entries);

        if ([] === $entries || \count($entries) > self::MAX_ENTRIES) {
            return null;
        }
        $encoded = json_encode($entries);

        return false === $encoded || \strlen($encoded) > self::MAX_BYTES ? null : $entries;
    }
}
