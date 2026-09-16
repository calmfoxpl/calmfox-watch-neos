<?php

declare(strict_types=1);

namespace Calmfox\Watch\Health;

use Calmfox\Watch\Core\DiskVerdict;
use Calmfox\Watch\Service\InstallationSize;
use Calmfox\Watch\Service\StateProvider;
use Neos\Flow\Annotations as Flow;

/**
 * Miejsce na dysku, z tą samą uczciwością co we wtyczce WordPressa. Na hostingu
 * współdzielonym disk_free_space() podaje CAŁY wolumen serwera (widzieliśmy
 * „wolne 4,8 TB" na koncie z kilkoma GB limitu), więc takiej liczby nie
 * pokazujemy. Kolejność źródeł: limit podany przez klienta, wiarygodny odczyt
 * systemowy, na końcu uczciwe „hosting nie pokazuje limitu tego konta".
 *
 * Osobno i twardo: zapisywalność Data/Persistent i Web/_Resources. Bez pierwszego
 * nie da się wgrać ani jednego pliku do strony, bez drugiego przy najbliższym
 * czyszczeniu pamięci podręcznej strona zostanie bez arkuszy stylów i skryptów.
 * To jest awaria, nie ostrzeżenie.
 */
#[Flow\Scope('singleton')]
class DiskCheck implements HealthCheckInterface
{
    #[Flow\Inject]
    protected StateProvider $stateProvider;

    #[Flow\Inject]
    protected InstallationSize $installationSize;

    public function run(): ?array
    {
        $label = 'Miejsce na dysku';

        $notWritable = [];
        foreach ($this->criticalDirectories() as $name => $path) {
            // Katalog może jeszcze nie istnieć (świeże wdrożenie przed pierwszą
            // publikacją zasobów). Wtedy pytamy o katalog nadrzędny, bo to on
            // decyduje, czy Flow zdoła go w ogóle utworzyć.
            $target = is_dir($path) ? $path : \dirname($path);
            if (!is_dir($target) || !is_writable($target)) {
                $notWritable[] = $name;
            }
        }
        if ([] !== $notWritable) {
            return ['id' => 'disk', 'status' => 'fail', 'label' => $label,
                'detail' => sprintf('Katalogi %s nie są zapisywalne. Wgrywanie plików i publikacja zasobów strony będą kończyć się błędem.', implode(', ', $notWritable))];
        }

        $size = $this->installationSize->measure();
        $used = $size['bytes'];
        $usedLabel = 'Sama instalacja zajmuje '.DiskVerdict::format((float) $used)
            .($size['complete'] ? '' : ' (liczone do limitu czasu, więc realny rozmiar może być większy)');

        // 1) Limit konta podany przez klienta: jedyna pewna liczba na hostingu współdzielonym.
        $quotaGb = (float) $this->stateProvider->state()->get('diskQuotaGb', 0.0);
        if ($quotaGb > 0) {
            $limit = $quotaGb * 1024 * 1024 * 1024;

            return ['id' => 'disk', 'status' => DiskVerdict::fromQuota($used, $limit), 'label' => $label,
                'detail' => sprintf('%s z podanego limitu konta %s (%d%%). Poza tym miejsce zajmują poczta i pozostałe strony na koncie.',
                    $usedLabel, DiskVerdict::format($limit), DiskVerdict::percentUsed($used, $limit))];
        }

        $directory = $this->dataPath();
        $free = @disk_free_space($directory);
        $total = @disk_total_space($directory);

        // 2) Odczyt systemowy tylko wtedy, gdy naprawdę dotyczy tego konta (serwer własny albo VPS).
        if (false !== $free && false !== $total && $total > 0 && !DiskVerdict::readingIsShared((float) $total, (string) ini_get('open_basedir'))) {
            return ['id' => 'disk', 'status' => DiskVerdict::fromFree((float) $free, (float) $total), 'label' => $label,
                'detail' => sprintf('Wolne %s z %s (%d%%). %s.',
                    DiskVerdict::format((float) $free), DiskVerdict::format((float) $total),
                    (int) floor($free / $total * 100), $usedLabel)];
        }

        // 3) Hosting współdzielony bez limitu od klienta: mówimy wprost, czego nie wiemy.
        return ['id' => 'disk', 'status' => 'ok', 'label' => $label,
            'detail' => sprintf('%s. Hosting nie pokazuje limitu tego konta (widzimy tylko wspólny dysk serwera), więc nie liczymy zajętości. Podaj limit w module Calmfox Watch, a będziemy go pilnować.', $usedLabel)];
    }

    /** @return array<string, string> */
    private function criticalDirectories(): array
    {
        return [
            'Data/Persistent' => $this->dataPath().'Persistent',
            'Web/_Resources' => $this->webPath().'_Resources',
        ];
    }

    private function dataPath(): string
    {
        return \defined('FLOW_PATH_DATA') ? (string) \constant('FLOW_PATH_DATA') : getcwd().'/Data/';
    }

    private function webPath(): string
    {
        return \defined('FLOW_PATH_WEB') ? (string) \constant('FLOW_PATH_WEB') : getcwd().'/Web/';
    }
}
