<?php

declare(strict_types=1);

namespace MyInvoice\Service\Currency;

use MyInvoice\Service\Import\PurchaseInvoiceCnbApplier;

/**
 * Doplní chybějící ČNB kurzy pro konkrétní doklady (issue #238).
 *
 * Volá se z daňových akcí při STAŽENÍ výkazu (vědomá zápisová akce) — buildery
 * jen detekují doklady bez kurzu (VatLedgerService::missingExchangeRateRows) a
 * vrátí je v `result['missing_rates']`; tady je doplníme oficiálním kurzem ČNB
 * k DUZP. Idempotentní: appliery plní jen NULL kurz a nikdy nepřepíší ručně
 * zadaný. Náhled výkazu tuto službu nevolá (nezapisuje).
 */
final class MissingExchangeRateFiller
{
    public function __construct(
        private readonly ExchangeRateApplier $issued,
        private readonly PurchaseInvoiceCnbApplier $purchase,
    ) {}

    /**
     * @param list<array{invoice_id:int, source:string, currency:string, tax_date:?string, issue_date:?string, doc?:string}> $rows
     * @return int počet dokladů, u nichž se kurz podařilo doplnit
     */
    public function fill(int $supplierId, array $rows): int
    {
        $filled = 0;
        foreach ($rows as $r) {
            $invoiceId = (int) ($r['invoice_id'] ?? 0);
            if ($invoiceId === 0) {
                continue;
            }
            if (($r['source'] ?? '') === 'sale') {
                // ensureRate plní jen non-CZK vystavenou fakturu s NULL kurzem (DUZP z ČNB).
                $this->issued->ensureRate($invoiceId);
                $filled++;
            } else {
                $ok = $this->purchase->applyIfMissing(
                    $invoiceId,
                    $supplierId,
                    (string) ($r['currency'] ?? ''),
                    (string) ($r['tax_date'] ?? $r['issue_date'] ?? ''),
                    null,
                );
                if ($ok) {
                    $filled++;
                }
            }
        }
        return $filled;
    }
}
