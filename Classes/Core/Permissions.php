<?php

declare(strict_types=1);

namespace Calmfox\Watch\Core;

/**
 * Odczyt praw dostępu z jednym założeniem: 644 to norma na hostingach
 * współdzielonych i nie ma sensu straszyć nią klienta. Alarmujemy dopiero
 * wtedy, gdy plik albo katalog jest ZAPISYWALNY dla grupy lub świata, bo
 * to znaczy, że cudzy proces na serwerze może go podmienić.
 */
final class Permissions
{
    public static function worldWritable(int $perms): bool
    {
        return 0 !== ($perms & 0002);
    }

    public static function groupWritable(int $perms): bool
    {
        return 0 !== ($perms & 0020);
    }

    public static function worldReadable(int $perms): bool
    {
        return 0 !== ($perms & 0004);
    }

    public static function groupReadable(int $perms): bool
    {
        return 0 !== ($perms & 0040);
    }

    /** Plik z hasłem do bazy albo z kluczem: zapis dla świata to awaria, zapis dla grupy to ostrzeżenie. */
    public static function secretFileVerdict(int $perms): string
    {
        if (self::worldWritable($perms) || self::worldReadable($perms)) {
            return 'fail';
        }

        return (self::groupWritable($perms) || self::groupReadable($perms)) ? 'warn' : 'ok';
    }

    public static function octal(int $perms): string
    {
        return str_pad(decoct($perms & 0777), 3, '0', \STR_PAD_LEFT);
    }
}
