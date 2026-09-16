<?php

declare(strict_types=1);

namespace Calmfox\Watch\Tests\Unit;

use Calmfox\Watch\Core\CheckNormalizer;
use PHPUnit\Framework\TestCase;

final class CheckNormalizerTest extends TestCase
{
    public function testEmptySectionIsOk(): void
    {
        self::assertSame('ok', CheckNormalizer::aggregate([]));
    }

    public function testAllOkAggregatesToOk(): void
    {
        self::assertSame('ok', CheckNormalizer::aggregate([
            ['id' => 'db', 'status' => 'ok'],
            ['id' => 'disk', 'status' => 'ok'],
        ]));
    }

    public function testWarnBeatsOk(): void
    {
        self::assertSame('warn', CheckNormalizer::aggregate([
            ['id' => 'db', 'status' => 'ok'],
            ['id' => 'smtp', 'status' => 'warn'],
            ['id' => 'disk', 'status' => 'ok'],
        ]));
    }

    public function testFailBeatsWarn(): void
    {
        self::assertSame('fail', CheckNormalizer::aggregate([
            ['id' => 'smtp', 'status' => 'warn'],
            ['id' => 'db', 'status' => 'fail'],
        ]));
    }

    /** Kolejność nie może zmieniać wyniku: fail zawsze wygrywa, także gdy jest pierwszy. */
    public function testFailWinsRegardlessOfOrder(): void
    {
        self::assertSame('fail', CheckNormalizer::aggregate([
            ['id' => 'db', 'status' => 'fail'],
            ['id' => 'smtp', 'status' => 'warn'],
            ['id' => 'disk', 'status' => 'ok'],
        ]));
    }

    public function testUnknownStatusIsDropped(): void
    {
        $checks = CheckNormalizer::normalize([
            ['id' => 'db', 'status' => 'critical'],
            ['id' => 'disk', 'status' => 'ok'],
        ]);

        self::assertCount(1, $checks);
        self::assertSame('disk', $checks[0]['id']);
    }

    public function testInvalidIdentifierIsDropped(): void
    {
        $checks = CheckNormalizer::normalize([
            ['id' => 'zły identyfikator', 'status' => 'ok'],
            ['id' => '', 'status' => 'ok'],
            ['id' => str_repeat('a', 41), 'status' => 'ok'],
            ['id' => 'flow_cache', 'status' => 'ok'],
        ]);

        self::assertCount(1, $checks);
        self::assertSame('flow_cache', $checks[0]['id']);
    }

    public function testIdentifierIsLowercased(): void
    {
        $checks = CheckNormalizer::normalize([['id' => 'Content_Repository', 'status' => 'ok']]);

        self::assertSame('content_repository', $checks[0]['id']);
    }

    public function testTextsAreTrimmedToContractLimits(): void
    {
        $checks = CheckNormalizer::normalize([[
            'id' => 'db',
            'status' => 'ok',
            'label' => str_repeat('e', 200),
            'detail' => str_repeat('s', 500),
        ]]);

        self::assertSame(80, mb_strlen((string) $checks[0]['label']));
        self::assertSame(300, mb_strlen((string) $checks[0]['detail']));
    }

    public function testMarkupAndWhitespaceAreStripped(): void
    {
        $checks = CheckNormalizer::normalize([[
            'id' => 'db',
            'status' => 'fail',
            'detail' => "  <b>Baza</b>\n\n  nie odpowiada.  ",
        ]]);

        self::assertSame('Baza nie odpowiada.', $checks[0]['detail']);
    }

    public function testNegativeDurationIsDiscarded(): void
    {
        $checks = CheckNormalizer::normalize([
            ['id' => 'db', 'status' => 'ok', 'ms' => -5],
            ['id' => 'smtp', 'status' => 'ok', 'ms' => 42],
            ['id' => 'disk', 'status' => 'ok', 'ms' => '12'],
        ]);

        self::assertNull($checks[0]['ms']);
        self::assertSame(42, $checks[1]['ms']);
        self::assertNull($checks[2]['ms'], 'ms podane jako tekst nie jest liczbą i nie udajemy, że jest');
    }

    public function testCheckLimitMatchesHub(): void
    {
        $input = [];
        for ($i = 0; $i < 100; ++$i) {
            $input[] = ['id' => 'check'.$i, 'status' => 'ok'];
        }

        self::assertCount(CheckNormalizer::MAX_CHECKS, CheckNormalizer::normalize($input));
    }

    public function testNonArrayEntriesAreIgnored(): void
    {
        self::assertSame([], CheckNormalizer::normalize(['tekst', 42, null]));
    }

    /**
     * Podpowiedź naprawcza jedzie razem z checkiem, a polecenie tylko wtedy, gdy
     * da się je bezpiecznie pokazać do skopiowania: jedna linia, drukowalne ASCII
     * i mieszczące się w limicie. Hub przycina tak samo, więc ekran w Neosie
     * i panel Calmfox pokazują dokładnie to samo.
     */
    public function testFixAndCommandFollowTheContract(): void
    {
        $checks = CheckNormalizer::normalize([
            ['id' => 'config_perms', 'status' => 'warn', 'fix' => str_repeat('x', 400), 'command' => 'chmod 640 Configuration/Production/Settings.yaml'],
            ['id' => 'dir_perms', 'status' => 'fail', 'command' => 'chmod -R 755 '.str_repeat('a', 200)],
            ['id' => 'https', 'status' => 'fail', 'command' => 'echo „cudzysłów drukarski”'],
            ['id' => 'db', 'status' => 'ok'],
        ]);

        self::assertSame(CheckNormalizer::MAX_COMMAND, mb_strlen((string) $checks[0]['fix']));
        self::assertSame('chmod 640 Configuration/Production/Settings.yaml', $checks[0]['command']);
        self::assertNull($checks[1]['command'], 'za długie polecenie wypada w całości, nie przycinamy go w połowie ścieżki');
        self::assertNull($checks[2]['command'], 'poza drukowalnym ASCII nie przepuszczamy nic');
        self::assertNull($checks[3]['fix']);
        self::assertNull($checks[3]['command']);
    }
}
