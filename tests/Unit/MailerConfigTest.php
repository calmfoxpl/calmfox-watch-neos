<?php

declare(strict_types=1);

namespace Calmfox\Watch\Tests\Unit;

use Calmfox\Watch\Core\MailerConfig;
use PHPUnit\Framework\TestCase;

final class MailerConfigTest extends TestCase
{
    public function testExplicitOverrideWins(): void
    {
        $config = MailerConfig::fromExplicit(['host' => 'poczta.example.com', 'port' => 465, 'encryption' => 'SSL']);

        self::assertSame('poczta.example.com', $config['host']);
        self::assertSame(465, $config['port']);
        self::assertSame('ssl', $config['encryption']);
    }

    public function testEmptyOverrideIsIgnored(): void
    {
        self::assertNull(MailerConfig::fromExplicit(['host' => '', 'port' => 587]));
    }

    public function testSwiftMailerSmtpTransportIsRead(): void
    {
        $config = MailerConfig::fromSwiftMailer([
            'type' => 'Neos\SwiftMailer\Transport\SmtpTransport',
            'options' => ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls'],
        ]);

        self::assertSame('smtp.example.com', $config['host']);
        self::assertSame(587, $config['port']);
        self::assertSame('tls', $config['encryption']);
        self::assertSame('Neos.SwiftMailer', $config['source']);
    }

    public function testSwiftMailerSendmailTransportIsNotAnSmtpServer(): void
    {
        $config = MailerConfig::fromSwiftMailer(['type' => 'Swift_SendmailTransport', 'options' => []]);

        self::assertTrue($config['native']);
        self::assertArrayNotHasKey('host', $config);
    }

    public function testSmtpDsnIsParsed(): void
    {
        $config = MailerConfig::fromDsn('smtp://uzytkownik:tajne@smtp.example.com:2525');

        self::assertSame('smtp.example.com', $config['host']);
        self::assertSame(2525, $config['port']);
    }

    /** Dane logowania z adresu DSN nie mają prawa opuścić tej klasy: opis checku trafia do huba. */
    public function testCredentialsNeverLeaveTheParser(): void
    {
        $config = MailerConfig::fromDsn('smtp://uzytkownik:tajne@smtp.example.com:2525');

        self::assertStringNotContainsString('tajne', json_encode($config, \JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('uzytkownik', json_encode($config, \JSON_UNESCAPED_UNICODE));
    }

    public function testSmtpsDsnDefaultsToPortFourSixFive(): void
    {
        $config = MailerConfig::fromDsn('smtps://smtp.example.com');

        self::assertSame(465, $config['port']);
        self::assertSame('ssl', $config['encryption']);
    }

    public function testEncryptionCanComeFromQueryString(): void
    {
        $config = MailerConfig::fromDsn('smtp://smtp.example.com:587?encryption=tls');

        self::assertSame('tls', $config['encryption']);
    }

    /** Wysyłka przez API dostawcy: nie ma czego odpytać po SMTP i tak to nazywamy. */
    public function testProviderApiDsnIsNotAnSmtpTest(): void
    {
        $config = MailerConfig::fromDsn('ses+api://KLUCZ:SEKRET@default');

        self::assertSame('ses', $config['api']);
        self::assertArrayNotHasKey('host', $config);
    }

    public function testProviderSmtpDsnWithoutHostIsStillNotTestable(): void
    {
        $config = MailerConfig::fromDsn('sendgrid+smtp://KLUCZ@default');

        self::assertSame('sendgrid', $config['api']);
    }

    public function testSendmailDsnIsNative(): void
    {
        self::assertTrue(MailerConfig::fromDsn('sendmail://default')['native']);
    }

    public function testNullDsnIsReportedAsDisabledDelivery(): void
    {
        self::assertSame('wysyłka wyłączona', MailerConfig::fromDsn('null://null')['api']);
    }

    public function testEmptyDsnGivesNothing(): void
    {
        self::assertNull(MailerConfig::fromDsn('   '));
    }
}
