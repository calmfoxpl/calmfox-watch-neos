<?php

declare(strict_types=1);

namespace Calmfox\Watch\Tests\Unit;

use Calmfox\Watch\Core\ScoreRing;
use PHPUnit\Framework\TestCase;

/**
 * Pierścień ma być TYM SAMYM rysunkiem, co w panelu. Te testy pilnują dwóch rzeczy:
 * że geometria zgadza się z panelem co do liczby, i że wypełnienie nie kłamie o tym,
 * czego nie zmierzono.
 */
final class ScoreRingTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function obszary(): array
    {
        return [
            ['area' => 'availability', 'label' => 'Działanie', 'score' => 100, 'measured' => true, 'weight' => 30],
            ['area' => 'security', 'label' => 'Zaufanie i bezpieczeństwo', 'score' => 60, 'measured' => true, 'weight' => 25],
            ['area' => 'updates', 'label' => 'Aktualność', 'score' => 80, 'measured' => true, 'weight' => 20],
            ['area' => 'correctness', 'label' => 'Poprawność', 'score' => 0, 'measured' => false, 'weight' => 15],
            ['area' => 'performance', 'label' => 'Wydajność', 'score' => 45, 'measured' => true, 'weight' => 10],
        ];
    }

    public function testEmptyScoreDrawsNothing(): void
    {
        self::assertSame([], ScoreRing::segments([]));
    }

    /** Łuki wypełniają pełny obrót: suma rozpiętości plus przerwy to dokładnie 360°. */
    public function testArcsFillTheWholeCircle(): void
    {
        $segments = ScoreRing::segments(self::obszary());
        self::assertCount(5, $segments);

        // Długość łuku wraca do kąta: stopnie = długość * 180 / (PI * promień).
        $stopnie = 0.0;
        foreach ($segments as $segment) {
            $stopnie += $segment['length'] * 180 / (M_PI * 92);
        }

        // Każdy łuk jest skrócony o naddatek zaokrąglonych końców z OBU stron, a między
        // łukami stoi przerwa 6°. Naddatek liczy panel tak samo: asin(szerokość/2 / promień).
        $cap = asin(22 / 2 / 92) * 180 / M_PI;
        $oczekiwane = 360.0 - 5 * 6.0 - 5 * 2 * $cap;

        self::assertEqualsWithDelta($oczekiwane, $stopnie, 0.05);
    }

    /** Szerokość łuku idzie z wagi obszaru, a nie z jego wyniku. */
    public function testArcWidthFollowsWeightNotScore(): void
    {
        $segments = ScoreRing::segments(self::obszary());

        // Działanie ma wagę 30, Wydajność 10 — łuk ma być trzy razy dłuższy, mimo że
        // wynik Wydajności (45) jest niższy niż Działania (100).
        $dzialanie = $segments[0]['length'] + 2 * self::capLength();
        $wydajnosc = $segments[4]['length'] + 2 * self::capLength();

        self::assertEqualsWithDelta(3.0, $dzialanie / $wydajnosc, 0.02);
    }

    /** Wypełnienie to wynik obszaru przeliczony na długość łuku. */
    public function testFillIsTheAreaScore(): void
    {
        $segments = ScoreRing::segments(self::obszary());

        // 100 punktów = cały łuk, 60 = 60% łuku.
        self::assertEqualsWithDelta($segments[0]['length'], $segments[0]['fill'], 0.01);
        self::assertEqualsWithDelta($segments[1]['length'] * 0.6, $segments[1]['fill'], 0.01);
    }

    /**
     * Obszar niezmierzony zostaje samym torem. To nie jest kosmetyka: wypełnienie w barwie
     * obszaru znaczy „zmierzone i tyle wyszło", więc na niezmierzonym kłamałoby o pomiarze.
     */
    public function testUnmeasuredAreaGetsNoFillAndNoNumber(): void
    {
        $segments = ScoreRing::segments(self::obszary());

        self::assertFalse($segments[3]['measured']);
        self::assertSame(0.0, $segments[3]['fill']);
        self::assertNull($segments[3]['score'], 'niezmierzony obszar nie ma prawa pokazać liczby');
        self::assertGreaterThan(0, $segments[3]['length'], 'ale tor ma być widoczny: mówi, ile mogło być');
    }

    /**
     * Obszar zmierzony, ale NIEŚWIEŻY, rysuje się jak niezmierzony. Tak samo liczy hub,
     * kiedy zasila pierścień w panelu (`SiteScore`, widok chudy: `measured && !stale`),
     * a pierścień ma być w obu miejscach ten sam rysunek.
     */
    public function testStaleAreaCountsAsUnmeasured(): void
    {
        $segments = ScoreRing::segments([
            ['area' => 'performance', 'score' => 90, 'measured' => true, 'stale' => true, 'weight' => 1],
        ]);

        self::assertFalse($segments[0]['measured']);
        self::assertSame(0.0, $segments[0]['fill'], 'nieświeży pomiar nie ma prawa wypełnić łuku');
        self::assertNull($segments[0]['score']);
    }

    /** Ocena spoza zakresu nie wyjeżdża poza łuk. */
    public function testScoreOutsideRangeIsClamped(): void
    {
        $segments = ScoreRing::segments([
            ['area' => 'security', 'score' => 250, 'measured' => true, 'weight' => 1],
            ['area' => 'updates', 'score' => -40, 'measured' => true, 'weight' => 1],
        ]);

        self::assertEqualsWithDelta($segments[0]['length'], $segments[0]['fill'], 0.01);
        self::assertSame(0.0, $segments[1]['fill']);
    }

    /**
     * Stary hub nie przysyła wag. Rysunek jest wtedy mniej dokładny, ale ekran pakietu
     * nie ma prawa się wywrócić na dzieleniu przez zero.
     */
    public function testAreasWithoutWeightsFallBackToEqualArcs(): void
    {
        $segments = ScoreRing::segments([
            ['area' => 'security', 'score' => 50, 'measured' => true],
            ['area' => 'updates', 'score' => 50, 'measured' => true],
            ['area' => 'performance', 'score' => 50, 'measured' => true],
        ]);

        self::assertCount(3, $segments);
        self::assertEqualsWithDelta($segments[0]['length'], $segments[1]['length'], 0.01);
        self::assertEqualsWithDelta($segments[1]['length'], $segments[2]['length'], 0.01);
    }

    /** Obszar, którego pakiet jeszcze nie zna, dostaje szarość zamiast wyjątku. */
    public function testUnknownAreaGetsTheFallbackColour(): void
    {
        $segments = ScoreRing::segments([['area' => 'cos-nowego', 'score' => 10, 'measured' => true, 'weight' => 1]]);

        self::assertSame('#989abc', $segments[0]['color']);
    }

    /** Barwy obszarów są przepisane z panelu i nie wolno ich tu rozjechać. */
    public function testAreaColoursMatchThePanel(): void
    {
        // Wariant CIEMNY: panel Neosa jest ciemny, w odróżnieniu od paneli pozostałych
        // trzech pakietów. Te same miejsca koła barw, co w wariancie jasnym.
        self::assertSame('#2dd4bf', ScoreRing::color('availability'));
        self::assertSame('#ce9fbc', ScoreRing::color('security'));
        self::assertSame('#60a5fa', ScoreRing::color('updates'));
        self::assertSame('#e879f9', ScoreRing::color('correctness'));
        self::assertSame('#a78bfa', ScoreRing::color('performance'));
    }

    /** Ścieżka łuku ma być poprawnym SVG: „M x y A r r 0 flaga 1 x y”. */
    public function testArcPathIsWellFormed(): void
    {
        foreach (ScoreRing::segments(self::obszary()) as $segment) {
            self::assertMatchesRegularExpression(
                '/^M -?\d+\.\d{2} -?\d+\.\d{2} A 92 92 0 [01] 1 -?\d+\.\d{2} -?\d+\.\d{2}$/',
                $segment['d']
            );
        }
    }

    /** Pierwszy łuk zaczyna się u góry pierścienia, tak samo jak w panelu. */
    public function testFirstArcStartsAtTheTop(): void
    {
        $segments = ScoreRing::segments(self::obszary());
        preg_match('/^M (-?[\d.]+) (-?[\d.]+)/', $segments[0]['d'], $m);

        // Kąt startowy to -90° + połowa przerwy + naddatek końca, czyli tuż za godziną 12.
        $cap = asin(22 / 2 / 92) * 180 / M_PI;
        $kat = -90.0 + 3.0 + $cap;
        self::assertEqualsWithDelta(110 + 92 * cos($kat * M_PI / 180), (float) $m[1], 0.01);
        self::assertEqualsWithDelta(110 + 92 * sin($kat * M_PI / 180), (float) $m[2], 0.01);
    }

    /**
     * Wzorzec wprost z panelu. Liczby poniżej to wynik `segments()` z
     * `src/app/shared/score-ring.ts` (wariant `card`) dla tych samych obszarów, przepisany
     * po porównaniu obu implementacji — zgadzały się co do znaku, ze ścieżkami SVG włącznie.
     *
     * Ten test istnieje po to, żeby rozjazd z panelem wyszedł TUTAJ, a nie na ekranie klienta,
     * który ogląda pierścień w panelu i w sklepie tego samego dnia. Jeśli zacznie padać po
     * zmianie w panelu, to nie jest powód, żeby podmienić liczby — to powód, żeby sprawdzić,
     * czy zmiana w panelu miała pójść także do pakietów.
     */
    public function testGeometryMatchesThePanelExactly(): void
    {
        $wzorzec = [
            ['M 125.77 19.36 A 92 92 0 0 1 201.63 118.23', 136.91, 136.91],
            ['M 193.47 148.69 A 92 92 0 0 1 104.17 201.82', 110.42, 66.25],
            ['M 73.51 194.45 A 92 92 0 0 1 20.88 132.83', 83.92, 67.14],
            ['M 18.40 101.39 A 92 92 0 0 1 40.71 49.48', 57.43, 0.0],
            ['M 65.21 29.64 A 92 92 0 0 1 94.23 19.36', 30.94, 13.92],
        ];

        $segments = ScoreRing::segments(self::obszary());

        foreach ($wzorzec as $i => [$d, $length, $fill]) {
            self::assertSame($d, $segments[$i]['d'], sprintf('ścieżka łuku %d rozjechała się z panelem', $i));
            self::assertSame($length, $segments[$i]['length'], sprintf('długość łuku %d', $i));
            self::assertEqualsWithDelta($fill, $segments[$i]['fill'], 0.005, sprintf('wypełnienie łuku %d', $i));
        }
    }

    /**
     * Pierścień zastępczy stoi tam, gdzie na progu Free nie ma oceny. Ma pokazywać KSZTAŁT
     * przyszłego rysunku i nie wolno mu udawać pomiaru: żaden łuk nie jest wypełniony
     * i żaden nie ma liczby.
     */
    public function testPlaceholderShowsShapeWithoutPretendingToMeasure(): void
    {
        $segments = ScoreRing::placeholder();

        self::assertCount(5, $segments);
        foreach ($segments as $segment) {
            self::assertFalse($segment['measured']);
            self::assertSame(0.0, $segment['fill'], 'zastępczy łuk nie ma prawa być wypełniony');
            self::assertNull($segment['score'], 'zastępczy łuk nie ma prawa pokazać liczby');
            self::assertGreaterThan(0, $segment['length']);
        }
    }

    /** Proporcje zastępcze są te same co w ocenie — rysunek zapowiada ten właściwy. */
    public function testPlaceholderKeepsTheRealWeights(): void
    {
        $zastepczy = ScoreRing::placeholder();
        $prawdziwy = ScoreRing::segments(self::obszary());

        foreach ($prawdziwy as $i => $segment) {
            self::assertSame($segment['length'], $zastepczy[$i]['length'], sprintf('łuk %d ma inną szerokość', $i));
            self::assertSame($segment['area'], $zastepczy[$i]['area']);
        }
    }

    /** Długość, o jaką zaokrąglony koniec skraca łuk po jednej stronie. */
    private static function capLength(): float
    {
        return (asin(22 / 2 / 92) * 180 / M_PI) * M_PI * 92 / 180;
    }

    /**
     * Regresja z pisania modułu: szablon rysuje tor grubością z tej stałej, a prywatna
     * stała wywraca ekran dopiero na żywym panelu — testy rdzenia by tego nie dotknęły.
     */
    public function testGruboscKreskiJestDostepnaDlaSzablonu(): void
    {
        self::assertTrue((new \ReflectionClassConstant(ScoreRing::class, 'WIDTH'))->isPublic());
        self::assertTrue((new \ReflectionClassConstant(ScoreRing::class, 'BOX'))->isPublic());
        self::assertTrue((new \ReflectionClassConstant(ScoreRing::class, 'TRACK_COLOR'))->isPublic());
    }
}
