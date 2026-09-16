<?php

declare(strict_types=1);

namespace Calmfox\Watch\Core;

/**
 * Stan instalacji trzymany w PLIKU, nie w bazie, i to jest decyzja kontraktowa,
 * nie wygoda: przy padniętej bazie endpoint MA odpowiedzieć „db: fail" i kodem
 * 503. Gdyby sekret siedział w tabeli, strona zamilkłaby dokładnie w tej jednej
 * chwili, w której monitoring naprawdę zarabia.
 *
 * Zapis jest atomowy (plik tymczasowy + rename), bo dwa równoległe żądania
 * potrafią spotkać się na rotacji sekretu, a plik przycięty w połowie oznacza
 * utratę klucza i zerwanie monitoringu. Prawa 600: sekret w URL-u jest
 * jedynym dowodem tożsamości tej instalacji.
 *
 * Klasa nie zna Flow. Testy podstawiają katalog tymczasowy i własny zegar.
 */
final class InstallationState
{
    /** Poprzedni sekret honorowany jeszcze kwadrans po rotacji: nieudane przepięcie w hubie nie zrywa monitoringu. */
    public const PREV_WINDOW = 900;

    /** Nonce parowania żyje tyle, ile trwa challenge huba plus zapas na wolne DNS. */
    public const PAIRING_TTL = 900;

