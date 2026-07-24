<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Builder XML pro DPFO (Daň z příjmů FO, formulář DPFDP5) a DPPO (PO, formulář DPPDP9).
 *
 * **Status: MVP foundation, vyžaduje účetní data (odpisy, mzdy, vedlejší výdaje), která
 * v MyInvoice.cz nemáme. Generuje jen XML kostru s identifikací poplatníka a souhrnem
 * tržeb/nákladů z faktur za daný rok. Reálné podání vyžaduje doplnění v účetnickém
 * software nebo ve spolupráci s daňovým poradcem.**
 *
 * ⚠️ Vygenerované XML je výslovně **incomplete** — slouží jen jako startovací bod
 * pro účetní. Před odesláním na EPO MUSÍ být doplněno.
 *
 * Verze formulářů:
 *   - DPFO: DPFDP5 (FO — fyzická osoba, OSVČ)
 *   - DPPO: DPPDP9 (PO — právnická osoba, s.r.o./a.s.)
 *
 * Algoritmus:
 *   1. Sum revenue za daný rok (invoices, status NOT IN draft/cancelled, !proforma)
 *   2. Sum costs (purchase_invoices)
 *   3. Profit = revenue - costs (orientační, NE účetní)
 *   4. Naplnit jen base XML (identifikace + souhrn) — řádky výkazu nechat blank
 */
