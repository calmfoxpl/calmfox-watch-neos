<?php

declare(strict_types=1);

namespace Calmfox\Watch\Tests\Unit;

use Calmfox\Watch\Core\InstallationState;
use PHPUnit\Framework\TestCase;

final class InstallationStateTest extends TestCase
{
    private string $directory;

    private int $now = 1_700_000_000;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/calmfox-watch-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    private function state(): InstallationState
    {
        return new InstallationState($this->directory.'/state.json', fn (): int => $this->now);
    }

    public function testSecretIsOneHundredTwentyEightBitsInHex(): void
    {
        $secret = $this->state()->ensureSecret();

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $secret);
    }

    public function testSecretIsStableAcrossInstances(): void
    {
        $secret = $this->state()->ensureSecret();

        self::assertSame($secret, $this->state()->ensureSecret());
    }

    public function testStateFileIsReadableOnlyByOwner(): void
    {
        $state = $this->state();
        $state->ensureSecret();

        clearstatcache();
        self::assertSame(0600, fileperms($state->path()) & 0777);
    }

    public function testValidKeyIsAccepted(): void
    {
        $state = $this->state();
        $secret = $state->ensureSecret();

        self::assertTrue($state->keyIsValid($secret));
    }

    public function testWrongKeyIsRejected(): void
    {
        $state = $this->state();
        $state->ensureSecret();

        self::assertFalse($state->keyIsValid('nie-ten-klucz'));
    }

    public function testEmptyKeyIsRejected(): void
    {
        $state = $this->state();
        $state->ensureSecret();

        self::assertFalse($state->keyIsValid(''));
    }

    /** Bez sekretu (świeża instalacja) nie wpuszczamy nikogo, także z pustym kluczem. */
    public function testKeyIsRejectedBeforeSecretExists(): void
    {
        self::assertFalse($this->state()->keyIsValid('cokolwiek'));
    }

    public function testRotationActivatesNewSecretImmediately(): void
    {
        $state = $this->state();
        $state->ensureSecret();

        $fresh = $state->rotateSecret();

        self::assertTrue($state->keyIsValid($fresh));
    }

    public function testPreviousSecretStaysValidInsideRotationWindow(): void
    {
        $state = $this->state();
        $old = $state->ensureSecret();
        $state->rotateSecret();

        $this->now += InstallationState::PREV_WINDOW - 1;

        self::assertTrue($state->keyIsValid($old), 'nieudane przepięcie w hubie nie może zrywać monitoringu przez kwadrans');
    }

    public function testPreviousSecretExpiresAfterRotationWindow(): void
    {
        $state = $this->state();
        $old = $state->ensureSecret();
        $new = $state->rotateSecret();

        $this->now += InstallationState::PREV_WINDOW + 1;

        self::assertFalse($state->keyIsValid($old));
        self::assertTrue($state->keyIsValid($new));
    }

    public function testSecondRotationDropsTheOldestSecret(): void
    {
        $state = $this->state();
        $first = $state->ensureSecret();
        $state->rotateSecret();
        $state->rotateSecret();

        self::assertFalse($state->keyIsValid($first), 'w oknie honorujemy tylko JEDEN poprzedni sekret');
    }

    public function testPairingNonceMatchesHubFormat(): void
    {
        $nonce = $this->state()->makePairingNonce();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{16,64}$/', $nonce);
    }

    public function testPairingNonceExpires(): void
    {
        $state = $this->state();
        $nonce = $state->makePairingNonce();
        self::assertSame($nonce, $state->pairingNonce());

        $this->now += InstallationState::PAIRING_TTL + 1;

        self::assertSame('', $state->pairingNonce());
    }

    public function testPairingNonceCanBeCleared(): void
    {
        $state = $this->state();
        $state->makePairingNonce();
        $state->clearPairingNonce();

        self::assertSame('', $state->pairingNonce());
    }

    public function testUpdatesAreUnknownUntilCounted(): void
    {
        self::assertNull($this->state()->updates());
    }

    public function testStoredUpdatesCarryTheirTimestamp(): void
    {
        $state = $this->state();
        $state->storeUpdates(1, 4);

        $updates = $state->updates();

        self::assertNotNull($updates);
        self::assertSame(1, $updates['core']);
        self::assertSame(4, $updates['plugins']);
        self::assertSame(0, $updates['themes']);
        self::assertSame(gmdate('c', $this->now), $updates['at']);
    }

    public function testHistoryKeepsNewestFirstAndIsCapped(): void
    {
        $state = $this->state();
        $state->prependHistory([['name' => 'stary']]);
        $state->prependHistory([['name' => 'nowy']]);

        self::assertSame('nowy', $state->history()[0]['name']);

        $bulk = [];
        for ($i = 0; $i < InstallationState::HISTORY_MAX + 50; ++$i) {
            $bulk[] = ['name' => 'pakiet'.$i];
        }
        $state->prependHistory($bulk);

        self::assertCount(InstallationState::HISTORY_MAX, $state->history());
    }

    public function testCorruptedFileFallsBackToDefaults(): void
    {
        @mkdir($this->directory, 0700, true);
        file_put_contents($this->directory.'/state.json', '{to nie jest JSON');

        $state = $this->state();

        self::assertSame('', $state->secret());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $state->ensureSecret());
    }

    public function testNoTemporaryFilesAreLeftBehind(): void
    {
        $state = $this->state();
        $state->ensureSecret();
        $state->rotateSecret();
        $state->makePairingNonce();

        $leftovers = glob($this->directory.'/*.tmp') ?: [];

        self::assertSame([], $leftovers, 'zapis atomowy sprząta po sobie plik tymczasowy');
    }

    /**
     * Znacznik łączenia przez panel: wychodzimy z nim na obcą stronę i wracamy
     * z jawnym kluczem instalacyjnym w adresie, więc to on rozstrzyga, czy powrót
     * jest odpowiedzią na NASZE kliknięcie. Musi być jednorazowy w obie strony:
     * po udanym zużyciu i po nieudanej próbie, inaczej dałoby się go zgadywać
     * w kółko tym samym powrotem.
     */
    public function testZnacznikLaczeniaDzialaRazIWygasa(): void
    {
        $state = $this->state();

        $token = $state->makeConnectState();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{12,64}$/', $token, 'panel przepuszcza tylko alfanumeryczne znaczniki');
        self::assertTrue($state->consumeConnectState($token));
        self::assertFalse($state->consumeConnectState($token), 'znacznik ma być jednorazowy');
    }

    public function testNieudanaProbaTezPaliZnacznik(): void
    {
        $state = $this->state();

        $token = $state->makeConnectState();
        self::assertFalse($state->consumeConnectState('nieprawidlowy'));
        self::assertFalse($state->consumeConnectState($token), 'po nieudanej próbie właściwy znacznik też ma już nie działać');
    }

    public function testZnacznikLaczeniaWygasa(): void
    {
        $state = $this->state();
        $token = $state->makeConnectState();

        $this->now += InstallationState::PAIRING_TTL + 1;

        self::assertFalse($state->consumeConnectState($token));
    }

    public function testPustyZnacznikNigdyNiePrzechodzi(): void
    {
        self::assertFalse($this->state()->consumeConnectState(''), 'brak znacznika w powrocie to nie jest zgoda na parowanie');
    }
}
