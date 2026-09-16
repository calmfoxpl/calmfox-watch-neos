<?php

declare(strict_types=1);

namespace Calmfox\Watch\Health;

use Calmfox\Watch\Core\MailerConfig;
use Calmfox\Watch\Service\Cache;
use Calmfox\Watch\Service\SiteUrl;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;

/**
 * Poczta. UCZCIWIE: to jest test POŁĄCZENIA z serwerem poczty (nawiązanie
 * sesji TCP, powitanie 220, odpowiedź na EHLO), a nie test doręczenia. Nie
 * wysyłamy wiadomości próbnych, bo wysyłka do siebie samego niczego nie
 * dowodzi, a wysyłka na cudzy adres to zaśmiecanie skrzynek i szybka droga
 * na listy blokujące.
 *
 * Konfigurację czytamy z trzech miejsc, bo tak wygląda rzeczywistość wdrożeń
 * Neosa: Neos.SwiftMailer (wersja 8), adres DSN Symfony Mailera (nowsze
 * instalacje) i jawne nadpisanie w ustawieniach pakietu. Gdy wysyłka idzie
 * przez API dostawcy, mówimy to wprost i nie udajemy testu SMTP.
 *
 * Wynik żyje 15 minut: to najdroższy check w sekcji, a serwer poczty nie
 * zmienia stanu co minutę.
 */
#[Flow\Scope('singleton')]
class SmtpCheck implements HealthCheckInterface
{
    private const NET_TIMEOUT = 2;

    #[Flow\Inject]
    protected ConfigurationManager $configurationManager;

    #[Flow\Inject]
    protected Cache $cache;

    #[Flow\Inject]
    protected SiteUrl $siteUrl;

    #[Flow\InjectConfiguration(path: 'smtp', package: 'Calmfox.Watch')]
    protected array $smtpOverride = [];

    public function run(): ?array
    {
        $label = 'Wysyłka e-mail (SMTP)';
        $config = $this->detect();

        if (null === $config) {
            return ['id' => 'smtp', 'status' => 'warn', 'label' => $label,
                'detail' => 'Nie znaleźliśmy skonfigurowanego serwera poczty. Wysyłka funkcją systemową bywa zawodna i nie da się jej monitorować.'];
        }
        if (!empty($config['native'])) {
            return ['id' => 'smtp', 'status' => 'warn', 'label' => 'Wysyłka e-mail',
                'detail' => sprintf('Poczta wychodzi programem systemowym serwera (%s), a nie serwerem SMTP. Nie ma tu czego odpytać, więc nie sprawdzamy dostępności wysyłki.', (string) $config['source'])];
        }
        if (!empty($config['api'])) {
            return ['id' => 'smtp', 'status' => 'ok', 'label' => 'Wysyłka e-mail',
                'detail' => sprintf('Wysyłka przez API dostawcy (%s, konfiguracja: %s). Test połączenia SMTP tu nie obowiązuje i go nie wykonujemy.', (string) $config['api'], (string) $config['source'])];
        }

        $cached = $this->cache->get(Cache::SMTP);
        if (\is_array($cached)) {
            return $cached;
        }

        $result = $this->probe($label, (string) $config['host'], (int) $config['port'], (string) ($config['encryption'] ?? ''));
        $this->cache->set(Cache::SMTP, $result, 900);

        return $result;
    }

    /** @return array<string, mixed> */
    private function probe(string $label, string $host, int $port, string $encryption): array
    {
        $target = ('ssl' === $encryption ? 'ssl://' : '').$host;
        $start = microtime(true);
        $number = 0;
        $text = '';
        $socket = @fsockopen($target, $port, $number, $text, self::NET_TIMEOUT);

        if (!\is_resource($socket)) {
            return ['id' => 'smtp', 'status' => 'fail', 'label' => $label,
                'detail' => sprintf('Nie można połączyć z %s:%d, %s.', $host, $port, '' !== $text ? $text : 'brak odpowiedzi'),
                'ms' => (int) round((microtime(true) - $start) * 1000)];
        }

        stream_set_timeout($socket, self::NET_TIMEOUT);
        $greeting = (string) fgets($socket, 512);
        fwrite($socket, 'EHLO '.($this->siteUrl->domain() ?: 'localhost')."\r\n");
        $ehlo = (string) fgets($socket, 512);
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
        $ms = (int) round((microtime(true) - $start) * 1000);

        $fine = str_starts_with($greeting, '220') && str_starts_with($ehlo, '250');

        return ['id' => 'smtp', 'status' => $fine ? 'ok' : 'fail', 'label' => $label,
            'detail' => $fine
                ? sprintf('Serwer %s:%d odpowiada poprawnie. To sprawdzenie połączenia, nie doręczenia wiadomości.', $host, $port)
                : sprintf('Serwer %s odpowiada, ale nie protokołem SMTP: „%s".', $host, trim('' !== $greeting ? $greeting : $ehlo)),
            'ms' => $ms];
    }

    /** @return array<string, mixed>|null */
    private function detect(): ?array
    {
        $explicit = MailerConfig::fromExplicit(\is_array($this->smtpOverride) ? $this->smtpOverride : []);
        if (null !== $explicit) {
            return $explicit;
        }

        $swift = $this->setting('Neos.SwiftMailer.transport');
        if (\is_array($swift)) {
            $fromSwift = MailerConfig::fromSwiftMailer($swift);
            if (null !== $fromSwift) {
                return $fromSwift;
            }
        }

        foreach (['Neos.SymfonyMailer.transport.dsn', 'Neos.Flow.mail.dsn'] as $path) {
            $dsn = $this->setting($path);
            if (\is_string($dsn) && '' !== trim($dsn)) {
                return MailerConfig::fromDsn($dsn, 'Symfony Mailer');
            }
        }

        $fromEnvironment = (string) (getenv('MAILER_DSN') ?: '');
        if ('' !== trim($fromEnvironment)) {
            return MailerConfig::fromDsn($fromEnvironment, 'zmienna środowiskowa MAILER_DSN');
        }

        return null;
    }

    private function setting(string $path): mixed
    {
        try {
            return $this->configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, $path);
        } catch (\Throwable) {
            return null;
        }
    }
}
