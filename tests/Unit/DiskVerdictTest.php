<?php

declare(strict_types=1);

namespace Calmfox\Watch\Tests\Unit;

use Calmfox\Watch\Core\DiskVerdict;
use PHPUnit\Framework\TestCase;

final class DiskVerdictTest extends TestCase
{
    private const GB = 1024 * 1024 * 1024;

    public function testQuotaBelowWarningThresholdIsOk(): void
    {
        self::assertSame('ok', DiskVerdict::fromQuota(8 * self::GB, 20 * self::GB));
    }

    public function testQuotaAtEightyFivePercentWarns(): void
    {
        self::assertSame('warn', DiskVerdict::fromQuota(17 * self::GB, 20 * self::GB));
    }

    public function testQuotaAtNinetyFivePercentFails(): void
    {
        self::assertSame('fail', DiskVerdict::fromQuota(19 * self::GB, 20 * self::GB));
    }

    public function testUnknownQuotaNeverFails(): void
    {
        self::assertSame('ok', DiskVerdict::fromQuota(100 * self::GB, 0));
    }

    public function testPercentUsedIsFloored(): void
    {
        self::assertSame(49, DiskVerdict::percentUsed(99, 200.0));
        self::assertSame(0, DiskVerdict::percentUsed(10, 0.0));
    }

    public function testPlentyOfFreeSpaceIsOk(): void
    {
        self::assertSame('ok', DiskVerdict::fromFree(40 * self::GB, 100 * self::GB));
    }

    public function testLowPercentageWarns(): void
    {
        self::assertSame('warn', DiskVerdict::fromFree(9 * self::GB, 100 * self::GB));
    }

    public function testVeryLowPercentageFails(): void
    {
        self::assertSame('fail', DiskVerdict::fromFree(2 * self::GB, 100 * self::GB));
    }

    /** Na dużym dysku 200 MB wolnego to i tak awaria, mimo że procentowo wygląda niewinnie. */
    public function testSmallAbsoluteFreeSpaceFailsEvenOnLargeVolume(): void
    {
        self::assertSame('fail', DiskVerdict::fromFree(100 * 1024 * 1024, 100 * self::GB));
    }

    public function testSharedHostingIsRecognisedByControlPanelTraces(): void
    {
        $exists = static fn (string $path): bool => '/usr/local/directadmin' === $path;

        self::assertTrue(DiskVerdict::readingIsShared(50 * self::GB, '', $exists));
    }

    public function testOpenBasedirMeansSharedHosting(): void
    {
        $exists = static fn (string $path): bool => false;

        self::assertTrue(DiskVerdict::readingIsShared(50 * self::GB, '/home/klient:/tmp', $exists));
    }

    /** Terabajt „wolnego" to nie jest konto współdzielone, tylko odczyt całego serwera. */
    public function testHugeVolumeMeansSharedReading(): void
    {
        $exists = static fn (string $path): bool => false;

        self::assertTrue(DiskVerdict::readingIsShared(2048.0 * self::GB, '', $exists));
    }

    public function testOwnServerReadingIsTrusted(): void
    {
        $exists = static fn (string $path): bool => false;

        self::assertFalse(DiskVerdict::readingIsShared(80 * self::GB, '', $exists));
    }

    public function testSizeIsFormattedForPolishReader(): void
    {
        self::assertSame('1,5 GB', DiskVerdict::format(1.5 * self::GB));
        self::assertSame('512 MB', DiskVerdict::format(512 * 1024 * 1024));
        self::assertSame('0 B', DiskVerdict::format(0));
    }
}
