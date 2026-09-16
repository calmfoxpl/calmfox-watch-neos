<?php

declare(strict_types=1);

namespace Calmfox\Watch\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regresja: niedomknięty (albo zduplikowany) znacznik Fluid to błąd składni,
 * czyli HTTP 500 na ekranie modułu. Ekran renderuje się dopiero po zalogowaniu
 * do panelu, więc żaden test integracyjny po stronie serwera go nie dotknie,
 * a wdrożenie wygląda na udane: strona stoi, adres kontrolny odpowiada.
 *
 * Ten test nie zastępuje renderowania, sprawdza jedną konkretną rzecz, na której
 * naprawdę się przewróciłem: bilans znaczników. Złapał zduplikowany <f:if>
 * wstawiony przy dokładaniu kafli stanu, ZANIM ktokolwiek otworzył ekran.
 */
final class TemplateStructureTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        yield 'moduł' => ['Resources/Private/Templates/Module/Watch/Index.html'];
        yield 'partial sprawdzeń' => ['Resources/Private/Partials/Module/Checks.html'];
    }

    #[DataProvider('templates')]
    public function testZnacznikiFluidSaZbilansowane(string $relative): void
    {
        $path = \dirname(__DIR__, 2).'/'.$relative;
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        $balance = [];
        preg_match_all('~<(/?)(f:[a-zA-Z.]+)([^>]*?)(/?)>~', $source, $matches, \PREG_SET_ORDER);
        foreach ($matches as [, $closing, $name, , $selfClosing]) {
            if ('/' === $selfClosing) {
                continue; // <f:layout ... /> zamyka się samo
            }
            $balance[$name] = ($balance[$name] ?? 0) + ('' === $closing ? 1 : -1);
        }

        foreach ($balance as $tag => $count) {
            self::assertSame(0, $count, sprintf('%s: znacznik %s nie jest zbilansowany (%+d)', $relative, $tag, $count));
        }
    }

    /**
     * Znaczniki, które mają sens WYŁĄCZNIE wewnątrz rodzica. Fluid nie mówi tego
     * wprost przy renderowaniu, tylko wywraca się komunikatem o niczym.
     */
    #[DataProvider('templates')]
    public function testZnacznikiZalezneMajaSwojegoRodzica(string $relative): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/'.$relative);

        foreach (['f:then' => 'f:if', 'f:else' => 'f:if', 'f:case' => 'f:switch', 'f:defaultCase' => 'f:switch'] as $child => $parent) {
            if (!str_contains($source, '<'.$child)) {
                continue;
            }
            self::assertStringContainsString('<'.$parent, $source, sprintf('%s: %s bez %s', $relative, $child, $parent));
        }
    }
}
