<?php

declare(strict_types=1);

namespace Calmfox\Watch\Tests\Unit;

use Calmfox\Watch\Core\VersionSnapshot;
use PHPUnit\Framework\TestCase;

final class VersionSnapshotTest extends TestCase
{
    private const AT = '2026-08-19T09:00:00+00:00';

    /** @return array<string, mixed> */
    private function installed(array $versions): array
    {
        $out = [];
        foreach ($versions as $name => $version) {
            $out[$name] = ['pretty_version' => $version, 'version' => $version.'.0'];
        }

        return ['root' => ['dev' => false], 'versions' => $out];
    }

    public function testSnapshotReadsPrettyVersionsAndPhp(): void
    {
        $snapshot = VersionSnapshot::fromInstalled($this->installed([
            'neos/neos' => '8.3.14',
            'vendor/pakiet' => '2.1.0',
        ]), '8.3.14');

        self::assertSame(['neos/neos' => '8.3.14', 'php' => '8.3.14', 'vendor/pakiet' => '2.1.0'], $snapshot);
    }

    /** Metapaczki bez wersji generowałyby wpis „dodano/usunięto" przy każdym odczycie. */
    public function testPackagesWithoutVersionAreSkipped(): void
    {
        $snapshot = VersionSnapshot::fromInstalled([
            'versions' => [
                'vendor/meta' => ['pretty_version' => '', 'version' => ''],
                'vendor/realny' => ['pretty_version' => '1.0.0'],
            ],
        ], '8.3.14');

        self::assertArrayNotHasKey('vendor/meta', $snapshot);
        self::assertArrayHasKey('vendor/realny', $snapshot);
    }

    public function testIdenticalSnapshotsProduceNoEntries(): void
    {
        $snapshot = ['neos/neos' => '8.3.14', 'php' => '8.3.14'];

        self::assertSame([], VersionSnapshot::diff($snapshot, $snapshot, self::AT));
    }

    public function testVersionBumpIsRecorded(): void
    {
        $entries = VersionSnapshot::diff(
            ['neos/neos' => '8.3.13'],
            ['neos/neos' => '8.3.14'],
            self::AT
        );

        self::assertCount(1, $entries);
        self::assertSame('core', $entries[0]['kind']);
        self::assertSame('neos/neos', $entries[0]['name']);
        self::assertSame('8.3.13', $entries[0]['from']);
        self::assertSame('8.3.14', $entries[0]['to']);
        self::assertSame(self::AT, $entries[0]['at']);
        self::assertSame('manual', $entries[0]['mode']);
        self::assertNull($entries[0]['by']);
    }

    public function testAddedPackageHasNoPreviousVersion(): void
    {
        $entries = VersionSnapshot::diff([], ['vendor/nowy' => '1.0.0'], self::AT);

        self::assertCount(1, $entries);
        self::assertSame('plugin', $entries[0]['kind']);
        self::assertNull($entries[0]['from']);
        self::assertSame('1.0.0', $entries[0]['to']);
    }

    /** Usunięcie pakietu potrafi wywrócić stronę tak samo jak aktualizacja, więc też jest wpisem. */
    public function testRemovedPackageHasNoTargetVersion(): void
    {
        $entries = VersionSnapshot::diff(['vendor/stary' => '3.2.1'], [], self::AT);

        self::assertCount(1, $entries);
        self::assertSame('3.2.1', $entries[0]['from']);
        self::assertNull($entries[0]['to']);
    }

    public function testAllThreeKindsOfChangeInOneRun(): void
    {
        $entries = VersionSnapshot::diff(
            ['neos/neos' => '8.3.13', 'vendor/usuniety' => '1.0.0', 'vendor/staly' => '2.0.0'],
            ['neos/neos' => '8.3.14', 'vendor/nowy' => '0.9.0', 'vendor/staly' => '2.0.0'],
            self::AT
        );

        $byName = [];
        foreach ($entries as $entry) {
            $byName[$entry['name']] = $entry;
        }

        self::assertCount(3, $entries);
        self::assertSame(['8.3.13', '8.3.14'], [$byName['neos/neos']['from'], $byName['neos/neos']['to']]);
        self::assertNull($byName['vendor/nowy']['from']);
        self::assertNull($byName['vendor/usuniety']['to']);
        self::assertArrayNotHasKey('vendor/staly', $byName);
    }

    public function testPhpUpgradeIsRecordedAsPlatformChange(): void
    {
        $entries = VersionSnapshot::diff(['php' => '8.2.20'], ['php' => '8.3.14'], self::AT);

        self::assertSame('core', $entries[0]['kind']);
        self::assertSame('PHP', $entries[0]['name'], 'w historii pokazujemy PHP pod nazwą, którą klient rozpozna');
    }

    public function testOrdinaryPackagesAreNotPlatform(): void
    {
        $entries = VersionSnapshot::diff(['neos/flow' => '8.3.0'], ['neos/flow' => '8.3.1'], self::AT);

        self::assertSame('plugin', $entries[0]['kind']);
    }

    public function testEntriesAreSortedByName(): void
    {
        $entries = VersionSnapshot::diff(
            ['zeta/pakiet' => '1.0.0', 'alfa/pakiet' => '1.0.0'],
            ['zeta/pakiet' => '1.0.1', 'alfa/pakiet' => '1.0.1'],
            self::AT
        );

        self::assertSame(['alfa/pakiet', 'zeta/pakiet'], array_column($entries, 'name'));
    }

    public function testLongValuesAreTrimmedToContractLimits(): void
    {
        $name = 'vendor/'.str_repeat('n', 200);
        $entries = VersionSnapshot::diff([], [$name => str_repeat('9', 60)], self::AT);

        self::assertSame(120, mb_strlen($entries[0]['name']));
        self::assertSame(32, mb_strlen((string) $entries[0]['to']));
    }

    /**
     * Regresja z produkcji (2026-08-19): przy nieczytelnym installed.php migawka
     * powstawała z samą wersją PHP i zapisywała się jako punkt odniesienia. Pierwszy
     * udany odczyt dawał wtedy wpis „dodano" dla KAŻDEGO pakietu naraz, czyli 134
     * śmieci w historii, którą klient ogląda pod hasłem „zmiany przed awarią".
     */
    public function testMigawkaBezPakietowJestPusta(): void
    {
        self::assertSame([], VersionSnapshot::fromInstalled([], '8.3.0'));
        self::assertSame([], VersionSnapshot::fromInstalled(['versions' => []], '8.3.0'));
        self::assertSame([], VersionSnapshot::fromInstalled(['versions' => ['bez/wersji' => []]], '8.3.0'));
    }

    public function testMigawkaZPakietamiNiesieTakzePhp(): void
    {
        $snapshot = VersionSnapshot::fromInstalled(['versions' => ['neos/neos' => ['pretty_version' => '9.1.5']]], '8.3.0');

        self::assertSame(['neos/neos' => '9.1.5', 'php' => '8.3.0'], $snapshot);
    }
}
