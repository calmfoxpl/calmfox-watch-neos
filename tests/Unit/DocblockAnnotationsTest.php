<?php

declare(strict_types=1);

namespace Calmfox\Watch\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regresja z produkcji (2026-08-19): przykład w komentarzu zawierał
 * „małpę" przed fsockopen, Doctrine potraktował to jak nieznaną adnotację
 * i kompilacja klas proxy padła. Skutek był nieproporcjonalny do przyczyny:
 * cała strona oddawała HTTP 500, nie tylko nasz adres kontrolny.
 *
 * Flow przepuszcza przez parser adnotacji KAŻDY docblok w pakiecie, więc test
 * czyta wszystkie i przepuszcza tylko znane znaczniki. Nowy komentarz z „małpą"
 * zapali się tutaj, a nie na stronie klienta.
 */
final class DocblockAnnotationsTest extends TestCase
{
    /**
     * Znaczniki, które parser Doctrine zna albo świadomie pomija. Lista jest
     * krótka celowo: łatwiej dopisać wyjątek po namyśle, niż tłumaczyć klientowi,
     * czemu strona nie wstała po wdrożeniu.
     */
    private const ALLOWED = [
        'param', 'return', 'var', 'throws', 'see', 'link', 'todo', 'api',
        'internal', 'deprecated', 'author', 'license', 'package', 'inheritdoc',
        'template', 'phpstan-param', 'phpstan-return', 'psalm-param', 'psalm-return',
        'Flow', 'ORM', 'Validate',
    ];

    public function testDocblokiNieZawierajaNieznanychAdnotacji(): void
    {
        $found = [];
        foreach ($this->phpFiles(\dirname(__DIR__, 2).'/Classes') as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $number => $line) {
                if (1 !== preg_match('/^\s*\*/', $line)) {
                    continue; // tylko wnętrze docbloków, kod może mieć operator wyciszania
                }
                if (0 === preg_match_all('/@([A-Za-z_][A-Za-z0-9_\\\\-]*)/', $line, $matches)) {
                    continue;
                }
                foreach ($matches[1] as $tag) {
                    if (\in_array($tag, self::ALLOWED, true) || str_starts_with($tag, 'Flow\\')) {
                        continue;
                    }
                    $found[] = sprintf('%s:%d → @%s', basename($file), $number + 1, $tag);
                }
            }
        }

        self::assertSame([], $found, "Nieznany znacznik w docbloku wywróci kompilację klas proxy w Flow:\n".implode("\n", $found));
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
