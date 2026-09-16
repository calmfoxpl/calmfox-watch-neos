<?php

declare(strict_types=1);

namespace Calmfox\Watch\Service;

use Calmfox\Watch\Core\PayloadBuilder;
use Calmfox\Watch\Health\HealthChecks;
use Calmfox\Watch\Security\SecurityChecks;
use Neos\Flow\Annotations as Flow;

/**
 * Budowa payloadu obu sekcji razem z pamięcią podręczną. Czasy życia są
 * z kontraktu: zdrowie usług 60 sekund (tyle, ile wynosi odstęp między
 * odpytaniami sondy), higiena bezpieczeństwa 10 minut (liczy się drożej,
 * a hub pyta o nią raz na dobę).
 *
 * Echo nonce'a parowania dokładamy POZA pamięcią podręczną, przy każdej
 * odpowiedzi osobno. Inaczej challenge huba mógłby dostać payload zbudowany
 * sekundę przed powstaniem nonce'a i parowanie nie miałoby prawa się udać.
 */
#[Flow\Scope('singleton')]
class PayloadProvider
{
    #[Flow\Inject]
    protected Cache $cache;

    #[Flow\Inject]
    protected HealthChecks $healthChecks;

    #[Flow\Inject]
    protected SecurityChecks $securityChecks;

    #[Flow\Inject]
    protected StateProvider $stateProvider;

    #[Flow\Inject]
    protected PackageVersions $packageVersions;

    #[Flow\Inject]
    protected AdminAccounts $adminAccounts;

    #[Flow\Inject]
    protected VersionHistory $versionHistory;

    /** @return array<string, mixed> */
    public function health(bool $fresh = false): array
    {
        if (!$fresh) {
            $cached = $this->cache->get(Cache::HEALTH);
            if (\is_array($cached)) {
                return $cached;
            }
        }

        $state = $this->stateProvider->state();
        $updates = $state->updates();
        $survey = $this->survey();

        $payload = PayloadBuilder::health(
            $this->healthChecks->run(),
            PayloadBuilder::site($this->packageVersions->platformVersion(), \PHP_VERSION, null === $updates ? null : [
                'core' => $updates['core'],
                'plugins' => $updates['plugins'],
                'themes' => 0,
            ]),
            // Dwa zestawy sygnałów w jednym polu `signals`: konta z pełnym dostępem
            // i skład zainstalowanych rozszerzeń. Hub porównuje je osobno, ale
            // odczyt jest jeden, bo to jedno odpytanie. Gdy żadnego z nich nie da
            // się policzyć, pole nie jedzie w ogóle, zamiast wieźć same zera.
            self::merge(
                null === $survey ? [] : PayloadBuilder::signals($survey['identifiers'], $survey['newestAt'], $state->secret()),
                PayloadBuilder::packageSignals($this->packageVersions->extensionNames(), $state->secret()),
            ),
        );
        $this->cache->set(Cache::HEALTH, $payload, 60);

        return $payload;
    }

    /**
     * @param array<string, mixed> $admins
     * @param array<string, mixed> $packages
     *
     * @return array<string, mixed>|null
     */
    private static function merge(array $admins, array $packages): ?array
    {
        $signals = array_merge($admins, $packages);

        return [] === $signals ? null : $signals;
    }

    /** @return array<string, mixed> */
    public function security(bool $fresh = false): array
    {
        if (!$fresh) {
            $cached = $this->cache->get(Cache::SECURITY);
            if (\is_array($cached)) {
                return $cached;
            }
        }

        // Uzgodnienie migawki wersji leży tutaj, bo to jedyne miejsce wołane
        // regularnie i nie częściej niż raz na dziesięć minut. Historia zmian
        // powstaje więc bez żadnego dodatkowego zadania cyklicznego.
        try {
            $this->versionHistory->reconcile();
        } catch (\Throwable) {
            // Brak zapisu historii nie może wywrócić całej sekcji.
        }

        $payload = PayloadBuilder::security($this->securityChecks->run(), $this->versionHistory->all());
        $this->cache->set(Cache::SECURITY, $payload, 600);

        return $payload;
    }

    /**
     * Echo nonce'a parowania. Czytane na żywo, celowo poza pamięcią podręczną.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function withPairing(array $payload): array
    {
        $nonce = $this->stateProvider->state()->pairingNonce();
        if ('' !== $nonce) {
            $payload['pairing'] = $nonce;
        }

        return $payload;
    }

    public function flush(): void
    {
        $this->cache->flush();
    }

    /** @return array{identifiers: list<string>, newestAt: ?string}|null null = nie udało się odczytać kont */
    private function survey(): ?array
    {
        try {
            $survey = $this->adminAccounts->survey();

            return ['identifiers' => $survey['identifiers'], 'newestAt' => $survey['newestAt']];
        } catch (\Throwable) {
            // Baza leży: sygnały o kontach są wtedy NIEZNANE i tak je podajemy
            // (pomijając pole), a reszta payloadu, a zwłaszcza check bazy, który
            // właśnie zapalił się na czerwono, musi dojść do huba.
            return null;
        }
    }
}
