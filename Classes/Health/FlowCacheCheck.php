<?php

declare(strict_types=1);

namespace Calmfox\Watch\Health;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cache\CacheManager;

/**
 * Pamięć podręczna Flow. Sprawdzamy ją zapisem i odczytem klucza kontrolnego,
 * a nie samym „czy obiekt istnieje": Redis potrafi przyjąć połączenie i odmówić
 * zapisu (przepełniona pamięć, tryb tylko do odczytu po awarii repliki),
 * a katalog Data/Temporary potrafi stać się niezapisywalny po wdrożeniu
 * z niewłaściwymi prawami. W obu przypadkach strona zaczyna liczyć wszystko
 * od nowa przy każdym żądaniu i pada pod własnym ciężarem.
 *
 * W etykiecie podajemy realny mechanizm składowania, jeśli da się go rozpoznać:
 * „Redis nie odpowiada" i „katalog pamięci podręcznej jest tylko do odczytu"
 * to dwie różne rozmowy z hostingodawcą.
 */
#[Flow\Scope('singleton')]
class FlowCacheCheck implements HealthCheckInterface
{
    private const PROBE_CACHE = 'Calmfox_Watch_Payload';

    #[Flow\Inject]
    protected CacheManager $cacheManager;

    public function run(): ?array
    {
        $start = microtime(true);
        try {
            $cache = $this->cacheManager->getCache(self::PROBE_CACHE);
            $backend = $this->backendLabel($cache->getBackend()::class);

            $expected = bin2hex(random_bytes(8));
            $cache->set('probe', $expected, [], 60);
            $actual = $cache->get('probe');
            $ms = (int) round((microtime(true) - $start) * 1000);

            $ok = $expected === $actual;

            return [
                'id' => 'flow_cache',
                'status' => $ok ? 'ok' : 'fail',
                'label' => sprintf('Pamięć podręczna Flow (%s)', $backend),
                'detail' => $ok ? null : 'Zapis i odczyt klucza kontrolnego nie zgadzają się. Strona przelicza wszystko od nowa przy każdym wejściu.',
                'ms' => $ms,
            ];
        } catch (\Throwable $exception) {
            return [
                'id' => 'flow_cache',
                'status' => 'fail',
                'label' => 'Pamięć podręczna Flow',
                'detail' => $exception->getMessage(),
                'ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        }
    }

    private function backendLabel(string $className): string
    {
        $lower = mb_strtolower($className);
        foreach (['redis' => 'Redis', 'memcached' => 'Memcached', 'apcu' => 'APCu', 'pdo' => 'baza danych', 'file' => 'pliki'] as $needle => $label) {
            if (str_contains($lower, $needle)) {
                return $label;
            }
        }
        $parts = explode('\\', $className);

        return (string) end($parts);
    }
}