final class IncomeTaxBuilder
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param string $taxpayerType 'fo' (DPFO) nebo 'po' (DPPO)
     * @return array{xml: string, summary: array<string,mixed>, warnings: list<string>}
     */
    public function build(int $supplierId, int $year, string $taxpayerType): array
    {
        if (!in_array($taxpayerType, ['fo', 'po'], true)) {
            throw new \InvalidArgumentException("taxpayerType musí být 'fo' nebo 'po'.");
        }

        $supplier = $this->loadSupplier($supplierId);
        $warnings = $this->validateSupplier($supplier, $taxpayerType);
        $warnings[] = '⚠ Tento výkaz je POUZE foundation — chybí účetní data (odpisy, ' .
                      'mzdy, vedlejší výdaje). Doplňte ve spolupráci s účetní/poradcem.';

        $isVatPayer = (bool) ($supplier['is_vat_payer'] ?? false);
        $totals = $this->loadYearTotals($supplierId, $year, $isVatPayer);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $pisemnost = $dom->createElement('Pisemnost');
        $pisemnost->setAttribute('nazevSW', 'MyInvoice.cz');
        $pisemnost->setAttribute('verzeSW', (string) ($this->loadAppVersion() ?? '0'));
        $dom->appendChild($pisemnost);

        if ($taxpayerType === 'fo') {
            $root = $dom->createElement('DPFDP5');
            $root->setAttribute('verzePis', '05.01');
        } else {
            $root = $dom->createElement('DPPDP9');
            $root->setAttribute('verzePis', '09.01');
        }
        $pisemnost->appendChild($root);

        // VetaD — identifikace
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', 'DPF');  // nebo DPP pro PO
        $vetaD->setAttribute('rok', (string) $year);
        if (!empty($supplier['financial_office_code'])) {
            $vetaD->setAttribute('c_ufo', (string) $supplier['financial_office_code']);
        }
        $vetaD->setAttribute('typ_platce', $taxpayerType === 'po' ? 'P' : 'F');
        $root->appendChild($vetaD);

        // VetaP — poplatník
        $vetaP = $dom->createElement('VetaP');
        if (!empty($supplier['dic'])) {
            $vetaP->setAttribute('dic', (string) $supplier['dic']);
        }
        if (!empty($supplier['ic'])) {
            $vetaP->setAttribute('ic', (string) $supplier['ic']);
        }
        if ($taxpayerType === 'po') {
            $vetaP->setAttribute('nazev_pol', (string) $supplier['company_name']);
        } else {
            $parts = explode(' ', trim((string) $supplier['company_name']), 2);
            $vetaP->setAttribute('jmeno', $parts[0] ?? '');
            $vetaP->setAttribute('prijmeni', $parts[1] ?? $parts[0] ?? '');
        }
        $vetaP->setAttribute('ulice', (string) ($supplier['street'] ?? ''));
        $vetaP->setAttribute('naz_obce', (string) ($supplier['city'] ?? ''));
        $vetaP->setAttribute('psc', (string) ($supplier['zip'] ?? ''));
        $root->appendChild($vetaP);

        // VetaInfo — placeholder s revenue/costs z faktur (NE účetně přesné)
        $vetaInfo = $dom->createElement('VetaInfo');
        $vetaInfo->setAttribute('prijmy_invoicing', $this->formatAmount($totals['revenue']));
        $vetaInfo->setAttribute('vydaje_invoicing', $this->formatAmount($totals['costs']));
        $vetaInfo->setAttribute('hosp_vysledek_orientacni', $this->formatAmount($totals['revenue'] - $totals['costs']));
        $vetaInfo->setAttribute('upozorneni', 'Toto jsou orientacni cisla z invoicing systemu, ne ucetni vykaz.');
        $root->appendChild($vetaInfo);

        return [
            'xml'     => $dom->saveXML() ?: '',
            'summary' => [
                'year'              => $year,
                'taxpayer_type'     => $taxpayerType,
                'revenue_orientacni' => round($totals['revenue'], 2),
                'exempt_revenue_orientacni' => round($totals['exempt_revenue'] ?? 0, 2),
                'costs_orientacni'   => round($totals['costs'], 2),
                'profit_orientacni'  => round($totals['revenue'] - $totals['costs'], 2),
                'submission_deadline' => sprintf('%04d-04-01', $year + 1),  // do 1.4. následujícího roku
                'currency'          => 'CZK',
                'is_vat_payer'      => $isVatPayer,  // true → částky bez DPH, false → vč. DPH
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Tržby a náklady za rok pro orientační hospodářský výsledek.
     *
     * Pro plátce DPH (is_vat_payer) je DPH průběžná položka vypořádaná zvlášť
     * přiznáním k DPH — do základu daně z příjmů nevstupuje, proto bereme částky
     * bez DPH (`total_without_vat`). Pro neplátce je DPH součástí ceny a odečíst
     * ji nelze, proto bereme částky včetně DPH (`total_with_vat`). Stejný vzor jako
     * StatsRecomputer / migrace 0023_revenue_vat_aware.
     *
     * @return array{revenue: float, costs: float, exempt_revenue: float}
     */
    private function loadYearTotals(int $supplierId, int $year, bool $isVatPayer): array
    {
        $start = sprintf('%04d-01-01', $year);
        $end   = sprintf('%04d-12-31', $year);

        $amount = $isVatPayer ? 'total_without_vat' : 'total_with_vat';

        // Revenue z vydaných (CZK only — pro DPFO/DPPO výkazy)
        $stmt = $this->db->pdo()->prepare(
            "SELECT SUM(COALESCE(i.{$amount}, 0)) AS total
               FROM invoices i
          LEFT JOIN currencies c ON c.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status NOT IN ('draft', 'cancelled')
                AND i.invoice_type != 'proforma'
                -- Doklady mimo základ daně z příjmů (§4 osvobození / přefakturace) se
                -- do DPFO/DPPO příjmů nezahrnují. Pozn.: DPH/KH/tržby nedotčeny.
                AND COALESCE(i.income_tax_exempt, 0) = 0
                AND COALESCE(c.code, 'CZK') = 'CZK'
                AND COALESCE(i.tax_date, i.issue_date) BETWEEN ? AND ?"
        );
        $stmt->execute([$supplierId, $start, $end]);
        $revenue = (float) ($stmt->fetchColumn() ?: 0);

        // Z toho vyloučeno ze základu daně z příjmů (§4 osvobození / přefakturace) —
        // pro transparentní řádek „z toho vyloučeno" ve výkazu. Do revenue NEvstupuje.
        $stmt = $this->db->pdo()->prepare(
            "SELECT SUM(COALESCE(i.{$amount}, 0)) AS total
               FROM invoices i
          LEFT JOIN currencies c ON c.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status NOT IN ('draft', 'cancelled')
                AND i.invoice_type != 'proforma'
                AND COALESCE(i.income_tax_exempt, 0) = 1
                AND COALESCE(c.code, 'CZK') = 'CZK'
                AND COALESCE(i.tax_date, i.issue_date) BETWEEN ? AND ?"
        );
        $stmt->execute([$supplierId, $start, $end]);
        $exemptRevenue = (float) ($stmt->fetchColumn() ?: 0);

        // Costs z přijatých
        $stmt = $this->db->pdo()->prepare(
            "SELECT SUM(COALESCE(pi.{$amount}, 0)) AS total
               FROM purchase_invoices pi
          LEFT JOIN currencies c ON c.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.status NOT IN ('draft', 'cancelled')
                AND pi.tax_deductible = 1
                -- Zálohová / proforma (advance) NENÍ daňový doklad → nikdy uznatelný
                -- náklad (bez ohledu na zaplacení/párování). Symetrické k příjmové
                -- straně, která vylučuje invoice_type='proforma'.
                AND COALESCE(pi.document_kind, '') <> 'advance'
                AND COALESCE(c.code, 'CZK') = 'CZK'
                -- NULL-safe: GREATEST(NULL, x) = NULL → faktury bez DUZP by jinak vypadly.
                AND GREATEST(COALESCE(pi.tax_date, pi.issue_date), pi.issue_date) BETWEEN ? AND ?"
        );
        $stmt->execute([$supplierId, $start, $end]);
        $costs = (float) ($stmt->fetchColumn() ?: 0);

        return ['revenue' => $revenue, 'costs' => $costs, 'exempt_revenue' => $exemptRevenue];
    }

    /** @return list<string> */
    private function validateSupplier(array $s, string $type): array
    {
        $w = [];
        if (empty($s['dic']) && empty($s['ic'])) {
            $w[] = 'Chybí DIČ i IČO.';
        }
        if (empty($s['financial_office_code'])) {
            $w[] = 'Chybí kód finančního úřadu (Nastavení → Daňové).';
        }
        if (!empty($s['taxpayer_type']) && $s['taxpayer_type'] !== $type) {
            $w[] = "V Nastavení máte typ poplatníka '{$s['taxpayer_type']}', generujete však pro '{$type}'.";
        }
        return $w;
    }

    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.ic, s.dic, s.taxpayer_type, s.is_vat_payer, s.financial_office_code,
                    s.workplace_code, s.data_box_id
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) throw new \RuntimeException("Supplier #{$supplierId} nenalezen.");
        return $row;
    }

    private function loadAppVersion(): ?string
    {
        $verFile = __DIR__ . '/../../../../VERSION';
        return is_file($verFile) ? trim((string) file_get_contents($verFile)) : null;
    }

    private function formatAmount(float $amount): string
    {
        return (string) (int) round($amount);
    }
}
