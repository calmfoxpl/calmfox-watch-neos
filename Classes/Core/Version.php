<?php

declare(strict_types=1);

namespace Calmfox\Watch\Core;

/**
 * Jedyne źródło prawdy o wersji pakietu. Świadomie NIE w composer.json:
 * Composer wylicza wersję z tagów repozytorium, więc pole `version` w pliku
 * zawsze prędzej czy później rozjeżdża się z rzeczywistością. Skrypt paczki
 * (scripts/build-neos-zip.sh) czyta tę stałą, payload dla huba też — jedna
 * liczba, jedno miejsce edycji.
 */
final class Version
{
    public const NUMBER = '1.3.1';
}
