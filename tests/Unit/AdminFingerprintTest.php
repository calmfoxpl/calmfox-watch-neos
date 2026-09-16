<?php

declare(strict_types=1);

namespace Calmfox\Watch\Tests\Unit;

use Calmfox\Watch\Core\AdminFingerprint;
use PHPUnit\Framework\TestCase;

final class AdminFingerprintTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';

    public function testFingerprintHasContractFormat(): void
    {
        $fingerprint = AdminFingerprint::of(['ola@Neos.Neos:Backend'], self::SECRET);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $fingerprint);
    }

    public function testSameAccountsGiveSameFingerprint(): void
    {
        $accounts = ['ola@Neos.Neos:Backend', 'jan@Neos.Neos:Backend'];

        self::assertSame(
            AdminFingerprint::of($accounts, self::SECRET),
            AdminFingerprint::of($accounts, self::SECRET)
        );
    }

    /**
     * Repozytorium może oddać konta w innej kolejności przy tym samym składzie.
     * Gdyby odcisk od tego zależał, hub otwierałby incydent o podmianie konta
     * bez żadnego powodu.
     */
    public function testOrderDoesNotChangeFingerprint(): void
    {
        self::assertSame(
            AdminFingerprint::of(['ola@Neos.Neos:Backend', 'jan@Neos.Neos:Backend'], self::SECRET),
            AdminFingerprint::of(['jan@Neos.Neos:Backend', 'ola@Neos.Neos:Backend'], self::SECRET)
        );
    }

    public function testAddedAccountChangesFingerprint(): void
    {
        self::assertNotSame(
            AdminFingerprint::of(['ola@Neos.Neos:Backend'], self::SECRET),
            AdminFingerprint::of(['ola@Neos.Neos:Backend', 'intruz@Neos.Neos:Backend'], self::SECRET)
        );
    }

    /** Podmiana konta przy tej samej liczbie to klasyczny ruch po przejęciu strony. */
    public function testSwappedAccountChangesFingerprintAtSameCount(): void
    {
        self::assertNotSame(
            AdminFingerprint::of(['ola@Neos.Neos:Backend', 'jan@Neos.Neos:Backend'], self::SECRET),
            AdminFingerprint::of(['ola@Neos.Neos:Backend', 'intruz@Neos.Neos:Backend'], self::SECRET)
        );
    }

    public function testRemovedAccountChangesFingerprint(): void
    {
        self::assertNotSame(
            AdminFingerprint::of(['ola@Neos.Neos:Backend', 'jan@Neos.Neos:Backend'], self::SECRET),
            AdminFingerprint::of(['ola@Neos.Neos:Backend'], self::SECRET)
        );
    }

    /** Sól z sekretu instalacji: ten sam skład kont na innej stronie daje inny odcisk. */
    public function testDifferentSecretGivesDifferentFingerprint(): void
    {
        $accounts = ['ola@Neos.Neos:Backend'];

        self::assertNotSame(
            AdminFingerprint::of($accounts, self::SECRET),
            AdminFingerprint::of($accounts, 'ffffffffffffffffffffffffffffffff')
        );
    }

    public function testDuplicateEntriesDoNotChangeFingerprint(): void
    {
        self::assertSame(
            AdminFingerprint::of(['ola@Neos.Neos:Backend'], self::SECRET),
            AdminFingerprint::of(['ola@Neos.Neos:Backend', 'ola@Neos.Neos:Backend'], self::SECRET)
        );
    }

    public function testEmptySetStillProducesFingerprint(): void
    {
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', AdminFingerprint::of([], self::SECRET));
    }
}
