<?php

declare(strict_types=1);

namespace Calmfox\Watch\Service;

use Neos\Flow\Annotations as Flow;

/**
 * Liczenie zaległych aktualizacji. Świadomie NIE robimy tego w endpointcie:
 * `composer outdated` odpytuje repozytoria pakietów przez sieć i potrafi trwać
 * kilkanaście sekund, a endpoint stanu ma odpowiadać w ułamku sekundy i nie
 * może zależeć od dostępności packagist.org.
 *
 * Dlatego liczby powstają w poleceniu `./flow calmfoxwatch:updates`, wpuszczanym
 * w zadania cykliczne raz na dobę, i lądują w pliku stanu. Dopóki nikt tego nie
 * uruchomi, mówimy wprost „nie sprawdzamy" i POMIJAMY pole updates w payloadzie,
 * zamiast wysyłać uspokajające zera.
 */
#[Flow\Scope('singleton')]
class UpdateCounter
{
    /** Composer chodzi po sieci; dłużej niż minutę to znaczy, że coś jest nie tak z siecią, nie z pakietami. */
    private const TIMEOUT = 120;

    #[Flow\Inject]
    protected StateProvider $stateProvider;

    #[Flow\InjectConfiguration(path: 'composerBinary', package: 'Calmfox.Watch')]
    protected string $composerBinary = '';

    /**
     * @return array{ok: bool, message: string, core: int, plugins: int}
     */
    public function refresh(): array
    {
        $result = $this->runComposerOutdated();
        if (!$result['ok']) {
            return $result;
        }
        $this->stateProvider->state()->storeUpdates($result['core'], $result['plugins']);

        return $result;
    }

    /**
     * @return array{ok: bool, message: string, core: int, plugins: int}
     */
    private function runComposerOutdated(): array
    {
        $binary = $this->composerBinary();
        if ('' === $binary) {
            return ['ok' => false, 'core' => 0, 'plugins' => 0,
                'message' => 'Nie znaleziono programu composer na serwerze. Wskaż go w ustawieniu Calmfox.Watch.composerBinary albo uruchamiaj to polecenie tam, gdzie composer jest dostępny.'];
        }

        $root = \defined('FLOW_PATH_ROOT') ? (string) \constant('FLOW_PATH_ROOT') : getcwd().'/';
        $command = sprintf(
            '%s outdated --direct --format=json --no-interaction --no-ansi --no-plugins --working-dir=%s 2>/dev/null',
            escapeshellcmd($binary),
            escapeshellarg(rtrim($root, '/'))
        );

        $output = $this->execute($command);
        if (null === $output) {
            return ['ok' => false, 'core' => 0, 'plugins' => 0,
                'message' => 'Uruchomienie programów zewnętrznych jest na tym serwerze zablokowane, więc nie policzymy aktualizacji.'];
        }

        $decoded = json_decode($output, true);
        if (!\is_array($decoded) || !isset($decoded['installed']) || !\is_array($decoded['installed'])) {
            return ['ok' => false, 'core' => 0, 'plugins' => 0,
                'message' => 'Composer nie zwrócił listy pakietów. Sprawdź, czy polecenie „composer outdated" działa w katalogu aplikacji.'];
        }

        $core = 0;
        $plugins = 0;
        foreach ($decoded['installed'] as $package) {
            if (!\is_array($package)) {
                continue;
            }
            $name = mb_strtolower((string) ($package['name'] ?? ''));
            $latest = (string) ($package['latest'] ?? '');
            $version = (string) ($package['version'] ?? '');
            if ('' === $name || '' === $latest || $latest === $version) {
                continue;
            }
            if ('neos/neos' === $name) {
                ++$core;
            } else {
                ++$plugins;
            }
        }

        return ['ok' => true, 'core' => $core, 'plugins' => $plugins,
            'message' => sprintf('Policzone: Neos %d, pozostałe pakiety %d.', $core, $plugins)];
    }

    private function execute(string $command): ?string
    {
        if (!\function_exists('proc_open')) {
            return null;
        }
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes);
        if (!\is_resource($process)) {
            return null;
        }
        stream_set_timeout($pipes[1], self::TIMEOUT);
        $output = (string) stream_get_contents($pipes[1]);
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);

        return $output;
    }

    private function composerBinary(): string
    {
        $configured = trim($this->composerBinary);
        if ('' !== $configured) {
            return $configured; // wdrożenie wie lepiej niż nasze zgadywanie
        }
        foreach (['composer', 'composer.phar', '/usr/local/bin/composer', '/usr/bin/composer'] as $candidate) {
            if (str_contains($candidate, '/') && is_file($candidate)) {
                return $candidate;
            }
            $found = $this->execute('command -v '.escapeshellarg($candidate).' 2>/dev/null');
            if (null !== $found && '' !== trim($found)) {
                return trim($found);
            }
        }

        return '';
    }
}
