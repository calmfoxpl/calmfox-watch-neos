<?php

declare(strict_types=1);

namespace Calmfox\Watch\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\AccountRepository;

/**
 * Konta z pełnymi uprawnieniami czytane przez repozytoria Flow Security, a nie
 * zapytaniem SQL. Powód jest merytoryczny, nie estetyczny: rola administratora
 * bywa DZIEDZICZONA przez rolę własną klienta (na przykład „Klient:Zarzad"
 * z parentRoles). Zapytanie po nazwie roli w tabeli przegapiłoby takie konto,
 * czyli dokładnie to, na czym zależy atakującemu.
 *
 * Loginy zostają na miejscu. Na zewnątrz idzie liczba, odcisk zbioru i data
 * najnowszego konta.
 */
#[Flow\Scope('singleton')]
class AdminAccounts
{
    public const ADMINISTRATOR_ROLE = 'Neos.Neos:Administrator';

    /** Powyżej tej liczby przestajemy zliczać: przy tysiącach kont odpowiedź endpointu byłaby droższa niż sam monitoring. */
    private const MAX_ACCOUNTS = 500;

    #[Flow\Inject]
    protected AccountRepository $accountRepository;

    /**
     * @return array{identifiers: list<string>, newestAt: ?string, defaultLogin: bool, truncated: bool}
     */
    public function survey(): array
    {
        $identifiers = [];
        $newest = null;
        $defaultLogin = false;
        $seen = 0;
        $truncated = false;

        foreach ($this->accountRepository->findAll() as $account) {
            if (!$account instanceof Account) {
                continue;
            }
            if (++$seen > self::MAX_ACCOUNTS) {
                $truncated = true;
                break;
            }
            if (!$this->isAdministrator($account)) {
                continue;
            }
            $identifiers[] = $account->getAccountIdentifier().'@'.$account->getAuthenticationProviderName();
            if ('admin' === mb_strtolower($account->getAccountIdentifier())) {
                $defaultLogin = true;
            }
            $created = $account->getCreationDate();
            if ($created instanceof \DateTimeInterface && (null === $newest || $created > $newest)) {
                $newest = $created;
            }
        }

        return [
            'identifiers' => $identifiers,
            'newestAt' => $newest?->setTimezone(new \DateTimeZone('UTC'))->format('c'),
            'defaultLogin' => $defaultLogin,
            'truncated' => $truncated,
        ];
    }

    private function isAdministrator(Account $account): bool
    {
        try {
            foreach ($account->getRoles() as $role) {
                if (self::ADMINISTRATOR_ROLE === $role->getIdentifier()) {
                    return true;
                }
                // Rola własna klienta może dziedziczyć administratora: liczy się
                // efektywne uprawnienie, nie nazwa wpisana w bazie.
                foreach ($role->getAllParentRoles() as $parent) {
                    if (self::ADMINISTRATOR_ROLE === $parent->getIdentifier()) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
            // Konto wskazuje rolę, której już nie ma w Policy.yaml. To samo w sobie
            // jest usterką konfiguracji, ale nie może wywrócić całej sekcji.
            return false;
        }

        return false;
    }
}
