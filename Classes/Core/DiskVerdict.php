<?php

declare(strict_types=1);

namespace Calmfox\Watch\Core;

/**
 * Decyzje checku dyskowego, wyjęte z Flow, żeby dały się przetestować.
 *
 * Uczciwość jest tu ważniejsza niż liczba: na hostingu współdzielonym
 * disk_free_space() podaje CAŁY wolumen serwera (na koncie z kilkoma GB limitu
 * potrafi pokazać „wolne 4,8 TB"), więc takiej liczby nie pokazujemy. Kolejność:
 * limit podany przez klienta, potem wiarygodny odczyt systemowy, na końcu
 * uczciwe „hosting nie pokazuje limitu tego konta".
 */
final class DiskVerdict
{
    /** Zajętość względem limitu podanego przez klienta. */
    public static function fromQuota(int $used, float $limitBytes): string
    {
        if ($limitBytes <= 0) {
            return 'ok';
        }
        $percent = self::percentUsed($used, $limitBytes);
        if ($percent >= 95) {
            return 'fail';
        }

        return $percent >= 85 ? 'warn' : 'ok';
    }

    public static function percentUsed(int $used, float $limitBytes): int
    {
        if ($limitBytes <= 0) {
            return 0;
        }

        return (int) floor($used / $limitBytes * 100);
    }

    /** Odczyt systemowy: liczy się i procent, i wartość bezwzględna (200 MB wolnego to awaria także na dużym dysku). */
    public static function fromFree(float $free, float $total): string
    {
        if ($total <= 0) {
            return 'ok';
        }
        $percent = (int) floor($free / $total * 100);
        if ($percent < 3 || $free < 200 * 1024 * 1024) {
            return 'fail';
        }

        return ($percent < 10 || $free < 1024 * 1024 * 1024) ? 'warn' : 'ok';
    }

    /**
     * Czy odczyt dotyczy całego serwera, a nie konta klienta: ślady hostingu
     * współdzielonego (CloudLinux/CageFS, DirectAdmin, cPanel, Plesk,
     * open_basedir) albo wolumen tak duży, że nie należy do jednego klienta.
     *
     * @param (callable(string): bool)|null $exists podmieniane w testach
     */
    public static function readingIsShared(float $total, string $openBasedir = '', ?callable $exists = null): bool
    {
        $exists ??= static fn (string $path): bool => @file_exists($path);
        foreach (['/usr/local/directadmin', '/usr/local/cpanel', '/opt/psa', '/proc/lve', '/var/cagefs'] as $path) {
            if ($exists($path)) {
                return true;
            }
        }
        if ('' !== trim($openBasedir)) {
            return true;
        }

        return $total >= 1024.0 * 1024 * 1024 * 1024; // 1 TB „wolnego" to nie jest konto współdzielone
    }

    /** Rozmiar po polsku, przecinkiem dziesiętnym i spacją jako separatorem tysięcy. */
    public static function format(float $bytes): string
    {
        $units = ['B', 'kB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;
        while ($bytes >= 1024 && $index < \count($units) - 1) {
            $bytes /= 1024;
            ++$index;
        }
        $decimals = ($index >= 2 && $bytes < 100) ? 1 : 0;

        return number_format($bytes, $decimals, ',', ' ').' '.$units[$index];
    }
}
