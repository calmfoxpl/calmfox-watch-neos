<?php

declare(strict_types=1);

namespace Calmfox\Watch\Tests\Unit;

use Calmfox\Watch\Core\DevPackages;
use PHPUnit\Framework\TestCase;

final class DevPackagesTest extends TestCase
{
    public function testCleanProductionInstallHasNothingToReport(): void
    {
        $found = DevPackages::detect([
            'root' => ['dev' => false],
            'versions' => [
                'neos/neos' => ['pretty_version' => '8.3.14', 'dev_requirement' => false],
            ],
        ]);

        self::assertFalse($found['devMode']);
        self::assertSame([], $found['names']);
        self::assertSame([], $found['risky']);
    }

    public function testDevRequirementsAreListed(): void
    {
        $found = DevPackages::detect([
            'root' => ['dev' => true],
            'versions' => [
                'neos/neos' => ['pretty_version' => '8.3.14', 'dev_requirement' => false],
                'phpunit/phpunit' => ['pretty_version' => '10.5.0', 'dev_requirement' => true],
                'symfony/var-dumper' => ['pretty_version' => '6.4.0', 'dev_requirement' => true],
            ],
        ]);

        self::assertTrue($found['devMode']);
        self::assertSame(['phpunit/phpunit', 'symfony/var-dumper'], $found['names']);
    }

    /** Kreator kodu potrafi zapisać plik PHP w drzewie aplikacji, więc pilnujemy go osobno. */
    public function testRiskyPackagesAreReportedEvenOutsideDevRequirements(): void
    {
        $found = DevPackages::detect([
            'root' => ['dev' => false],
            'versions' => [
                'neos/kickstarter' => ['pretty_version' => '8.3.0', 'dev_requirement' => false],
            ],
        ]);

        self::assertSame(['neos/kickstarter'], $found['risky']);
        self::assertSame([], $found['names']);
    }

    public function testPackageNamesAreCaseInsensitive(): void
    {
        $found = DevPackages::detect([
            'root' => ['dev' => false],
            'versions' => ['Neos/Kickstarter' => ['pretty_version' => '8.3.0']],
        ]);

        self::assertSame(['neos/kickstarter'], $found['risky']);
    }

    public function testEmptyInstalledFileIsHandled(): void
    {
        $found = DevPackages::detect([]);

        self::assertFalse($found['devMode']);
        self::assertSame([], $found['names']);
    }
}
