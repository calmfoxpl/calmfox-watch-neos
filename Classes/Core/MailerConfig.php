<?php

declare(strict_types=1);

namespace Calmfox\Watch\Core;

/**
 * Rozpoznanie konfiguracji poczty. Neos w wersji 8 wysyła przez Neos.SwiftMailer,
 * nowsze wdrożenia przez Symfony Mailer z adresem DSN — a część klientów nadpisuje
 * jedno i drugie własnym transportem. Dlatego czytamy trzy źródła i mamy jawne
 * nadpisanie w ustawieniach pakietu.
 *
 * Kluczowa uczciwość: gdy wysyłka idzie przez API dostawcy (SES, SendGrid,
 * Mailgun, Postmark), NIE ma czego testować po SMTP i mówimy to wprost, zamiast
 * pokazywać zielony wynik testu, którego nie wykonaliśmy.
 *
 * Hasła i loginy z adresu DSN nigdy nie wychodzą z tej klasy: zwracamy wyłącznie
 * host, port i sposób szyfrowania.
 */
final class MailerConfig
{
    /** Sposoby wysyłki, które są wysyłką przez API dostawcy, a nie połączeniem SMTP. */
    private const API_SCHEMES = ['ses', 'sendgrid', 'mailgun', 'postmark', 'mandrill', 'sendinblue', 'brevo', 'mailjet', 'mailchimp', 'gmail', 'infobip', 'mailpace', 'scaleway', 'sendcloud'];

    /**
     * Jawne nadpisanie z Settings.yaml (Calmfox.Watch.smtp).
     *
     * @param array<string, mixed> $settings
     *
     * @return array{host?: string, port?: int, encryption?: string, api?: string, native?: bool, source: string}|null
     */
    public static function fromExplicit(array $settings): ?array
    {
        $host = trim((string) ($settings['host'] ?? ''));
        if ('' === $host) {
            return null;
        }

        return [
            'host' => $host,
            'port' => (int) ($settings['port'] ?? 587),
            'encryption' => mb_strtolower(trim((string) ($settings['encryption'] ?? ''))),
            'source' => 'ustawienia pakietu',
        ];
    }

    /**
     * Neos.SwiftMailer.transport z Neosa 8. Sprawdzamy typ transportu, bo
     * SendmailTransport i NullTransport nie mają żadnego serwera do odpytania.
     *
     * @param array<string, mixed> $transport
     *
     * @return array{host?: string, port?: int, encryption?: string, api?: string, native?: bool, source: string}|null
     */
    public static function fromSwiftMailer(array $transport): ?array
    {
        $type = (string) ($transport['type'] ?? '');
        if ('' === $type) {
            return null;
        }
        $options = \is_array($transport['options'] ?? null) ? $transport['options'] : [];
        $lower = mb_strtolower($type);

        if (str_contains($lower, 'sendmail') || str_contains($lower, 'mail_transport')) {
            return ['native' => true, 'source' => 'Neos.SwiftMailer'];
        }
        if (str_contains($lower, 'null')) {
            return ['native' => true, 'source' => 'Neos.SwiftMailer'];
        }
        if (!str_contains($lower, 'smtp')) {
            return ['api' => $type, 'source' => 'Neos.SwiftMailer'];
        }

        $host = trim((string) ($options['host'] ?? ''));
        if ('' === $host) {
            return null;
        }
        $encryption = mb_strtolower(trim((string) ($options['encryption'] ?? $options['security'] ?? '')));

        return [
            'host' => $host,
            'port' => (int) ($options['port'] ?? ('ssl' === $encryption ? 465 : 25)),
            'encryption' => $encryption,
            'source' => 'Neos.SwiftMailer',
        ];
    }

    /**
     * Adres DSN Symfony Mailera (Neos.SymfonyMailer.transport.dsn albo zmienna
     * środowiskowa MAILER_DSN).
     *
     * @return array{host?: string, port?: int, encryption?: string, api?: string, native?: bool, source: string}|null
     */
    public static function fromDsn(string $dsn, string $source = 'Symfony Mailer'): ?array
    {
        $dsn = trim($dsn);
        if ('' === $dsn) {
            return null;
        }
        $parts = parse_url($dsn);
        if (false === $parts || !isset($parts['scheme'])) {
            return null;
        }
        $scheme = mb_strtolower((string) $parts['scheme']);
        $family = explode('+', $scheme)[0];
        $transport = explode('+', $scheme)[1] ?? '';

        if (\in_array($scheme, ['sendmail', 'native'], true)) {
            return ['native' => true, 'source' => $source];
        }
        if ('null' === $scheme) {
            return ['api' => 'wysyłka wyłączona', 'source' => $source];
        }

        // Dostawca po API albo po jego własnym SMTP: „ses+smtp" da się odpytać,
        // „ses+api" nie ma z czym rozmawiać po SMTP i tak to nazywamy.
        if (\in_array($family, self::API_SCHEMES, true) && 'smtp' !== $transport && 'smtps' !== $transport) {
            return ['api' => $family, 'source' => $source];
        }

        $host = trim((string) ($parts['host'] ?? ''));
        if ('' === $host || 'default' === $host) {
            // Dostawca sam wie, gdzie się łączy (np. ses+smtp://KEY:SECRET@default),
            // a my nie znamy jego hosta. Nie zgadujemy.
            return ['api' => '' !== $family ? $family : $scheme, 'source' => $source];
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $encryption = mb_strtolower(trim((string) ($query['encryption'] ?? '')));
        if ('' === $encryption && \in_array('smtps', [$scheme, $transport], true)) {
            $encryption = 'ssl';
        }

        return [
            'host' => $host,
            'port' => (int) ($parts['port'] ?? ('ssl' === $encryption ? 465 : 25)),
            'encryption' => $encryption,
            'source' => $source,
        ];
    }
}
