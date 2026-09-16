<?php

declare(strict_types=1);

namespace Calmfox\Watch\Tests\Unit;

use Calmfox\Watch\Core\CheckNormalizer;
use Calmfox\Watch\Core\FixCommand;
use PHPUnit\Framework\TestCase;

final class FixCommandTest extends TestCase
{
    public function testSimplePathsStayReadable(): void
    {
        self::assertSame('chmod 640 Configuration/Production/Settings.yaml',
            FixCommand::chmod('640', ['Configuration/Production/Settings.yaml']));
    }

    public function testRecursiveFlagGoesBeforeTheMode(): void
    {
        self::assertSame('chmod -R 755 Data Web/_Resources',
            FixCommand::chmod('755', ['Data', 'Web/_Resources'], recursive: true));
    }

    /** Ścieżka ze spacją bez cudzysłowów wykonałaby chmod na dwóch innych plikach. */
    public function testPathWithSpaceIsQuoted(): void
    {
        self::assertSame("chmod 600 'Data/Persistent/Klucz szyfrujący'",
            FixCommand::chmod('600', ['Data/Persistent/Klucz szyfrujący']));
    }

    public function testApostropheInPathIsEscaped(): void
    {
        self::assertSame("chmod 600 'Data/O'\\''Brien/EncryptionKey'",
            FixCommand::chmod('600', ["Data/O'Brien/EncryptionKey"]));
    }

    /**
     * Komplet ścieżek nie mieści się w limicie kontraktu: zostaje przykład na
     * pierwszym pliku. Lista ucięta w połowie nazwy wyglądałaby na gotową
     * do wklejenia i zrobiłaby coś innego, niż mówi opis.
     */
    public function testTooLongListFallsBackToTheFirstPath(): void
    {
        $long = str_repeat('a', 150);
        $command = FixCommand::chmod('640', [$long, str_repeat('b', 150)]);

        self::assertSame('chmod 640 '.$long, $command);
        self::assertLessThanOrEqual(CheckNormalizer::MAX_COMMAND, mb_strlen((string) $command));
    }

    public function testNoPathsMeansNoCommand(): void
    {
        self::assertNull(FixCommand::chmod('640', []));
        self::assertNull(FixCommand::chmod('640', ['']));
    }
}
