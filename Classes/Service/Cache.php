<?php

declare(strict_types=1);

namespace Calmfox\Watch\Service;

use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations as Flow;

/**
 * Pamięć podręczna pakietu. Osobna klasa, bo każdy dostęp musi być odporny na
 * awarię samej pamięci podręcznej: gdy padnie Redis albo katalog Data/Temporary
 * przestanie być zapisywalny, endpoint stanu ma dalej odpowiadać (i właśnie
 * wtedy powiedzieć, że coś jest nie tak), a nie wywalić się na zapisie klucza.
 */
#[Flow\Scope('singleton')]
class Cache
{
    public const HEALTH = 'health';
    public const SECURITY = 'security';
    public const SMTP = 'smtp';
    public const ELASTICSEARCH = 'elasticsearch';
    public const INSTALL_SIZE = 'installSize';
    public const SCORE = 'score';

    protected VariableFrontend $cache;

    public function injectCache(VariableFrontend $cache): void
    {
        $this->cache = $cache;
    }

    public function get(string $key): mixed
    {
        try {
            $value = $this->cache->get($key);

            return false === $value ? null : $value;
        } catch (\Throwable) {
            return null;
        }
    }

    public function set(string $key, mixed $value, int $lifetime): void
    {
        try {
            $this->cache->set($key, $value, [], $lifetime);
        } catch (\Throwable) {
            // Brak zapisu oznacza tylko tyle, że następne odpytanie policzy
            // check od nowa. To gorsza wydajność, nie utrata monitoringu.
        }
    }

    /** Skasowanie jednego wpisu: stara ocena nie ma prawa zostać po rozłączeniu. */
    public function remove(string $key): void
    {
        try {
            $this->cache->remove($key);
        } catch (\Throwable) {
            // Tak samo jak przy zapisie: brak skasowania to gorszy podgląd,
            // nie utrata monitoringu — a wpis i tak wygaśnie sam.
        }
    }

    public function flush(): void
    {
        try {
            $this->cache->flush();
        } catch (\Throwable) {
        }
    }
}
