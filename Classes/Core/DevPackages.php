<?php

declare(strict_types=1);

namespace Calmfox\Watch\Core;

/**
 * Pakiety deweloperskie na produkcji. Composer zapisuje w installed.php, czy
 * instalacja szła z wymaganiami dev (`root.dev`) i który pakiet przyszedł
 * z sekcji require-dev (`dev_requirement`). To znaczy, że nie musimy zgadywać
 * po nazwach — wystarczy przeczytać, jak wdrożenie zostało wykonane.
 *
 * Osobno pilnujemy pakietów, które dają realną władzę nad serwerem, nawet gdy
 * ktoś przeniósł je do zwykłych wymagań: kreator kodu potrafi zapisać plik PHP
 * w drzewie aplikacji.
 */
final class DevPackages
{
    public const RISKY = ['neos/kickstarter', 'neos/welcome', 'neos/behat'];

    /**
     * @param array<string, mixed> $installed zawartość vendor/composer/installed.php
     *
     * @return array{devMode: bool, names: list<string>, risky: list<string>}
     */
    public static function detect(array $installed): array
    {
        $root = \is_array($installed['root'] ?? null) ? $installed['root'] : [];
        $versions = \is_array($installed['versions'] ?? null) ? $installed['versions'] : [];

        $names = [];
        $risky = [];
        foreach ($versions as $name => $info) {
            if (!\is_string($name) || !\is_array($info)) {
                continue;
            }
            $name = mb_strtolower($name);
            if (true === ($info['dev_requirement'] ?? false)) {
                $names[] = $name;
            }
            if (\in_array($name, self::RISKY, true)) {
                $risky[] = $name;
            }
        }
        sort($names, \SORT_STRING);
        sort($risky, \SORT_STRING);

        return [
            'devMode' => true === ($root['dev'] ?? false),
            'names' => $names,
            'risky' => $risky,
        ];
    }
}
