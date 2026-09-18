<?php

declare(strict_types=1);

namespace Calmfox\Watch\Core;

/**
 * Geometria pierścienia kondycji: z obszarów oceny liczy łuki SVG do narysowania.
 *
 * Pierścień jest tym samym rysunkiem, co w panelu (`src/app/shared/score-ring.ts`, wariant
 * `card`) i w aplikacji mobilnej. Klient ogląda oba tego samego dnia, więc liczby geometrii
 * i barwy są tu PRZEPISANE Z PANELU, a nie dobrane na oko. Zmiana któregokolwiek z tych
 * stałych bez zmiany w panelu rozjeżdża rysunek między ekranami.
 *
 * Klasa jest świadomie bez Flow i bez Neosa — tak jak reszta rdzenia pakietu — więc
 * da się ją sprawdzić testem bez kontenera obiektów, bazy i repozytorium treści.
 */
final class ScoreRing
{
    /** Pole rysunku SVG (viewBox), kwadrat. Środek pierścienia leży w jego środku. */
    public const BOX = 220;

    /** Promień linii środkowej łuku i grubość kreski — razem dają zewnętrzną krawędź. */
    private const RADIUS = 92;

    /**
     * Grubość kreski jest PUBLICZNA, bo rysuje nią szablon modułu: Fluid nie umie wstawić
     * liczby z klasy inaczej niż przez zmienną widoku, a przepisana na sztywno w szablonie
     * rozjechałaby się z geometrią przy pierwszej zmianie pierścienia.
     */
    public const WIDTH = 22;

    /**
     * WIDOCZNA przerwa między łukami, w stopniach. Sama różnica kątów nie wystarczy:
     * zaokrąglony koniec łuku wystaje poza swój kąt o pół grubości kreski i zjada odstęp,
     * aż sąsiednie obszary zlewają się w jeden pierścień. Dlatego każdy łuk jest dodatkowo
     * skracany o ten naddatek (patrz self::cap()) po obu stronach.
     */
    private const GAP = 6.0;

    /**
     * Barwy obszarów na CIEMNYM tle. Moduł Neosa jest jedynym z naszych pakietów, który
     * stoi na ciemnym panelu (WordPress, Magento i Sylius mają panele białe i biorą
     * wariant jasny), więc bierze tu `SCORE_AREA_COLORS` z panelu — ten sam wariant,
     * który panel Calmfox Watch pokazuje w motywie ciemnym.
     *
     * Odcienie stoją w TYM SAMYM miejscu koła barw, co wariant jasny: turkus zostaje
     * turkusem, błękit błękitem, więc legenda z panelu zgadza się z tą tutaj, a klient
     * ogląda oba ekrany tego samego dnia. Zmiana barwy obszaru idzie do panelu, do
     * aplikacji mobilnej, do widżetu zegarka i do rdzenia KAŻDEGO pakietu CMS naraz
     * albo do żadnego.
     *
     * Żadna z tych barw nie może być zielona, bursztynowa ani czerwona: te trzy niosą
     * w całym produkcie STAN (sprawne / uwaga / awaria), a obszar to tożsamość, nie ocena.
     */
    private const COLORS = [
        'availability' => '#2dd4bf',
        'security' => '#ce9fbc',
        'updates' => '#60a5fa',
        'correctness' => '#e879f9',
        'performance' => '#a78bfa',
    ];

    /** Obszar spoza listy dostaje szarość, a nie błąd: hub może dołożyć nowy przed pakietem. */
    private const COLOR_FALLBACK = '#989abc';

    /**
     * Tor łuku, czyli „ile mogło być punktów", w szarości panelu Neosa. Panel Calmfox
     * Watch używa tu swojego `corporate-800`, ale ma granatowe tło — na szarym panelu
     * Neosa granat czytałby się jak przebarwienie, a nie jak tor.
     */
    public const TRACK_COLOR = '#3f3f3f';

