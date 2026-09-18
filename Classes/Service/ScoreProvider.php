<?php

declare(strict_types=1);

namespace Calmfox\Watch\Service;

use Calmfox\Watch\Core\ScoreRing;
use Neos\Flow\Annotations as Flow;

/**
 * Ocena kondycji na moduł w panelu: pobrana z huba, zbuforowana, gotowa do rysowania.
 *
 * Odpowiednik `Calmfox_Watch_Score` z wtyczki WordPressa i `ScoreProvider` z pakietów
 * Magento i Sylius — trzyma się tych samych zasad:
 *
 * - `null` znaczy „NIE WIEMY" i sekcja oceny wtedy w ogóle się nie pokazuje. Pusta ramka
 *   z zerem kłamałaby o stanie strony, a zero jest w tej skali najgorszym wynikiem.
 * - Cisza huba NIE JEST oceną: zapamiętujemy ją na krótko (5 minut), żeby nie pukać przy
 *   każdym otwarciu modułu, ale nie na godzinę — hub wróci wcześniej i moduł ma to zobaczyć.
 * - Ocena przelicza się w hubie raz na dobę, więc godzina w pamięci podręcznej nie postarza
 *   jej zauważalnie, a panel nie czeka na sieć.
 */
#[Flow\Scope('singleton')]
class ScoreProvider
{
    /** Udana odpowiedź: ocena i tak przelicza się raz na dobę. */
    private const TTL = 3600;

    /** Hub milczy albo odmawia: krótka cisza, żeby nie pukać przy każdym otwarciu modułu. */
    private const TTL_SILENCE = 300;

    #[Flow\Inject]
    protected HubClient $hubClient;

    #[Flow\Inject]
    protected StateProvider $stateProvider;

    #[Flow\Inject]
    protected Cache $cache;

    /**
     * Ocena gotowa dla szablonu albo `null`, gdy jej nie ma.
     *
     * Zwracana tablica to odpowiedź huba wzbogacona o `ring` — łuki policzone przez
     * ScoreRing, żeby szablon nie liczył geometrii (Fluid i tak by nie umiał).
     *
     * @param bool $fresh pominąć pamięć podręczną (po ręcznym przeliczeniu sprawdzeń)
     *
     * @return array<string, mixed>|null
     */
    public function score(bool $fresh = false): ?array
    {
        if (!$this->stateProvider->state()->get('connected')) {
            return null;
        }

        if (!$fresh) {
            $cached = $this->cache->get(Cache::SCORE);
            if (\is_array($cached)) {
                return empty($cached['available']) ? null : $cached;
            }
        }

        $result = $this->hubClient->score();
        if (!$result['ok']) {
            $this->cache->set(Cache::SCORE, ['available' => false], self::TTL_SILENCE);

            return null;
        }

        $data = $result['data'];

        // `available: false` przychodzi na progu Free (hub nie wydaje wtedy ŻADNEJ liczby
        // o stanie strony) i wtedy, gdy oceny jeszcze nie policzono. Moduł robi w obu
        // wypadkach to samo — pokazuje pusty pierścień — ale odpowiedź buforujemy na pełną
        // godzinę, bo to jest odpowiedź huba, a nie jego cisza.
        if (empty($data['available'])) {
            $this->cache->set(Cache::SCORE, ['available' => false], self::TTL);

            return null;
        }

        $data['ring'] = ScoreRing::segments(\is_array($data['areas'] ?? null) ? $data['areas'] : []);
        $this->cache->set(Cache::SCORE, $data, self::TTL);

        return $data;
    }

    /** Po rozłączeniu i po wymianie klucza stara ocena nie ma prawa zostać na ekranie. */
    public function forget(): void
    {
        $this->cache->remove(Cache::SCORE);
    }
}
