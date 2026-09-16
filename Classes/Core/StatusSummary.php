<?php

declare(strict_types=1);

namespace Calmfox\Watch\Core;

/**
 * Skrót obu sekcji do jednego zdania i krótkiej listy problemów. Używa go
 * kafelek na pulpicie panelu administracyjnego: tam nie ma miejsca na pełną
 * tabelę sprawdzeń, a osoba, która właśnie zalogowała się do sklepu, ma
 * zobaczyć jedno z dwóch: „wszystko działa" albo „to jest zepsute".
 *
 * Kolejność problemów jest kolejnością pilności, nie kolejnością sekcji:
 * najpierw awarie (`fail`), potem ostrzeżenia (`warn`). W obrębie tej samej
 * wagi zostaje kolejność sprawdzeń, czyli stan usług przed higieną
 * bezpieczeństwa: leżąca baza jest ważniejsza niż zbyt szeroko ustawione
 * prawa katalogu, choć jedno i drugie jest na czerwono.
 */
final class StatusSummary
{
    /**
     * @param array<string, mixed> $health   sekcja `health` albo pusta tablica
     * @param array<string, mixed> $security sekcja `security` albo pusta tablica
     *
     * @return array{status: string, counts: array{ok: int, warn: int, fail: int}, problems: list<array<string, mixed>>, total: int}
     */
    public static function of(array $health, array $security, int $limit = 3): array
    {
        $checks = [];
        foreach ([$health, $security] as $payload) {
            foreach (\is_array($payload['checks'] ?? null) ? $payload['checks'] : [] as $check) {
                if (\is_array($check) && isset($check['status'])) {
                    $checks[] = $check;
                }
            }
        }

        $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        $problems = ['fail' => [], 'warn' => []];
        foreach ($checks as $check) {
            $status = (string) $check['status'];
            if (!isset($counts[$status])) {
                continue;
            }
            ++$counts[$status];
            if ('ok' !== $status) {
                $problems[$status][] = $check;
            }
        }

        $ordered = array_merge($problems['fail'], $problems['warn']);

        return [
            'status' => $counts['fail'] > 0 ? 'fail' : ($counts['warn'] > 0 ? 'warn' : 'ok'),
            'counts' => $counts,
            'problems' => \array_slice($ordered, 0, max(0, $limit)),
            // Ile problemów jest naprawdę, żeby kafelek mógł uczciwie powiedzieć
            // „i 4 dalsze" zamiast udawać, że lista jest kompletna.
            'total' => \count($ordered),
        ];
    }
}