    public const HISTORY_MAX = 200;

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    /** @param (\Closure(): int)|null $clock zegar wstrzykiwany w testach (rotacja, wygasanie nonce'a) */
    public function __construct(
        private readonly string $path,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if (null !== $this->data) {
            return $this->data;
        }
        $stored = [];
        if (is_file($this->path)) {
            $raw = @file_get_contents($this->path);
            $decoded = \is_string($raw) ? json_decode($raw, true) : null;
            if (\is_array($decoded)) {
                $stored = $decoded;
            }
        }

        return $this->data = array_merge(self::defaults(), $stored);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return \array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** @param array<string, mixed> $changes */
    public function update(array $changes): void
    {
        $this->persist(array_merge($this->all(), $changes));
    }

    // ── Sekret adresu kontrolnego ────────────────────────────────────────

    public function secret(): string
    {
        return (string) $this->get('secret', '');
    }

    /** 16 bajtów losowych, czyli 32 znaki hex (128 bitów) — tyle wymaga kontrakt. */
    public function ensureSecret(): string
    {
        $secret = $this->secret();
        if ('' === $secret) {
            $secret = bin2hex(random_bytes(16));
            $this->update(['secret' => $secret]);
        }

        return $secret;
    }

    /** Rotacja: nowy sekret działa od razu, stary jeszcze przez okno przejściowe. */
    public function rotateSecret(): string
    {
        $fresh = bin2hex(random_bytes(16));
        $this->update([
            'secret' => $fresh,
            'prevSecret' => $this->secret(),
            'prevSecretUntil' => $this->now() + self::PREV_WINDOW,
        ]);

        return $fresh;
    }

    /**
     * Klucz z żądania kontra sekret bieżący albo poprzedni w oknie rotacji.
     * Zawsze hash_equals: porównanie znak po znaku zdradza sekret czasem odpowiedzi.
     */
    public function keyIsValid(string $key): bool
    {
        if ('' === $key) {
            return false;
        }
        $secret = $this->secret();
        if ('' !== $secret && hash_equals($secret, $key)) {
            return true;
        }
        $prev = (string) $this->get('prevSecret', '');
        $until = (int) $this->get('prevSecretUntil', 0);

        return '' !== $prev && $this->now() <= $until && hash_equals($prev, $key);
    }

    // ── Parowanie ────────────────────────────────────────────────────────

    /**
     * Nonce parowania MUSI powstać przed wysłaniem żądania do huba: hub w trakcie
     * obsługi register/pair pobiera nasz adres kontrolny i szuka w odpowiedzi
     * dokładnie tej wartości.
     */
    public function makePairingNonce(): string
    {
        $nonce = bin2hex(random_bytes(16));
        $this->update(['pairingNonce' => $nonce, 'pairingNonceUntil' => $this->now() + self::PAIRING_TTL]);

        return $nonce;
    }

    public function pairingNonce(): string
    {
        $until = (int) $this->get('pairingNonceUntil', 0);
        if ($this->now() > $until) {
            return '';
        }

        return (string) $this->get('pairingNonce', '');
    }

    public function clearPairingNonce(): void
    {
        $this->update(['pairingNonce' => '', 'pairingNonceUntil' => 0]);
    }

    /**
     * Znacznik łączenia przez panel. Wychodzimy z nim na obcą stronę i wracamy
     * z kluczem instalacyjnym w adresie, więc jego jedynym zadaniem jest
     * odpowiedzieć na pytanie „czy ten powrót jest odpowiedzią na MOJE kliknięcie".
     * Bez niego ktokolwiek mógłby podrzucić administratorowi link z cudzym
     * kluczem i podpiąć tę stronę pod swoje konto.
     *
     * Alfabet jest celowo wyłącznie alfanumeryczny: znacznik jedzie w adresie
     * i wraca w adresie, a panel przepuszcza tylko [A-Za-z0-9]{12,64}.
     */
    public function makeConnectState(): string
    {
        $state = substr(bin2hex(random_bytes(16)), 0, 24);
        $this->update(['connectState' => $state, 'connectStateUntil' => $this->now() + self::PAIRING_TTL]);

        return $state;
    }

    /**
     * Zużycie znacznika: porównanie w stałym czasie i skasowanie NIEZALEŻNIE od
     * wyniku. Znacznik ma być jednorazowy, więc nieudana próba też go pali:
     * inaczej dałoby się go zgadywać w kółko tym samym powrotem.
     */
    public function consumeConnectState(string $candidate): bool
    {
        $expected = (string) $this->get('connectState', '');
        $until = (int) $this->get('connectStateUntil', 0);
        $this->update(['connectState' => '', 'connectStateUntil' => 0]);

        if ('' === $expected || '' === $candidate || $this->now() > $until) {
            return false;
        }

        return hash_equals($expected, $candidate);
    }

    /** Znacznik ostatniego autoryzowanego odpytania, zapisywany najwyżej raz na minutę (sonda puka co 60 s). */
    public function touchLastPoll(): void
    {
        $last = (int) $this->get('lastPollAt', 0);
        if ($this->now() - $last >= 60) {
            $this->update(['lastPollAt' => $this->now()]);
        }
    }

    // ── Historia zmian wersji i migawka ──────────────────────────────────

    /** @return list<array<string, mixed>> najnowsze pierwsze */
    public function history(): array
    {
        $stored = $this->get('history', []);

        return \is_array($stored) ? array_values($stored) : [];
    }

    /** @param list<array<string, mixed>> $entries */
    public function prependHistory(array $entries): void
    {
        if ([] === $entries) {
            return;
        }
        $this->update(['history' => \array_slice(array_merge($entries, $this->history()), 0, self::HISTORY_MAX)]);
    }

    /** @return array<string, string> */
    public function snapshot(): array
    {
        $stored = $this->get('snapshot', []);

        return \is_array($stored) ? array_map('strval', $stored) : [];
    }

    /** @param array<string, string> $snapshot */
    public function storeSnapshot(array $snapshot): void
    {
        $this->update(['snapshot' => $snapshot]);
    }

    // ── Zaległe aktualizacje (liczone poleceniem CLI) ────────────────────

    /**
     * Liczby zaległych aktualizacji albo null, gdy NIGDY ich nie liczono.
     * Null jest tu istotny: kontrakt zabrania wysyłania zer jako atrapy,
     * bo zero znaczy „sprawdzone, nie ma czego aktualizować".
     *
     * @return array{core: int, plugins: int, themes: int, at: string}|null
     */
    public function updates(): ?array
    {
        $stored = $this->get('updates');
        if (!\is_array($stored) || !isset($stored['at'])) {
            return null;
        }

        return [
            'core' => max(0, (int) ($stored['core'] ?? 0)),
            'plugins' => max(0, (int) ($stored['plugins'] ?? 0)),
            'themes' => 0, // Neos nie ma motywów, więc zero jest tu prawdą, nie atrapą
            'at' => (string) $stored['at'],
        ];
    }

    public function storeUpdates(int $core, int $plugins): void
    {
        $this->update(['updates' => [
            'core' => max(0, $core),
            'plugins' => max(0, $plugins),
            'themes' => 0,
            'at' => gmdate('c', $this->now()),
        ]]);
    }

    // ── Wnętrze ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'secret' => '',
            'prevSecret' => '',
            'prevSecretUntil' => 0,
            'connected' => false,
            'installToken' => '',
            'siteId' => '',
            'plan' => '',
            'panelUrl' => 'https://watch.calmfox.net',
            'pairedAt' => '',
            'lastPollAt' => 0,
            'pairingNonce' => '',
            'pairingNonceUntil' => 0,
            // Limit dyskowy konta w GB podany przez klienta: hosting współdzielony
            // go nie ujawnia, a bez niego nie da się uczciwie liczyć zajętości.
            'diskQuotaGb' => 0.0,
            'updates' => null,
            'history' => [],
            'snapshot' => [],
        ];
    }

    private function now(): int
    {
        return null !== $this->clock ? ($this->clock)() : time();
    }

    /** @param array<string, mixed> $data */
    private function persist(array $data): void
    {
        $directory = \dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Nie można utworzyć katalogu stanu %s', $directory));
        }
        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (false === $json) {
            throw new \RuntimeException('Nie można zserializować stanu pakietu Calmfox Watch.');
        }

        // Zapis do pliku tymczasowego OBOK docelowego (ten sam system plików,
        // więc rename jest atomowy) — czytelnik nigdy nie zobaczy połówki pliku.
        $temporary = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (false === @file_put_contents($temporary, $json, \LOCK_EX)) {
            throw new \RuntimeException(sprintf('Nie można zapisać stanu pakietu w %s', $temporary));
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new \RuntimeException(sprintf('Nie można podmienić pliku stanu %s', $this->path));
        }
        @chmod($this->path, 0600);

        $this->data = $data;
    }
}