    /**
     * Łuki do narysowania, w kolejności podanych obszarów.
     *
     * Każdy element: `d` (ścieżka toru), `length` (długość łuku), `fill` (długość wypełnienia),
     * `color`, `label`, `area`, `score`, `measured`. Szablon rysuje tor ścieżką `d`, a wypełnienie
     * tą samą ścieżką z `stroke-dasharray="{fill} {length+1}"` — jedno przejście, bez JavaScriptu.
     *
     * Obszar NIEZMIERZONY zostaje samym torem (`fill` = 0.0): kropka w barwie obszaru
     * sugerowałaby, że coś tam zmierzono i wyszło zero.
     *
     * @param list<array<string, mixed>> $areas obszary z odpowiedzi huba (`areas` w ocenie)
     *
     * @return list<array<string, mixed>>
     */
    public static function segments(array $areas): array
    {
        if ([] === $areas) {
            return [];
        }

        $total = 0.0;
        foreach ($areas as $area) {
            $total += max(0.0, (float) ($area['weight'] ?? 0));
        }
        // Same zera w wagach (stary hub albo obszar bez wagi) dają równe łuki zamiast dzielenia
        // przez zero: rysunek jest wtedy mniej dokładny, ale ekran się nie wywraca.
        $rowne = $total <= 0.0;
        if ($rowne) {
            $total = (float) \count($areas);
        }

        $usable = 360.0 - self::GAP * \count($areas);
        $cap = self::cap();
        $angle = -90.0 + self::GAP / 2;

        $segments = [];
        foreach ($areas as $area) {
            $weight = $rowne ? 1.0 : max(0.0, (float) ($area['weight'] ?? 0));
            $span = ($weight / $total) * $usable;

            $from = $angle + $cap;
            $to = $angle + $span - $cap;
            $drawn = max(0.0, $to - $from);
            $length = ($drawn * M_PI * self::RADIUS) / 180;

            // Pomiar PRZETERMINOWANY nie jest pomiarem: hub liczy tak samo, gdy zasila
            // pierścień w panelu (`SiteScore`, widok chudy), a pierścień ma być w obu
            // miejscach ten sam. Bez tego obszar nieświeży wyglądałby tu na zmierzony.
            $measured = (bool) ($area['measured'] ?? false) && !($area['stale'] ?? false);
            $score = min(100.0, max(0.0, (float) ($area['score'] ?? 0)));

            $segments[] = [
                'area' => (string) ($area['area'] ?? ''),
                'label' => (string) ($area['label'] ?? ($area['area'] ?? '')),
                'score' => $measured ? (int) round($score) : null,
                'measured' => $measured,
                'd' => self::arc($from, $to),
                'length' => round($length, 2),
                'fill' => $measured ? round($length * $score / 100, 2) : 0.0,
                'color' => self::COLORS[(string) ($area['area'] ?? '')] ?? self::COLOR_FALLBACK,
            ];

            $angle += $span + self::GAP;
        }

        return $segments;
    }

    /**
     * Pierścień ZASTĘPCZY: pięć torów w prawdziwych proporcjach, żaden nie wypełniony.
     *
     * Stoi tam, gdzie na progu Free nie ma oceny. Pokazuje KSZTAŁT tego, co klient dostanie,
     * i nie udaje pomiaru: wszystkie łuki są niezmierzone, więc rysują się kreskowanym torem
     * i bez liczby w środku. To jedyne miejsce, w którym pakiet rysuje pierścień bez danych
     * z huba — i wolno mu, bo nie pokazuje wtedy ŻADNEJ liczby o stanie strony.
     *
     * Wagi są te same co w ocenie (30/25/20/15/10), żeby rysunek zapowiadał ten właściwy,
     * a nie inny.
     *
     * @return list<array<string, mixed>>
     */
    public static function placeholder(): array
    {
        return self::segments([
            ['area' => 'availability', 'weight' => 30, 'measured' => false],
            ['area' => 'security', 'weight' => 25, 'measured' => false],
            ['area' => 'updates', 'weight' => 20, 'measured' => false],
            ['area' => 'correctness', 'weight' => 15, 'measured' => false],
            ['area' => 'performance', 'weight' => 10, 'measured' => false],
        ]);
    }

    /** Barwa obszaru — dla legendy pod pierścieniem, żeby nie powtarzać tablicy w szablonie. */
    public static function color(string $area): string
    {
        return self::COLORS[$area] ?? self::COLOR_FALLBACK;
    }

    /** O ile stopni zaokrąglony koniec kreski wystaje poza kąt łuku. */
    private static function cap(): float
    {
        return asin(self::WIDTH / 2 / self::RADIUS) * 180 / M_PI;
    }

    private static function arc(float $from, float $to): string
    {
        [$x1, $y1] = self::point($from);
        [$x2, $y2] = self::point($to);
        $large = ($to - $from) > 180 ? 1 : 0;

        return sprintf('M %s %s A %d %d 0 %d 1 %s %s', $x1, $y1, self::RADIUS, self::RADIUS, $large, $x2, $y2);
    }

    /** @return array{0: string, 1: string} */
    private static function point(float $angle): array
    {
        $rad = $angle * M_PI / 180;
        $centre = self::BOX / 2;

        return [
            number_format($centre + self::RADIUS * cos($rad), 2, '.', ''),
            number_format($centre + self::RADIUS * sin($rad), 2, '.', ''),
        ];
    }
}
