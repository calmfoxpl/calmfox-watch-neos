<?php

declare(strict_types=1);

namespace Calmfox\Watch\Core;

/**
 * Normalizacja i agregacja wyników sprawdzeń. Klasa jest celowo bez żadnej
 * zależności od Flow: to samo liczy hub po swojej stronie (WpHealthClient),
 * więc rozjazd między nami a nim byłby najdroższym możliwym błędem. Testujemy
 * ją bez bootstrapowania frameworka.
 *
 * Punkt rozszerzenia (HealthCheckInterface) może zwrócić cokolwiek, dlatego
 * przycinamy tak samo, jak zrobi to hub: nadmiar i tak by zniknął, a wtedy
 * panel pokazałby coś innego niż ekran w Neosie.
 */
final class CheckNormalizer
{
    public const STATUSES = ['ok', 'warn', 'fail'];

    /** Tyle samo, ile przyjmuje hub. Więcej pozycji i tak by odpadło po drodze. */
    public const MAX_CHECKS = 60;

    /** Limit przykładowego polecenia naprawczego — taki sam po stronie huba. */
    public const MAX_COMMAND = 200;

    /**
     * @param iterable<mixed> $checks
     *
     * @return list<array{id: string, status: string, label: ?string, detail: ?string, fix: ?string, command: ?string, ms: ?int}>
     */
    public static function normalize(iterable $checks): array
    {
        $out = [];
        foreach ($checks as $check) {
            if (!\is_array($check)) {
                continue;
            }
            $id = mb_strtolower(trim((string) ($check['id'] ?? '')));
            $status = (string) ($check['status'] ?? '');
            if (1 !== preg_match('/^[a-z0-9_-]{1,40}$/', $id) || !\in_array($status, self::STATUSES, true)) {
                continue;
            }
            $ms = $check['ms'] ?? null;
            $out[] = [
                'id' => $id,
                'status' => $status,
                'label' => self::text($check['label'] ?? null, 80),
                'detail' => self::text($check['detail'] ?? null, 300),
                'fix' => self::text($check['fix'] ?? null, 200),
                'command' => self::command($check['command'] ?? null),
                'ms' => \is_int($ms) && $ms >= 0 ? $ms : null,
            ];
            if (\count($out) >= self::MAX_CHECKS) {
                break;
            }
        }

        return $out;
    }

    /**
     * Agregat sekcji: fail bije warn, warn bije ok. Od tego zależy kod HTTP
     * (503 przy fail), czyli to, czy monitoring otworzy incydent.
     *
     * @param iterable<array<string, mixed>> $checks
     */
    public static function aggregate(iterable $checks): string
    {
        $status = 'ok';
        foreach ($checks as $check) {
            $current = (string) ($check['status'] ?? '');
            if ('fail' === $current) {
                return 'fail';
            }
            if ('warn' === $current) {
                $status = 'warn';
            }
        }

        return $status;
    }

    /**
     * Przykładowe polecenie naprawcze. Panel daje przy nim przycisk kopiowania,
     * więc jest jedyną wartością z tej instalacji, którą ktoś wkleja sobie do
     * terminala: jedna linia i wyłącznie drukowalne ASCII. Za długiego NIE
     * przycinamy, tylko wyrzucamy w całości — polecenie urwane w połowie ścieżki
     * jest gorsze niż jego brak, bo wygląda na gotowe do wklejenia.
     */
    public static function command(mixed $value): ?string
    {
        $clean = self::text($value, self::MAX_COMMAND + 1);

        return null !== $clean && mb_strlen($clean) <= self::MAX_COMMAND
            && 1 === preg_match('/^[\x20-\x7E]+$/', $clean) ? $clean : null;
    }

    /** Tekst do payloadu: bez znaczników, bez wielokrotnych spacji, przycięty do limitu kontraktu. */
    public static function text(mixed $value, int $max): ?string
    {
        if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
            return null;
        }
        $clean = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $value)));

        return '' === $clean ? null : mb_substr($clean, 0, $max);
    }
}
