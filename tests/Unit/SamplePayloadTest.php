<?php

declare(strict_types=1);

namespace Calmfox\Watch\Tests\Unit;

use Calmfox\Watch\Core\CheckNormalizer;
use Calmfox\Watch\Core\Version;
use PHPUnit\Framework\TestCase;

/**
 * Próbki z Documentation/ są tym, na czym hub opiera test kontraktowy. Ten test
 * pilnuje, żeby leżało w nich dokładnie to, co kontrakt (WTYCZKI.md, sekcja 7)
 * przewiduje dla Neosa: identyfikatory sprawdzeń są częścią umowy, bo panel
 * opisuje każde z nich własnym tekstem.
 */
final class SamplePayloadTest extends TestCase
{
    /** Katalog z kontraktu, kolumna „Neos". Gwiazdką oznaczone są w nim checki opcjonalne. */
    private const HEALTH_IDS = ['db', 'disk', 'smtp', 'flow_cache', 'resources', 'content_repository', 'queue', 'elasticsearch'];

    private const SECURITY_IDS = ['admin_count', 'admin_login', 'flow_context', 'debug_display', 'https',
        'php_version', 'config_perms', 'dir_perms', 'encryption_key', 'dev_packages', 'pending_updates'];

    /** @return array<string, mixed> */
    private function sample(string $name): array
    {
        $path = \dirname(__DIR__, 2).'/Documentation/'.$name;
        self::assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testHealthSampleCoversTheWholeCatalogue(): void
    {
        $ids = array_column($this->sample('sample-health.json')['checks'], 'id');

        self::assertSame(self::HEALTH_IDS, $ids);
    }

    public function testSecuritySampleCoversTheWholeCatalogue(): void
    {
        $ids = array_column($this->sample('sample-security.json')['checks'], 'id');

        self::assertSame(self::SECURITY_IDS, $ids);
    }

    public function testSamplesDeclareCurrentSchemaAndVersion(): void
    {
        foreach (['sample-health.json', 'sample-security.json'] as $name) {
            $payload = $this->sample($name);
            self::assertSame(1, $payload['schema'], $name);
            self::assertSame(Version::NUMBER, $payload['plugin'], $name);
        }
    }

    public function testSamplesSurviveTheContractLimits(): void
    {
        foreach (['sample-health.json', 'sample-security.json'] as $name) {
            $payload = $this->sample($name);
            foreach ($payload['checks'] as $check) {
                self::assertMatchesRegularExpression('/^[a-z0-9_-]{1,40}$/', (string) $check['id'], $name);
                self::assertContains($check['status'], CheckNormalizer::STATUSES, $name);
                self::assertLessThanOrEqual(80, mb_strlen((string) $check['label']), $name);
                self::assertLessThanOrEqual(300, mb_strlen((string) ($check['detail'] ?? '')), $name);
                self::assertTrue(null === $check['ms'] || (\is_int($check['ms']) && $check['ms'] >= 0), $name);
            }
        }
    }

    /**
     * Zasada z kontraktu: w sekcji health jadą liczby, nie nazwy pakietów.
     * Nazwa pakietu w opisie sprawdzenia to gotowy rekonesans dla atakującego.
     */
    public function testHealthSampleNeverNamesPackages(): void
    {
        $payload = $this->sample('sample-health.json');

        self::assertArrayNotHasKey('history', $payload);
        self::assertSame(['core', 'plugins', 'themes'], array_keys($payload['site']['updates']));
    }

    /** W historii nazwy są dozwolone i potrzebne: to jest cała wartość tej funkcji. */
    public function testSecuritySampleCarriesNamedHistory(): void
    {
        $history = $this->sample('sample-security.json')['history'];

        self::assertNotSame([], $history);
        foreach ($history as $entry) {
            self::assertContains($entry['kind'], ['core', 'plugin'], 'na platformach composerowych motywy nie występują');
            self::assertNotSame('', (string) $entry['name']);
            self::assertSame('manual', $entry['mode']);
            self::assertNull($entry['by'], 'Composer nie mówi nam, kto wdrażał, więc nie zmyślamy autora');
            self::assertInstanceOf(\DateTimeImmutable::class, new \DateTimeImmutable((string) $entry['at']));
        }
    }

    public function testSecuritySampleHasNoSiteSection(): void
    {
        $payload = $this->sample('sample-security.json');

        self::assertArrayNotHasKey('site', $payload);
        self::assertArrayNotHasKey('signals', $payload);
    }

    public function testSampleStatusesMatchTheirChecks(): void
    {
        foreach (['sample-health.json', 'sample-security.json'] as $name) {
            $payload = $this->sample($name);
            self::assertSame(CheckNormalizer::aggregate($payload['checks']), $payload['status'], $name);
        }
    }

    /** Teksty widoczne dla klienta nie mogą zawierać znaku pauzy (JEZYK.md). */
    public function testSampleTextsAvoidEmDashes(): void
    {
        foreach (['sample-health.json', 'sample-security.json'] as $name) {
            foreach ($this->sample($name)['checks'] as $check) {
                self::assertStringNotContainsString('—', (string) $check['label'], $name);
                self::assertStringNotContainsString('—', (string) ($check['detail'] ?? ''), $name);
            }
        }
    }
}
