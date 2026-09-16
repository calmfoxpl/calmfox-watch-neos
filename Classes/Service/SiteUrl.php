<?php

declare(strict_types=1);

namespace Calmfox\Watch\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;

/**
 * Adres strony widziany z zewnątrz. Potrzebujemy go w trzech miejscach:
 * do zbudowania adresu kontrolnego dla huba, do sprawdzenia szyfrowania
 * i do podania domeny przy rejestracji.
 *
 * Kolejność źródeł nie jest przypadkowa. Najpierw ustawienia (najpierw nasze
 * Calmfox.Watch.baseUri, potem Neos.Flow.http.baseUri), bo to jedyne wartości,
 * które ktoś świadomie ustawił. Potem bieżące żądanie, bo
 * ono na pewno mówi, po czym klient przyszedł. Repozytorium domen dopiero na
 * końcu i w try/catch: potrzebuje bazy, a ta klasa musi działać także wtedy,
 * gdy baza leży. Polecenia CLI korzystają z niej najczęściej właśnie w drugą
 * stronę, bo w konsoli nie ma żadnego żądania.
 */
#[Flow\Scope('singleton')]
class SiteUrl
{
    #[Flow\InjectConfiguration(path: 'baseUri', package: 'Calmfox.Watch')]
    protected mixed $configuredBaseUri = null;

    #[Flow\InjectConfiguration(path: 'http.baseUri', package: 'Neos.Flow')]
    protected mixed $baseUri = null;

    #[Flow\Inject]
    protected ObjectManagerInterface $objectManager;

    public function baseUri(): string
    {
        // Ustawienie pakietu jest pierwsze, bo bywa jedynym źródłem: instalacja
        // bez wpisanych domen i bez Neos.Flow.http.baseUri nie ma z czego podać
        // hosta w konsoli (sprawdzone na żywej instalacji Neos 9.1.5).
        foreach ([$this->configuredBaseUri, $this->baseUri] as $candidate) {
            $configured = trim((string) ($candidate ?? ''));
            if ('' !== $configured) {
                return rtrim($configured, '/');
            }
        }
        $fromRequest = $this->fromRequest();
        if ('' !== $fromRequest) {
            return $fromRequest;
        }

        return $this->fromDomainRepository();
    }

    public function domain(): string
    {
        return (string) parse_url($this->baseUri(), \PHP_URL_HOST);
    }

    public function isHttps(): bool
    {
        return 'https' === parse_url($this->baseUri(), \PHP_URL_SCHEME);
    }

    private function fromRequest(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ('' === $host) {
            return '';
        }
        $forwarded = mb_strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $secure = 'https' === $forwarded
            || 'on' === mb_strtolower((string) ($_SERVER['HTTPS'] ?? ''))
            || 'https' === mb_strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? ''));

        return ($secure ? 'https://' : 'http://').$host;
    }

    /** Ostatnia deska ratunku dla konsoli: pierwsza domena podstawowa aktywnej strony. */
    private function fromDomainRepository(): string
    {
        try {
            $repositoryClass = 'Neos\Neos\Domain\Repository\DomainRepository';
            if (!class_exists($repositoryClass)) {
                return '';
            }
            $repository = $this->objectManager->get($repositoryClass);
            $domain = null;
            foreach ($repository->findAll() as $candidate) {
                if (method_exists($candidate, 'getActive') && !$candidate->getActive()) {
                    continue;
                }
                $domain = $candidate;
                break;
            }
            if (null === $domain) {
                return '';
            }
            $scheme = method_exists($domain, 'getScheme') ? (string) $domain->getScheme() : '';
            $hostname = method_exists($domain, 'getHostname') ? (string) $domain->getHostname() : '';
            if ('' === $hostname) {
                return '';
            }

            return ('' !== $scheme ? $scheme : 'https').'://'.$hostname;
        } catch (\Throwable) {
            // Brak bazy albo starsza wersja API: adres i tak poda ustawienie
            // albo żądanie, a wywrócenie się tutaj zabiłoby cały endpoint.
            return '';
        }
    }
}
