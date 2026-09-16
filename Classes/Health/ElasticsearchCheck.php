<?php

declare(strict_types=1);

namespace Calmfox\Watch\Health;

use Calmfox\Watch\Core\SimpleHttp;
use Calmfox\Watch\Service\Cache;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;

/**
 * Elasticsearch przez Flowpack.ElasticSearch.ContentRepositoryAdaptor. Check
 * OPCJONALNY: bez tego pakietu i bez skonfigurowanego klienta zwracamy null.
 *
 * Warto go mieć, bo awaria klastra nie zabija strony, tylko wyszukiwarkę
 * i listingi na niej oparte. Monitoring dostępności widzi wtedy kod 200,
 * a klient dowiaduje się o problemie od swoich odwiedzających.
 *
 * Wynik żyje 5 minut, a zapytanie ma dwie sekundy maksymalnego czasu
 * odpowiedzi: klaster potrafi odpowiadać wolno właśnie wtedy, gdy jest
 * przeciążony, a nasz adres kontrolny nie może na to czekać.
 */
#[Flow\Scope('singleton')]
class ElasticsearchCheck implements HealthCheckInterface
{
    private const NET_TIMEOUT = 2.0;

    #[Flow\Inject]
    protected ConfigurationManager $configurationManager;

    #[Flow\Inject]
    protected Cache $cache;

    public function run(): ?array
    {
        if (!class_exists('Flowpack\ElasticSearch\ContentRepositoryAdaptor\Indexer\NodeIndexer')) {
            return null;
        }
        $url = $this->clusterUrl();
        if ('' === $url) {
            return null;
        }

        $cached = $this->cache->get(Cache::ELASTICSEARCH);
        if (\is_array($cached)) {
            return $cached;
        }

        $label = 'Wyszukiwarka Elasticsearch';
        $start = microtime(true);
        $response = SimpleHttp::request('GET', $url.'/_cluster/health', null, ['Accept' => 'application/json'], self::NET_TIMEOUT);
        $ms = (int) round((microtime(true) - $start) * 1000);

        if ('' !== $response['error'] || 0 === $response['status']) {
            $result = ['id' => 'elasticsearch', 'status' => 'fail', 'label' => $label,
                'detail' => 'Klaster nie odpowiada: '.('' !== $response['error'] ? $response['error'] : 'brak odpowiedzi'), 'ms' => $ms];
        } else {
            $body = json_decode($response['body'], true);
            $cluster = \is_array($body) && isset($body['status']) ? (string) $body['status'] : '';
            $result = match ($cluster) {
                'green' => ['id' => 'elasticsearch', 'status' => 'ok', 'label' => $label, 'detail' => 'Klaster w pełni sprawny.', 'ms' => $ms],
                'yellow' => ['id' => 'elasticsearch', 'status' => 'warn', 'label' => $label, 'detail' => 'Klaster działa, ale nie ma kompletu kopii danych. Awaria jednego węzła oznaczałaby utratę indeksu.', 'ms' => $ms],
                'red' => ['id' => 'elasticsearch', 'status' => 'fail', 'label' => $label, 'detail' => 'Klaster zgłasza brak części danych. Wyszukiwarka i listingi oparte na indeksie nie działają poprawnie.', 'ms' => $ms],
                default => ['id' => 'elasticsearch', 'status' => 'fail', 'label' => $label, 'detail' => 'Odpowiedź klastra bez informacji o stanie.', 'ms' => $ms],
            };
        }

        $this->cache->set(Cache::ELASTICSEARCH, $result, 300);

        return $result;
    }

    private function clusterUrl(): string
    {
        try {
            $clients = $this->configurationManager->getConfiguration(
                ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
                'Flowpack.ElasticSearch.clients.default'
            );
        } catch (\Throwable) {
            return '';
        }
        if (!\is_array($clients)) {
            return '';
        }
        // Konfiguracja to lista grup, a w grupie lista węzłów. Bierzemy pierwszy:
        // check ma powiedzieć, czy klaster odpowiada, a stan klastra i tak jest wspólny.
        $node = $this->firstNode($clients);
        if (null === $node) {
            return '';
        }
        $scheme = (string) ($node['scheme'] ?? 'http');
        $host = (string) ($node['host'] ?? '');
        $port = (int) ($node['port'] ?? 9200);

        return '' !== $host ? sprintf('%s://%s:%d', $scheme, $host, $port) : '';
    }

    /**
     * @param array<mixed> $clients
     *
     * @return array<string, mixed>|null
     */
    private function firstNode(array $clients): ?array
    {
        foreach ($clients as $group) {
            if (!\is_array($group)) {
                continue;
            }
            if (isset($group['host'])) {
                return $group;
            }
            foreach ($group as $node) {
                if (\is_array($node) && isset($node['host'])) {
                    return $node;
                }
            }
        }

        return null;
    }
}
