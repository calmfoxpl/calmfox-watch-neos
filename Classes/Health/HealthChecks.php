<?php

declare(strict_types=1);

namespace Calmfox\Watch\Health;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ReflectionService;

/**
 * Zbieranie checków sekcji health. Nasze idą pierwsze, w ustalonej kolejności
 * (baza, dysk, poczta, pamięć podręczna, zasoby, treść), bo tak czyta je
 * człowiek w panelu. Implementacje z innych pakietów dokładamy po nich,
 * alfabetycznie po nazwie klasy, żeby kolejność nie zależała od tego, w jakiej
 * kolejności Flow akurat je znalazł.
 *
 * Każdy check jest opakowany w try/catch. Wyjątek z jednego sprawdzenia nie
 * może zabrać ze sobą całej odpowiedzi: sonda dostałaby wtedy stronę błędu
 * zamiast informacji, co konkretnie leży.
 */
#[Flow\Scope('singleton')]
class HealthChecks
{
    /** @var list<class-string<HealthCheckInterface>> */
    private const OWN_ORDER = [
        DatabaseCheck::class,
        DiskCheck::class,
        SmtpCheck::class,
        FlowCacheCheck::class,
        ResourcesCheck::class,
        ContentRepositoryCheck::class,
        JobQueueCheck::class,
        ElasticsearchCheck::class,
    ];

    #[Flow\Inject]
    protected ObjectManagerInterface $objectManager;

    #[Flow\Inject]
    protected ReflectionService $reflectionService;

    /** @return list<array<string, mixed>> */
    public function run(): array
    {
        $checks = [];
        foreach ($this->classNames() as $className) {
            $result = $this->runOne($className);
            if (null !== $result) {
                $checks[] = $result;
            }
        }

        return $checks;
    }

    /** @return list<class-string<HealthCheckInterface>> */
    private function classNames(): array
    {
        try {
            $discovered = $this->reflectionService->getAllImplementationClassNamesForInterface(HealthCheckInterface::class);
        } catch (\Throwable) {
            // Nieprzebudowana pamięć podręczna refleksji nie może zabrać nam
            // WŁASNYCH sprawdzeń: lecimy dalej z listą wpisaną na sztywno.
            $discovered = [];
        }
        $foreign = array_values(array_diff($discovered, self::OWN_ORDER));
        sort($foreign, \SORT_STRING);

        return array_merge(self::OWN_ORDER, $foreign);
    }

    /** @return array<string, mixed>|null */
    private function runOne(string $className): ?array
    {
        try {
            $check = $this->objectManager->get($className);
            if (!$check instanceof HealthCheckInterface) {
                return null;
            }

            return $check->run();
        } catch (\Throwable $exception) {
            // Nie znamy identyfikatora checku, który się wywrócił, więc nie
            // podszywamy się pod żaden z katalogu: własny identyfikator mówi
            // wprost, że problem jest po stronie sprawdzenia, nie usługi.
            return [
                'id' => 'check_error',
                'status' => 'warn',
                'label' => 'Sprawdzenie nie zakończyło się',
                'detail' => sprintf('%s: %s', $this->shortName($className), $exception->getMessage()),
            ];
        }
    }

    private function shortName(string $className): string
    {
        $parts = explode('\\', $className);

        return (string) end($parts);
    }
}
