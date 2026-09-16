<?php

declare(strict_types=1);

namespace Calmfox\Watch\Service;

use Neos\Flow\Annotations as Flow;

/**
 * Odczyt vendor/composer/installed.php. Czytamy plik, a nie klasę
 * Composer\InstalledVersions, z jednego powodu: InstalledVersions trzyma dane
 * z chwili załadowania autoloadera, a polecenie CLI liczące aktualizacje bywa
 * uruchamiane zaraz po wdrożeniu, kiedy plik na dysku jest już nowy, a proces
 * jeszcze stary. Plik zawsze mówi, co naprawdę leży w vendorze.
 */
#[Flow\Scope('singleton')]
class PackageVersions
{
    /** Hub przycina dłuższe nazwy, więc przycinamy je sami, żeby odcisk zgadzał się z listą. */
    private const NAME_LENGTH = 80;

    /** @var array<string, mixed>|null */
    private ?array $installed = null;

    /** @return array<string, mixed> */
    public function installed(): array
    {
        if (null !== $this->installed) {
            return $this->installed;
        }
        $path = $this->path();
        if ('' === $path || !is_file($path)) {
            return $this->installed = [];
        }
        try {
            $data = include $path;
        } catch (\Throwable) {
            return $this->installed = [];
        }

        return $this->installed = \is_array($data) ? $data : [];
    }

    public function versionOf(string $package): ?string
    {
        $versions = $this->installed()['versions'] ?? [];
        $info = \is_array($versions) ? ($versions[mb_strtolower($package)] ?? null) : null;
        if (!\is_array($info)) {
            return null;
        }
        $version = (string) ($info['pretty_version'] ?? $info['version'] ?? '');

        return '' !== $version ? $version : null;
    }

    /**
     * Nazwy zainstalowanych ROZSZERZEŃ, czyli pakietów Flow i Neosa. Świadomie
     * bez bibliotek: spis wszystkiego, co leży w zależnościach, byłby gotowym
     * rekonesansem, a wartość ma tu wyłącznie to, co dokłada stronie funkcje
     * i co ktoś może z niej zdjąć. Wersji nie wysyłamy, tylko skład.
     *
     * @return list<string> posortowane, bez duplikatów
     */
    public function extensionNames(): array
    {
        $versions = $this->installed()['versions'] ?? [];
        if (!\is_array($versions)) {
            return [];
        }

        $names = [];
        foreach ($versions as $name => $info) {
            $type = \is_array($info) ? (string) ($info['type'] ?? '') : '';
            if (!str_starts_with($type, 'neos-') && !str_starts_with($type, 'flow-') && !str_starts_with($type, 'typo3-flow-')) {
                continue;
            }
            $name = trim(mb_substr((string) $name, 0, self::NAME_LENGTH));
            if ('' !== $name && '__root__' !== $name) {
                $names[$name] = true;
            }
        }
        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /** Wersja platformy raportowana hubowi: sam Neos, a gdy go nie ma (czysty Flow) samo jądro. */
    public function platformVersion(): string
    {
        return $this->versionOf('neos/neos') ?? $this->versionOf('neos/flow') ?? 'nieznana';
    }

    /**
     * Ścieżka do installed.php. Nie ma jednej: dystrybucje Flow trzymają zależności
     * w Packages/Libraries, a nie w vendor (sprawdzone na Neosie 9.1.5, gdzie sztywne
     * „vendor/" dawało pustą listę pakietów, czyli wersję platformy „nieznana",
     * pusty check pakietów deweloperskich i historię zmian bez punktu odniesienia).
     *
     * Pierwsze pytamy autoloader Composera przez refleksję: on wie, gdzie naprawdę
     * leży katalog zależności, niezależnie od układu projektu. Ścieżki na sztywno
     * zostają jako zapasowe, gdyby klasa autoloadera była niedostępna.
     */
    public function path(): string
    {
        foreach ($this->candidates() as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /** @return list<string> */
    private function candidates(): array
    {
        $paths = [];
        if (class_exists(\Composer\Autoload\ClassLoader::class)) {
            try {
                $file = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
                if (\is_string($file) && '' !== $file) {
                    $paths[] = \dirname($file).'/installed.php';
                }
            } catch (\ReflectionException) {
                // Autoloader bez pliku (np. preload): zostają ścieżki zapasowe.
            }
        }

        $root = rtrim(\defined('FLOW_PATH_ROOT') ? (string) \constant('FLOW_PATH_ROOT') : (string) getcwd(), '/');
        $paths[] = $root.'/Packages/Libraries/composer/installed.php'; // układ dystrybucji Flow
        $paths[] = $root.'/vendor/composer/installed.php';             // zwykły projekt Composera

        return $paths;
    }
}
