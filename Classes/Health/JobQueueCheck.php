<?php

declare(strict_types=1);

namespace Calmfox\Watch\Health;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;

/**
 * Kolejki zadań (Flowpack.JobQueue). Check OPCJONALNY: gdy pakietu nie ma albo
 * nie zdefiniowano żadnej kolejki, zwracamy null i nie wysyłamy nic. Kontrakt
 * jest tu jednoznaczny: nie mówimy „ok" o usłudze, której na stronie nie ma.
 *
 * Zaległości w kolejce to `warn`, nie `fail`, i to jest decyzja świadoma:
 * kod 503 z adresu kontrolnego otwiera incydent i budzi ludzi, a rosnąca
 * kolejka nie jest awarią strony. Awarią jest dopiero kolejka, która w ogóle
 * nie odpowiada, bo wtedy nie działa nic, co przez nią przechodzi.
 */
#[Flow\Scope('singleton')]
class JobQueueCheck implements HealthCheckInterface
{
    /** Powyżej tylu oczekujących zadań kolejka wyraźnie nie nadąża. */
    private const BACKLOG_WARN = 500;

    #[Flow\Inject]
    protected ObjectManagerInterface $objectManager;

    #[Flow\Inject]
    protected ConfigurationManager $configurationManager;

    public function run(): ?array
    {
        $managerClass = 'Flowpack\JobQueue\Common\Queue\QueueManager';
        if (!class_exists($managerClass)) {
            return null;
        }
        $queues = $this->queueNames();
        if ([] === $queues) {
            return null;
        }

        $label = 'Kolejki zadań';
        $start = microtime(true);
        $ready = 0;
        $failed = 0;
        $unreachable = [];

        $manager = $this->objectManager->get($managerClass);
        foreach ($queues as $name) {
            try {
                $queue = $manager->getQueue($name);
                $ready += (int) $queue->countReady();
                if (method_exists($queue, 'countFailed')) {
                    $failed += (int) $queue->countFailed();
                }
            } catch (\Throwable) {
                $unreachable[] = $name;
            }
        }
        $ms = (int) round((microtime(true) - $start) * 1000);

        if ([] !== $unreachable) {
            return ['id' => 'queue', 'status' => 'fail', 'label' => $label,
                'detail' => sprintf('Kolejki %s nie odpowiadają. Zadania w tle (wysyłki, indeksowanie, importy) nie są wykonywane.', implode(', ', $unreachable)),
                'ms' => $ms];
        }
        if ($failed > 0) {
            return ['id' => 'queue', 'status' => 'warn', 'label' => $label,
                'detail' => sprintf('Zadań zakończonych błędem: %d, oczekujących: %d. Zadania z błędem nie wykonają się same.', $failed, $ready),
                'ms' => $ms];
        }
        if ($ready > self::BACKLOG_WARN) {
            return ['id' => 'queue', 'status' => 'warn', 'label' => $label,
                'detail' => sprintf('Oczekujących zadań: %d. Kolejka rośnie szybciej, niż pracownicy zdążają ją opróżniać.', $ready),
                'ms' => $ms];
        }

        return ['id' => 'queue', 'status' => 'ok', 'label' => $label,
            'detail' => sprintf('Oczekujących zadań: %d.', $ready), 'ms' => $ms];
    }

    /** @return list<string> */
    private function queueNames(): array
    {
        try {
            $queues = $this->configurationManager->getConfiguration(
                ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
                'Flowpack.JobQueue.Common.queues'
            );
        } catch (\Throwable) {
            return [];
        }

        return \is_array($queues) ? array_values(array_map('strval', array_keys($queues))) : [];
    }
}
