<?php

declare(strict_types=1);

namespace Calmfox\Watch\Health;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;

/**
 * Publikacja zasobów. Ten check pilnuje awarii, która na pierwszy rzut oka
 * wygląda jak „strona działa": Flow publikuje arkusze stylów, skrypty i obrazy
 * do Web/_Resources, a przy niezapisywalnym katalogu publikacja po cichu nie
 * dochodzi do skutku. Dopóki stare pliki tam leżą, nikt niczego nie zauważy.
 * Przy najbliższym czyszczeniu pamięci podręcznej albo wdrożeniu strona zostaje
 * bez wyglądu, a monitoring dostępności dalej widzi kod 200.
 *
 * Sprawdzamy dwie rzeczy: czy katalog docelowy publikacji jest zapisywalny
 * i czy Web/_Resources w ogóle istnieje.
 */
#[Flow\Scope('singleton')]
class ResourcesCheck implements HealthCheckInterface
{
    #[Flow\Inject]
    protected ConfigurationManager $configurationManager;

    public function run(): ?array
    {
        $label = 'Publikacja zasobów strony';
        $published = $this->publishTarget();
        $persistent = $this->dataPath().'Persistent/Resources';

        if (!is_dir($published)) {
            return ['id' => 'resources', 'status' => 'fail', 'label' => $label,
                'detail' => sprintf('Katalog publikacji %s nie istnieje. Strona nie ma skąd wziąć arkuszy stylów, skryptów ani obrazów.', $this->relative($published))];
        }
        if (!is_writable($published)) {
            return ['id' => 'resources', 'status' => 'fail', 'label' => $label,
                'detail' => sprintf('Katalog publikacji %s nie jest zapisywalny. Przy najbliższym wdrożeniu albo czyszczeniu pamięci podręcznej strona straci wygląd.', $this->relative($published))];
        }
        if (is_dir($persistent) && !is_writable($persistent)) {
            return ['id' => 'resources', 'status' => 'fail', 'label' => $label,
                'detail' => 'Magazyn plików Data/Persistent/Resources nie jest zapisywalny. Wgranie obrazu albo dokumentu zakończy się błędem.'];
        }

        return ['id' => 'resources', 'status' => 'ok', 'label' => $label,
            'detail' => sprintf('Katalog publikacji %s jest zapisywalny.', $this->relative($published))];
    }

    private function publishTarget(): string
    {
        $configured = null;
        try {
            $configured = $this->configurationManager->getConfiguration(
                ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
                'Neos.Flow.resource.targets.localWebDirectoryStaticResourcesTarget.targetOptions.path'
            );
        } catch (\Throwable) {
            $configured = null;
        }
        if (\is_string($configured) && '' !== trim($configured)) {
            return rtrim($configured, '/');
        }

        return rtrim($this->webPath().'_Resources', '/');
    }

    private function relative(string $path): string
    {
        $root = \defined('FLOW_PATH_ROOT') ? (string) \constant('FLOW_PATH_ROOT') : '';

        return '' !== $root && str_starts_with($path, $root) ? substr($path, \strlen($root)) : $path;
    }

    private function webPath(): string
    {
        return \defined('FLOW_PATH_WEB') ? (string) \constant('FLOW_PATH_WEB') : getcwd().'/Web/';
    }

    private function dataPath(): string
    {
        return \defined('FLOW_PATH_DATA') ? (string) \constant('FLOW_PATH_DATA') : getcwd().'/Data/';
    }
}
