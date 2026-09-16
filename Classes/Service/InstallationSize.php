<?php

declare(strict_types=1);

namespace Calmfox\Watch\Service;

use Neos\Flow\Annotations as Flow;

/**
 * Rozmiar instalacji liczony realnie, ale z twardymi limitami czasu i liczby
 * plików. Nie szacujemy i nie zgadujemy: na hostingu, gdzie limit konta jest
 * nieznany, ta jedna liczba jest jedyną prawdą, jaką możemy podać klientowi.
 *
 * Przy przekroczeniu limitu zwracamy tyle, ile zdążyliśmy policzyć, i mówimy
 * o tym w opisie checku. Wynik żyje dobę, bo rozmiar instalacji nie zmienia
 * się co minutę, a przejście po całym drzewie plików jest najdroższą rzeczą,
 * jaką ten pakiet w ogóle robi.
 */
#[Flow\Scope('singleton')]
class InstallationSize
{
    private const MAX_SECONDS = 3.0;
    private const MAX_FILES = 200000;

    #[Flow\Inject]
    protected Cache $cache;

    /** @return array{bytes: int, complete: bool} */
    public function measure(): array
    {
        $cached = $this->cache->get(Cache::INSTALL_SIZE);
        if (\is_array($cached) && isset($cached['bytes'])) {
            return ['bytes' => (int) $cached['bytes'], 'complete' => (bool) ($cached['complete'] ?? true)];
        }

        $result = $this->scan($this->root());
        $this->cache->set(Cache::INSTALL_SIZE, $result, 86400);

        return $result;
    }

    /** @return array{bytes: int, complete: bool} */
    private function scan(string $root): array
    {
        $bytes = 0;
        $files = 0;
        $complete = true;
        $deadline = microtime(true) + self::MAX_SECONDS;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $bytes += $file->getSize();
                }
                ++$files;
                if ($files >= self::MAX_FILES || (0 === $files % 2000 && microtime(true) > $deadline)) {
                    $complete = false;
                    break;
                }
            }
        } catch (\Throwable) {
            // Nieczytelny katalog nie może wywrócić checku: oddajemy to, co policzone.
            $complete = false;
        }

        return ['bytes' => $bytes, 'complete' => $complete];
    }

    private function root(): string
    {
        return \defined('FLOW_PATH_ROOT') ? (string) \constant('FLOW_PATH_ROOT') : getcwd().'/';
    }
}
