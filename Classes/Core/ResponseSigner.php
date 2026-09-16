<?php

declare(strict_types=1);

namespace Calmfox\Watch\Core;

/**
 * Dowód świeżości odpowiedzi. Hub przysyła jednorazowy znacznik, my liczymy
 * HMAC nad NIM, czasem wygenerowania i treścią — kluczem instalacji.
 *
 * Podpis idzie nagłówkiem, nie w treści, i dlatego kolejność jest sztywna:
 * najpierw budujemy bajty odpowiedzi, potem je podpisujemy. Gdyby podpis
 * siedział w JSON-ie, trzeba by zgadywać, jak framework poskłada tablicę,
 * a hub liczy HMAC nad dokładnie tym, co przyszło z łącza.
 *
 * Granica ochrony, mówimy o niej wprost: kto ma sekret z serwera, może
 * podpisać kłamstwo. To odcina tanie ataki (podstawiony plik statyczny,
 * odpowiedź z pamięci podręcznej, powtórka sprzed przejęcia strony),
 * a nie zastępuje odzyskiwania serwera.
 */
final class ResponseSigner
{
    public const HEADER_PROOF = 'X-Calmfox-Proof';
    public const HEADER_GENERATED_AT = 'X-Calmfox-Generated-At';

    public static function proof(string $nonce, string $generatedAt, string $body, string $secret): string
    {
        return hash_hmac('sha256', $nonce."\n".$generatedAt."\n".$body, $secret);
    }

    /** Ten sam format, którego hub oczekuje w nagłówku (ISO 8601 w UTC). */
    public static function generatedAt(?int $timestamp = null): string
    {
        return gmdate('c', $timestamp ?? time());
    }
}
