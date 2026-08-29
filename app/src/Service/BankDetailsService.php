<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SettingsRepository;

/**
 * The club's bank details for accepting payment by transfer (virement) —
 * admin-editable (see AdminController::showBankDetails()/saveBankDetails())
 * instead of the old static secrets.php 'club.bank' array, so a treasurer
 * can update them without a code deploy.
 *
 * Falls back to the legacy secrets.php values per field when a DB value is
 * empty, so nothing goes blank right after this feature ships (the DB
 * starts with no bank_* settings until an admin visits the new screen once).
 */
final class BankDetailsService
{
    private const array FIELDS = ['name', 'code_banque', 'account_number', 'bic', 'iban'];

    /** @param array $legacyBank settings.php's 'club.bank' array (from secrets.php), used only as a fallback */
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly array $legacyBank = [],
    ) {
    }

    /** @return array{name:string, code_banque:string, account_number:string, bic:string, iban:string} */
    public function current(): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $this->settings->get('bank_' . $field);
            $out[$field] = $value !== null && $value !== '' ? $value : (string) ($this->legacyBank[$field] ?? '');
        }
        return $out;
    }

    /** @param array<string, string> $values */
    public function save(array $values): void
    {
        foreach (self::FIELDS as $field) {
            $this->settings->set('bank_' . $field, trim((string) ($values[$field] ?? '')));
        }
    }
}
