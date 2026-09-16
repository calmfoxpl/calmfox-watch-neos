<?php

declare(strict_types=1);

namespace Calmfox\Watch\Core;

/**
 * Jednokierunkowy odcisk zbioru kont z pełnymi uprawnieniami. Hub porównuje
 * odciski między odpytaniami i wykrywa ZMIANĘ SKŁADU, także wtedy, gdy liczba
 * kont się nie zmieniła (podmiana konta to klasyczny ruch po przejęciu strony).
 *
 * Loginów nie wysyłamy nigdy. Sól z sekretu instalacji sprawia dodatkowo, że
 * odcisk jest bezużyteczny poza tą jedną stroną: nie da się go porównać ze
 * słownikiem popularnych nazw kont ani zestawić z inną instalacją.
 */
final class AdminFingerprint
{
    /** @param iterable<string> $identifiers dowolnie stabilne identyfikatory kont (u nas: identyfikator konta i dostawca uwierzytelniania) */
    public static function of(iterable $identifiers, string $secret): string
    {
        $parts = [];
        foreach ($identifiers as $identifier) {
            $identifier = trim((string) $identifier);
            if ('' !== $identifier) {
                $parts[$identifier] = true;
            }
        }
        $parts = array_keys($parts);
        // Sortowanie jest częścią kontraktu, nie kosmetyką: repozytorium może
        // oddać konta w innej kolejności przy tym samym składzie, a wtedy hub
        // zobaczyłby „zmianę" i otworzył incydent bez powodu.
        sort($parts, \SORT_STRING);

        return substr(hash_hmac('sha256', implode('|', $parts), $secret), 0, 32);
    }
}
