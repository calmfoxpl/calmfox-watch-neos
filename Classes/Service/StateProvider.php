<?php

declare(strict_types=1);

namespace Calmfox\Watch\Service;

use Calmfox\Watch\Core\InstallationState;
use Neos\Flow\Annotations as Flow;

/**
 * Jedno wejście do stanu instalacji dla całego pakietu. Ścieżka jest
 * konfigurowalna, bo część wdrożeń trzyma Data/Persistent na współdzielonym
 * wolumenie (kilka instancji aplikacji, jeden stan) — a sekret MUSI być
 * wspólny dla wszystkich instancji, inaczej hub trafi raz na jedną, raz
 * na drugą i dostanie 403.
 */
#[Flow\Scope('singleton')]
class StateProvider
{
    #[Flow\InjectConfiguration(path: 'statePath', package: 'Calmfox.Watch')]
    protected string $statePath = '';

    private ?InstallationState $state = null;

    public function state(): InstallationState
    {
        return $this->state ??= new InstallationState($this->resolvePath());
    }

    private function resolvePath(): string
    {
        $path = trim($this->statePath);
        if ('' === $path) {
            $path = '%FLOW_PATH_DATA%Persistent/CalmfoxWatch/state.json';
        }
        // Flow podstawia %STAŁE% w plikach ustawień, ale ścieżka bywa też
        // podawana zmienną środowiskową, gdzie nikt tego za nas nie zrobi.
        if (str_contains($path, '%FLOW_PATH_DATA%') && \defined('FLOW_PATH_DATA')) {
            $path = str_replace('%FLOW_PATH_DATA%', (string) \constant('FLOW_PATH_DATA'), $path);
        }

        return $path;
    }
}
