<?php

declare(strict_types=1);

namespace Calmfox\Watch\Tests\Unit;

use Calmfox\Watch\Core\PayloadBuilder;
use Calmfox\Watch\Core\Version;
use PHPUnit\Framework\TestCase;

final class PayloadBuilderTest extends TestCase
{
    public function testHealthCarriesSchemaAndVersion(): void
    {
        $payload = PayloadBuilder::health([['id' => 'db', 'status' => 'ok']], PayloadBuilder::site('8.3.14', '8.3.14', null), null);

        self::assertSame(1, $payload['schema']);
        self::assertSame(Version::NUMBER, $payload['plugin']);
    }

    public function testHealthStatusIsAggregatedFromChecks(): void
    {
        $payload = PayloadBuilder::health([
            ['id' => 'db', 'status' => 'ok'],
            ['id' => 'disk', 'status' => 'fail'],
        ], PayloadBuilder::site('8.3.14', '8.3.14', null), null);

        self::assertSame('fail', $payload['status']);
    }

    /**
     * Najważniejsza asercja tego pliku. Zero znaczy „sprawdzone, nie ma czego
     * aktualizować", więc gdy nie liczyliśmy, pole musi zniknąć w całości.
     */
    public function testUpdatesFieldIsOmittedWhenUnknown(): void
    {
        $site = PayloadBuilder::site('8.3.14', '8.3.14', null);

        self::assertArrayNotHasKey('updates', $site);
        self::assertSame(['wp', 'php', 'plugin'], array_keys($site));
    }

    public function testUpdatesFieldIsPresentWhenCounted(): void
    {
        $site = PayloadBuilder::site('8.3.14', '8.3.14', ['core' => 1, 'plugins' => 3, 'themes' => 0]);

        self::assertSame(['core' => 1, 'plugins' => 3, 'themes' => 0], $site['updates']);
    }

    public function testUpdatesNeverGoNegative(): void
    {
        $site = PayloadBuilder::site('8.3.14', '8.3.14', ['core' => -2, 'plugins' => -1, 'themes' => 0]);

        self::assertSame(['core' => 0, 'plugins' => 0, 'themes' => 0], $site['updates']);
    }

    public function testPlatformVersionLandsInTheHistoricallyNamedField(): void
    {
        $site = PayloadBuilder::site('9.0.2', '8.3.14', null);

        self::assertSame('9.0.2', $site['wp']);
        self::assertSame('8.3.14', $site['php']);
    }

    /**
     * Puste sygnały wyglądałyby dla huba jak podmiana wszystkich kont naraz,
     * więc przy nieodczytanych kontach pole musi zniknąć, a nie wyzerować się.
     */
    public function testSignalsAreOmittedWhenAccountsCouldNotBeRead(): void
    {
        $payload = PayloadBuilder::health([['id' => 'db', 'status' => 'fail']], PayloadBuilder::site('8.3.14', '8.3.14', null), null);

        self::assertArrayNotHasKey('signals', $payload);
    }

    public function testSignalsCountUniqueAccounts(): void
    {
        $signals = PayloadBuilder::signals(['admin@Neos.Neos:Backend', 'admin@Neos.Neos:Backend', 'ola@Neos.Neos:Backend'], '2026-08-01T10:00:00+00:00', 'sekret');

        self::assertSame(2, $signals['adminCount']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $signals['adminsFingerprint']);
        self::assertSame('2026-08-01T10:00:00+00:00', $signals['newestAdminAt']);
    }

    public function testSecurityCarriesHistory(): void
    {
        $payload = PayloadBuilder::security(
            [['id' => 'https', 'status' => 'ok']],
            [['kind' => 'core', 'name' => 'neos/neos', 'from' => '8.3.13', 'to' => '8.3.14', 'at' => '2026-08-18T21:35:00+00:00', 'mode' => 'manual', 'by' => null]]
        );

        self::assertSame('ok', $payload['status']);
        self::assertCount(1, $payload['history']);
        self::assertSame('neos/neos', $payload['history'][0]['name']);
    }

    public function testSecurityHistoryIsCappedAtTwoHundred(): void
    {
        $history = [];
        for ($i = 0; $i < 250; ++$i) {
            $history[] = ['kind' => 'plugin', 'name' => 'vendor/pakiet'.$i, 'from' => '1.0.0', 'to' => '1.0.1', 'at' => '2026-08-18T21:35:00+00:00', 'mode' => 'manual', 'by' => null];
        }

        self::assertCount(200, PayloadBuilder::security([], $history)['history']);
    }

    /** Payload musi być serializowalny bez strat: to on jest podpisywany. */
    public function testPayloadEncodesToJson(): void
    {
        $payload = PayloadBuilder::health(
            [['id' => 'db', 'status' => 'ok', 'label' => 'Baza danych', 'detail' => 'Zapytanie kontrolne trwało 3 ms.', 'ms' => 3]],
            PayloadBuilder::site('8.3.14', '8.3.14', ['core' => 0, 'plugins' => 2, 'themes' => 0]),
            PayloadBuilder::signals(['admin@Neos.Neos:Backend'], null, 'sekret')
        );

        $json = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        self::assertIsString($json);
        self::assertSame($payload, json_decode((string) $json, true));
    }

    /**
     * Skład pakietów: liczba, odcisk i nazwy. Nazwy jadą świadomie (bez nich
     * zdarzenie mówi tylko „coś się zmieniło"), ale wersji przy nich nie ma.
     */
    public function testPackageSignalsCarryNamesWithoutVersions(): void
    {
        $signals = PayloadBuilder::packageSignals(['neos/neos', 'neos/seo', 'vendor/site'], str_repeat('a', 32));

        self::assertSame(3, $signals['pluginCount']);
        self::assertSame(['neos/neos', 'neos/seo', 'vendor/site'], $signals['activePlugins']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $signals['pluginsFingerprint']);
        self::assertArrayNotHasKey('autoUpdates', $signals, 'Neos nie aktualizuje się sam, więc pole zostaje nieobecne zamiast kłamać wartością.');
    }

    /** Odcisk solimy sekretem instalacji, więc ten sam skład w dwóch miejscach daje inny wynik. */
    public function testPackageFingerprintIsSaltedWithTheInstallationSecret(): void
    {
        $here = PayloadBuilder::packageSignals(['neos/neos'], str_repeat('a', 32));
        $elsewhere = PayloadBuilder::packageSignals(['neos/neos'], str_repeat('b', 32));

        self::assertNotSame($here['pluginsFingerprint'], $elsewhere['pluginsFingerprint']);
    }

    /** Pusty spis znaczy „nie udało się odczytać", a nie „strona nie ma pakietów". */
    public function testEmptyPackageListSendsNothingAtAll(): void
    {
        self::assertSame([], PayloadBuilder::packageSignals([], str_repeat('a', 32)));
    }
}
