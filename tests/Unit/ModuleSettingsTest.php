<?php

declare(strict_types=1);

namespace Calmfox\Watch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Regresja z produkcji (2026-08-19): moduł prosił o arkusz „lite", a plik
 * w Neosie nazywa się „Lite.css". Ramka modułu skleja tę wartość w ścieżkę
 * resource://Neos.Neos/Styles/<nazwa>.css, więc literówka dawała 404 i ekran
 * renderował się bez ŻADNEGO stylu. Nic tego nie zgłaszało: strona wstawała,
 * odpowiadała kodem 200, tylko wyglądała jak surowy HTML.
 */
final class ModuleSettingsTest extends TestCase
{
    /** Arkusze, które Neos naprawdę ma (Resources/Public/Styles) w 8.3 i 9.1. */
    private const KNOWN_STYLESHEETS = ['Main', 'Lite', 'Minimal'];

    /** @return array<string, array<string, mixed>> grupa modułu i jej podmoduły, po jednym wpisie na ekran */
    private function modules(): array
    {
        $settings = Yaml::parseFile(\dirname(__DIR__, 2).'/Configuration/Settings.yaml');
        $group = $settings['Neos']['Neos']['modules']['calmfoxWatch'] ?? null;

        self::assertIsArray($group, 'moduł zniknął z ustawień, panel nie pokaże ekranu');

        $modules = ['calmfoxWatch' => $group];
        foreach ($group['submodules'] ?? [] as $name => $submodule) {
            $modules['calmfoxWatch/'.$name] = $submodule;
        }

        return $modules;
    }

    public function testModulProsiOIstniejacyArkuszPanelu(): void
    {
        foreach ($this->modules() as $path => $module) {
            self::assertContains($module['mainStylesheet'] ?? '', self::KNOWN_STYLESHEETS,
                sprintf('nazwa arkusza panelu (%s) musi zgadzać się CO DO WIELKOŚCI LITER z plikiem w Neos.Neos', $path));
        }
    }

    public function testModulWskazujeIstniejacyKontrolerIPrzywilej(): void
    {
        $policy = Yaml::parseFile(\dirname(__DIR__, 2).'/Configuration/Policy.yaml');

        foreach ($this->modules() as $path => $module) {
            // Sprawdzamy PLIK, nie class_exists: załadowanie kontrolera wciągnęłoby
            // klasy bazowe Neosa, których w samodzielnych testach nie ma.
            $relative = str_replace('Calmfox\\Watch\\', '', $module['controller']);
            $file = \dirname(__DIR__, 2).'/Classes/'.str_replace('\\', '/', $relative).'.php';
            self::assertFileExists($file, sprintf('moduł %s wskazuje kontroler, którego nie ma w pakiecie', $path));
            self::assertArrayHasKey($module['privilegeTarget'],
                $policy['privilegeTargets']['Neos\Flow\Security\Authorization\Privilege\Method\MethodPrivilege'],
                sprintf('moduł %s wskazuje przywilej, którego nie ma w Policy.yaml', $path));
        }
    }

    /**
     * Moduł ma być w GŁÓWNYM menu panelu, nie pod „Zarządzaniem". To jest
     * decyzja produktowa, nie kosmetyka: monitoring, do którego trzeba się
     * doklikać, ogląda wyłącznie ten, kto go szuka.
     */
    public function testModulStoiWlasnaGrupaWMenuGlownym(): void
    {
        $settings = Yaml::parseFile(\dirname(__DIR__, 2).'/Configuration/Settings.yaml');
        $modules = $settings['Neos']['Neos']['modules'];

        self::assertArrayHasKey('calmfoxWatch', $modules, 'grupa modułu zniknęła z pierwszego poziomu menu');
        self::assertArrayNotHasKey('calmfoxWatch', $modules['management']['submodules'] ?? [],
            'moduł wrócił pod „Zarządzanie", a ma stać w menu głównym');
        self::assertNotEmpty($modules['calmfoxWatch']['submodules'] ?? [],
            'grupa bez podmodułu renderuje się w panelu jako nagłówek bez treści');
    }
}
