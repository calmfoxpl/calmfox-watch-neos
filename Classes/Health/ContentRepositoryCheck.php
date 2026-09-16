<?php

declare(strict_types=1);

namespace Calmfox\Watch\Health;

use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;

/**
 * Repozytorium treści. Baza może odpowiadać na „SELECT 1" i jednocześnie nie
 * mieć czego oddać stronie: skasowany węzeł główny, wyłączona strona, projekcja
 * treści rozjechana po nieudanej migracji. Wtedy odwiedzający dostaje błąd 500
 * albo pustą stronę, a check bazy dalej świeci na zielono. Dlatego pytamy
 * osobno o to, co naprawdę renderuje się użytkownikowi.
 *
 * Brak węzła głównego to `fail`: strona nie ma czego pokazać.
 *
 * Uwaga o wersjach: Neos 9 przepisał API repozytorium treści od nowa, więc
 * podstawowe sprawdzenie robimy przez SiteRepository (istnieje i w 8, i w 9),
 * a liczbę węzłów dokładamy tylko wtedy, gdy potrafimy ją policzyć w danej
 * wersji. Lepsza uczciwa niepełna informacja niż check, który wywala się
 * na jednej z dwóch wspieranych wersji.
 */
#[Flow\Scope('singleton')]
class ContentRepositoryCheck implements HealthCheckInterface
{
    /** Tabela węzłów w Neosie 8. W Neosie 9 nazwa zależy od identyfikatora repozytorium, więc liczby po prostu nie podajemy. */
    private const NODE_TABLE = 'neos_contentrepository_domain_model_nodedata';

    #[Flow\Inject]
    protected ObjectManagerInterface $objectManager;

    #[Flow\Inject]
    protected EntityManagerInterface $entityManager;

    public function run(): ?array
    {
        $label = 'Repozytorium treści';
        $start = microtime(true);

        try {
            $repositoryClass = 'Neos\Neos\Domain\Repository\SiteRepository';
            if (!class_exists($repositoryClass)) {
                return null; // czysty Flow bez Neosa: nie ma repozytorium treści, więc nie ma o czym mówić
            }
            $sites = $this->objectManager->get($repositoryClass)->findOnline();
            $online = \count($sites);
            $ms = (int) round((microtime(true) - $start) * 1000);

            if (0 === $online) {
                return ['id' => 'content_repository', 'status' => 'fail', 'label' => $label,
                    'detail' => 'Repozytorium odpowiada, ale nie ma ani jednej aktywnej strony. Odwiedzający nie zobaczy żadnej treści.', 'ms' => $ms];
            }

            $rootMissing = $this->siteNodeMissing();
            if (true === $rootMissing) {
                return ['id' => 'content_repository', 'status' => 'fail', 'label' => $label,
                    'detail' => 'Strona jest aktywna, ale w gałęzi live nie ma jej węzła głównego. Nie ma czego renderować.', 'ms' => $ms];
            }

            $nodes = $this->countLiveNodes();
            $detail = sprintf('Aktywnych stron: %d.', $online);
            if (null !== $nodes) {
                $detail .= sprintf(' Węzłów treści w gałęzi live: %d.', $nodes);
            }

            return ['id' => 'content_repository', 'status' => 'ok', 'label' => $label, 'detail' => $detail, 'ms' => $ms];
        } catch (\Throwable $exception) {
            return ['id' => 'content_repository', 'status' => 'fail', 'label' => $label,
                'detail' => $exception->getMessage(), 'ms' => (int) round((microtime(true) - $start) * 1000)];
        }
    }

    /**
     * Węzeł główny strony w kontekście live.
     *
     * @return bool|null true = brak węzła, false = jest, null = tej wersji Neosa nie umiemy o to zapytać
     */
    private function siteNodeMissing(): ?bool
    {
        $factoryClass = 'Neos\Neos\Domain\Service\ContentContextFactory';
        if (!class_exists($factoryClass)) {
            return null; // Neos 9: inne API, sprawdzenie opieramy na SiteRepository
        }
        try {
            $context = $this->objectManager->get($factoryClass)->create([
                'workspaceName' => 'live',
                'invisibleContentShown' => true,
                'inaccessibleContentShown' => true,
            ]);

            return null === $context->getCurrentSiteNode();
        } catch (\Throwable) {
            return null;
        }
    }

    private function countLiveNodes(): ?int
    {
        try {
            $connection = $this->entityManager->getConnection();
            // DBAL 2 i DBAL 3 nazywają to inaczej, a oba wchodzą w grę przy
            // zakresie wersji Neosa, który deklarujemy.
            $schema = method_exists($connection, 'createSchemaManager')
                ? $connection->createSchemaManager()
                : $connection->getSchemaManager();
            if (!$schema->tablesExist([self::NODE_TABLE])) {
                return null;
            }

            return (int) $connection->executeQuery(
                'SELECT COUNT(*) FROM '.self::NODE_TABLE.' WHERE workspace = :workspace',
                ['workspace' => 'live']
            )->fetchOne();
        } catch (\Throwable) {
            return null;
        }
    }
}
