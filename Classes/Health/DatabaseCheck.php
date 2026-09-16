<?php

declare(strict_types=1);

namespace Calmfox\Watch\Health;

use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Ping bazy. To jest ten check, dla którego cały pakiet trzyma sekret w pliku,
 * a nie w bazie: gdy baza leży, adres kontrolny MA odpowiedzieć „db: fail"
 * i kodem 503, a nie zamilknąć. Milczenie hub też zauważy, ale dopiero po
 * trzech nieudanych odpytaniach i bez informacji, co konkretnie padło.
 *
 * Dlatego łapiemy wszystko, łącznie z Error: brak sterownika PDO albo zerwane
 * połączenie potrafi rzucić czymś spoza hierarchii Exception.
 */
#[Flow\Scope('singleton')]
class DatabaseCheck implements HealthCheckInterface
{
    #[Flow\Inject]
    protected EntityManagerInterface $entityManager;

    public function run(): ?array
    {
        $start = microtime(true);
        try {
            $connection = $this->entityManager->getConnection();
            $value = $connection->executeQuery('SELECT 1')->fetchOne();
            $ms = (int) round((microtime(true) - $start) * 1000);
            $ok = '1' === (string) $value;

            return [
                'id' => 'db',
                'status' => $ok ? 'ok' : 'fail',
                'label' => 'Baza danych',
                'detail' => $ok ? null : 'Zapytanie testowe nie zwróciło wyniku.',
                'ms' => $ms,
            ];
        } catch (\Throwable $exception) {
            return [
                'id' => 'db',
                'status' => 'fail',
                'label' => 'Baza danych',
                'detail' => $exception->getMessage(),
                'ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        }
    }
}
