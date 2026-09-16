<?php

declare(strict_types=1);

namespace Calmfox\Watch\Service;

use Calmfox\Watch\Core\VersionSnapshot;
use Neos\Flow\Annotations as Flow;

/**
 * Historia zmian wersji pakietów. Composer wymienia je poza aplikacją, więc
 * nie ma hooka, pod który dałoby się podpiąć: porównujemy migawkę przy każdym
 * budowaniu sekcji security (a ta ma pamięć podręczną na 10 minut, więc koszt
 * jest znikomy) i przy poleceniach CLI.
 *
 * Historia zaczyna się w chwili instalacji pakietu. Pierwsze uzgodnienie tylko
 * zapisuje migawkę i nie generuje wpisów — inaczej dzień wdrożenia wyglądałby
 * jak dzień, w którym ktoś zainstalował całą aplikację od zera.
 */
#[Flow\Scope('singleton')]
class VersionHistory
{
    #[Flow\Inject]
    protected StateProvider $stateProvider;

    #[Flow\Inject]
    protected PackageVersions $packageVersions;

    /**
     * Uzgodnienie migawki z faktycznymi wersjami.
     *
     * @return list<array<string, mixed>> wpisy dopisane do historii przy tym wywołaniu
     */
    public function reconcile(): array
    {
        $state = $this->stateProvider->state();
        $current = VersionSnapshot::fromInstalled($this->packageVersions->installed(), \PHP_VERSION);
        if ([] === $current) {
            return []; // brak odczytu z Composera: lepiej nie ruszać migawki niż skasować ją do zera
        }

        $previous = $state->snapshot();
        if ([] === $previous) {
            $state->storeSnapshot($current);

            return [];
        }

        $entries = VersionSnapshot::diff($previous, $current, gmdate('c'));
        if ([] !== $entries) {
            $state->prependHistory($entries);
        }
        $state->storeSnapshot($current);

        return $entries;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->stateProvider->state()->history();
    }
}
