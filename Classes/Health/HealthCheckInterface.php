<?php

declare(strict_types=1);

namespace Calmfox\Watch\Health;

/**
 * Punkt rozszerzenia dla usług klienta. Odpowiednik filtra
 * `calmfox_watch_health_checks` z wtyczki WordPressa, tyle że po flowowemu:
 * wystarczy zaimplementować ten interfejs w dowolnym pakiecie, a Flow znajdzie
 * klasę sam (ReflectionService zbiera implementacje w czasie kompilacji, więc
 * na produkcji nie kosztuje to nic).
 *
 * Przykład (Twoja aplikacja, Classes/Monitoring/BrokerCheck.php):
 *
 *     class BrokerCheck implements HealthCheckInterface
 *     {
 *         public function run(): ?array
 *         {
 *             $socket = fsockopen('127.0.0.1', 5672, $number, $text, 2);
 *             if (!is_resource($socket)) {
 *                 return ['id' => 'rabbitmq', 'status' => 'fail', 'label' => 'Kolejka RabbitMQ',
 *                         'detail' => 'Broker nie przyjmuje połączeń.'];
 *             }
 *             fclose($socket);
 *
 *             return ['id' => 'rabbitmq', 'status' => 'ok', 'label' => 'Kolejka RabbitMQ'];
 *         }
 *     }
 *
 * Klasa NIE może być `final`: Flow buduje dla wstrzykiwanych obiektów klasy
 * pośredniczące przez dziedziczenie i finalnej nie da się w ten sposób opakować.
 *
 * W przykładzie NIE ma operatora wyciszania błędów przed fsockopen, i to nie
 * przypadek: Flow przepuszcza każdy docblok przez parser adnotacji Doctrine,
 * który „małpę" ze słowem traktuje jak nieznaną adnotację i przerywa kompilację
 * klas proxy. Taki komentarz zdejmuje CAŁĄ stronę, nie tylko ten pakiet.
 * Wyciszaj błędy w kodzie, nie w przykładach w komentarzu.
 *
 * Dwie zasady, obie z kontraktu:
 * 1. Zwróć null, gdy usługi na tej instalacji NIE MA. Nie wysyłamy „ok"
 *    o czymś, czego nie ma, bo to jest kłamstwo w panelu klienta.
 * 2. Trzymaj twardy, krótki limit czasu. Wynik tej metody wchodzi w odpowiedź
 *    adresu kontrolnego, który sonda odpytuje co minutę.
 */
interface HealthCheckInterface
{
    /**
     * @return array{id: string, status: string, label?: string, detail?: string|null, ms?: int|null}|null
     */
    public function run(): ?array;
}
