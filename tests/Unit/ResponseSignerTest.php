<?php

declare(strict_types=1);

namespace Calmfox\Watch\Tests\Unit;

use Calmfox\Watch\Core\ResponseSigner;
use PHPUnit\Framework\TestCase;

/**
 * Podpis odpowiedzi musi zgadzać się co do bajta z tym, co liczy hub
 * (App\Plugin\WpHealthClient::verifyProof). Rozjazd tutaj nie oznacza
 * „brak podpisu", tylko „podpis nieważny", a to hub traktuje jak podmienioną
 * treść i otwiera incydent. Dlatego test ma zaszyty stały wektor: zmiana
 * kolejności składników albo separatora zapali się natychmiast.
 */
final class ResponseSignerTest extends TestCase
{
    private const NONCE = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const GENERATED_AT = '2026-08-19T09:00:00+00:00';
    private const BODY = '{"schema":1,"status":"ok"}';
    private const SECRET = '0123456789abcdef0123456789abcdef';

    public function testProofMatchesFixedVector(): void
    {
        self::assertSame(
            '414a223c297ecac0f31e11498ea8b9744ff02ed479dddfb11dad578b791429d4',
            ResponseSigner::proof(self::NONCE, self::GENERATED_AT, self::BODY, self::SECRET)
        );
    }

    /** Ta sama formuła, przepisana wprost z kodu huba. */
    public function testProofMatchesHubFormula(): void
    {
        $expected = hash_hmac('sha256', self::NONCE."\n".self::GENERATED_AT."\n".self::BODY, self::SECRET);

        self::assertTrue(hash_equals($expected, ResponseSigner::proof(self::NONCE, self::GENERATED_AT, self::BODY, self::SECRET)));
    }

    public function testChangedBodyBreaksTheProof(): void
    {
        $original = ResponseSigner::proof(self::NONCE, self::GENERATED_AT, self::BODY, self::SECRET);
        $tampered = ResponseSigner::proof(self::NONCE, self::GENERATED_AT, '{"schema":1,"status":"fail"}', self::SECRET);

        self::assertNotSame($original, $tampered);
    }

    /** Znacznik jednorazowy jest sensem tego podpisu: stara odpowiedź nie może przejść dla nowego odpytania. */
    public function testChangedNonceBreaksTheProof(): void
    {
        $original = ResponseSigner::proof(self::NONCE, self::GENERATED_AT, self::BODY, self::SECRET);
        $replayed = ResponseSigner::proof('ffffffffffffffffffffffffffffffff', self::GENERATED_AT, self::BODY, self::SECRET);

        self::assertNotSame($original, $replayed);
    }

    public function testChangedSecretBreaksTheProof(): void
    {
        $original = ResponseSigner::proof(self::NONCE, self::GENERATED_AT, self::BODY, self::SECRET);
        $other = ResponseSigner::proof(self::NONCE, self::GENERATED_AT, self::BODY, 'ffffffffffffffffffffffffffffffff');

        self::assertNotSame($original, $other);
    }

    /** Hub odrzuca odpowiedzi starsze niż 300 s, więc czas musi być w UTC i w formacie ISO 8601. */
    public function testGeneratedAtIsIsoUtc(): void
    {
        $value = ResponseSigner::generatedAt(1_755_594_000);

        self::assertSame('2025-08-19T09:00:00+00:00', $value);
        self::assertInstanceOf(\DateTimeImmutable::class, new \DateTimeImmutable($value));
    }
}
