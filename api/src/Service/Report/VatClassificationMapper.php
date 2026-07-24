<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Mapper VAT klasifikací — code → dphdp3_line, kh_section, sazba.
 *
 * Pro každého tenanta načte:
 *   - Globální seed kódy (supplier_id IS NULL)
 *   - Per-tenant override (supplier_id = $supplierId) — pokud existuje, vyhraje
 */
final class VatClassificationMapper
{
    public function __construct(
        private readonly Connection $db,
        private readonly VatLedgerService $ledger,
    ) {}

    /**
     * Měsíční DPH trend za posledních N měsíců (default 12) — počítáno z VatLedgerService
     * (stejná logika jako přiznání: klasifikace, CZK přepočet, RC samovyměření). Historie
     * = finalizované doklady bez draftů. Nahrazuje dřívější crm_monthly_summary (které
     * navíc filtrovalo jen CZK → cizoměnné DPH chybělo).
     *
     * @return list<array{period:string, vat_output:float, vat_input:float, vat_due:float}>
     */
    public function monthlyDphTrend(int $supplierId, int $monthsBack = 12): array
    {
        $now = new \DateTimeImmutable('first day of this month');
        $out = [];
        for ($i = $monthsBack - 1; $i >= 0; $i--) {
            $m = $now->modify("-{$i} months");
            $byLine = $this->aggregateForDphPriznani($supplierId, (int) $m->format('Y'), (int) $m->format('n'));
            $t = $this->dphSummaryTotals($byLine);
            $out[] = [
                'period'     => $m->format('Y-m'),
                'vat_output' => $t['output'],
                'vat_input'  => $t['input'],
                'vat_due'    => $t['due'],
            ];
        }
        return $out;
    }

    /**
     * Predikce DPH pro období VČETNĚ konceptů — pro KPI boxy „DPH na výstupu/vstupu/
     * k odvodu". Stejná (ledger) logika jako přiznání, jen `includeDrafts=true`, plus
     * počty dokladů a konceptů (informativní). Nahrazuje dřívější inline SQL v akci,
     * které sčítalo total_vat napřímo (bez RC samovyměření, bez klasifikace).
     *
     * @return array{vat_output:float, vat_input:float, tax_due:float, sale_count:int,
     *   sale_draft_count:int, purchase_count:int, purchase_draft_count:int}
     */
    public function predictDph(int $supplierId, int $year, int $month, string $period = 'monthly'): array
    {
        [$start, $end] = $this->periodRange($year, $month, $period);
        $rows = $this->ledger->rows($supplierId, $start, $end, includeDrafts: true);
        $totals = $this->dphSummaryTotals($this->projectDphLines($rows));

        $saleInv = []; $saleDraft = []; $purInv = []; $purDraft = [];
        foreach ($rows as $r) {
            $id = (int) $r['invoice_id'];
            if ($r['source'] === 'sale') {
                $saleInv[$id] = true;
                if ($r['is_draft']) $saleDraft[$id] = true;
            } else {
                $purInv[$id] = true;
                if ($r['is_draft']) $purDraft[$id] = true;
            }
        }
        return [
            'vat_output'           => $totals['output'],
            'vat_input'            => $totals['input'],
            'tax_due'              => $totals['due'],
            'sale_count'           => count($saleInv),
            'sale_draft_count'     => count($saleDraft),
            'purchase_count'       => count($purInv),
            'purchase_draft_count' => count($purDraft),
        ];
    }

    /**
     * Output/input/vlastní daň z byLine (output = řádky < 40, input = >= 40 mimo ř.47).
     *
     * @param array<string, array{base:float, vat:float, count:int, label:string}> $byLine
     * @return array{output:float, input:float, due:float}
     */
    public function dphSummaryTotals(array $byLine): array
    {
        $output = 0.0; $input = 0.0;
        foreach ($byLine as $line => $d) {
            if ((int) $line < 40) {
                $output += (float) $d['vat'];
            } elseif ((int) $line !== 47) {
                $input += (float) $d['vat'];
            }
        }
        return ['output' => round($output, 2), 'input' => round($input, 2), 'due' => round($output - $input, 2)];
    }

    /** @return array{0:string, 1:string} [start, end] data rozsahu pro období */
    private function periodRange(int $year, int $month, string $period): array
    {
        if ($period === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $qStartMonth = ($quarter - 1) * 3 + 1;
            $qEndMonth   = $quarter * 3;
            $start = sprintf('%04d-%02d-01', $year, $qStartMonth);
            $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $qEndMonth)))
                ->modify('last day of this month')->format('Y-m-d');
        } else {
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        }
        return [$start, $end];
    }


    /**
     * Aggregace pro DPH přiznání DPHDP3 — vrátí summary per řádek výkazu.
     *
     * Z invoices + purchase_invoices + their items podle období (rok+měsíc nebo kvartál).
     * Quarterly: $month = 0 (Q1 = leden-březen pro $year) nebo 3/6/9/12 (poslední měsíc kvartálu).
     * Pro každou fakturu/řádek najde vat_classification_code (item-level override → invoice-level fallback).
     *
     * @param int $year     Rok (např. 2026)
     * @param int $month    Měsíc (1-12) nebo 0 (= roční přehled)
     * @param string $period 'monthly' | 'quarterly' — quarterly bere celý kvartál
     *                       odpovídající danému $month (Q = ceil($month / 3))
     * @return array<string, array{base:float, vat:float, count:int, label:string}>
     */
    public function aggregateForDphPriznani(int $supplierId, int $year, int $month, string $period = 'monthly'): array
    {
        [$start, $end] = $this->periodRange($year, $month, $period);
        return $this->projectDphLines($this->ledger->rows($supplierId, $start, $end, includeDrafts: false));
    }

    /**
     * Doklady v cizí měně bez zafixovaného kurzu za období (issue #238). Přiznání
     * s náhradním kurzem 1.0 by cizí měnu vykázalo jako CZK — DphPriznaniBuilder je
     * vrátí v `missing_rates`, akce je při stažení doplní z ČNB (náhled jen varuje).
     *
     * @return list<array{invoice_id:int, source:string, currency:string, tax_date:?string, issue_date:?string, doc:string}>
     */
    public function missingRatesForPeriod(int $supplierId, int $year, int $month, string $period = 'monthly'): array
    {
        [$start, $end] = $this->periodRange($year, $month, $period);
        return VatLedgerService::missingExchangeRateRows(
            $this->ledger->rows($supplierId, $start, $end, includeDrafts: false)
        );
    }

    /**
     * Projekce kanonických řádků (VatLedgerService) na řádky DPHDP3. Sdílená logika
     * (klasifikace, CZK, RC samovyměření, rate bucket) žije ve službě; tady jen agregace
     * po dphdp3_line + mirror ř.43 (secondary) + ř.47 (majetek).
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string, array{base:float, vat:float, count:int, label:string}>
     */
    public function projectDphLines(array $rows): array
    {
        $byLine = [];
        $invoiceLineSeen = []; // per (source:invId) × line → distinct count
        foreach ($rows as $r) {
            $primary = $r['dphdp3_line'];
            if ($r['code'] === null || $primary === null) continue; // bez řádku DPHDP3 → přeskoč

            $baseCzk = (float) $r['base_czk'];
            $vatCzk  = (float) $r['vat_czk'];
            $label   = (string) $r['label'];
            // Count distinct faktur per řádek: oddělený namespace sale/purchase.
            $invId = (int) $r['invoice_id'] * 10 + ($r['source'] === 'sale' ? 1 : 2);

            $this->addLine($byLine, $primary, $baseCzk, $vatCzk, $invId, $invoiceLineSeen, $label);

            // Secondary (typicky ř.43 — mirror odpočet u RC / dovozu služby). U plnění bez
            // nároku na odpočet ('none', § 72/4 — např. reprezentace ze zahraničí v RC) se
            // zrcadlový odpočet POTLAČÍ: výstupní samovyměření (primární ř.) zůstává, odpočet ne.
            $secondary = $r['dphdp3_line_secondary'];
            if ($secondary !== null && $secondary !== '' && $secondary !== $primary && empty($r['vat_deduction_none'])) {
                $this->addLine(
                    $byLine,
                    $secondary,
                    (float) ($r['deduction_base_czk'] ?? $baseCzk),
                    (float) ($r['deduction_vat_czk'] ?? $vatCzk),
                    $invId,
                    $invoiceLineSeen,
                    $label,
                );
            }

            // ř.47 — hodnota pořízeného majetku (doplňující údaj k ř.40-45).
            if ($r['is_fixed_asset']) {
                $assetEligibleLine = $this->countsAsFixedAssetLine($primary)
                    ? $primary
                    : (($secondary !== null && $this->countsAsFixedAssetLine($secondary)) ? $secondary : null);
                if ($assetEligibleLine !== null) {
                    $assetBase = $assetEligibleLine === $secondary
                        ? (float) ($r['deduction_base_czk'] ?? $baseCzk)
                        : $baseCzk;
                    $assetVat = $assetEligibleLine === $secondary
                        ? (float) ($r['deduction_vat_czk'] ?? $vatCzk)
                        : $vatCzk;
                    $this->addLine($byLine, '47', $assetBase, $assetVat, $invId, $invoiceLineSeen, 'Hodnota pořízeného majetku (§ 4 odst. 4 písm. c)');
                }
            }
        }

        return $byLine;
    }

    /**
     * @param array<string, array{base:float, vat:float, count:int, label:string}> $byLine by-ref
     * @param array<string, bool> $invoiceLineSeen by-ref
     */
    private function addLine(array &$byLine, string $line, float $baseCzk, float $vatCzk, int $invId, array &$invoiceLineSeen, string $label): void
    {
        if (!isset($byLine[$line])) {
            $byLine[$line] = ['base' => 0.0, 'vat' => 0.0, 'count' => 0, 'label' => $label];
        }
        $byLine[$line]['base'] += $baseCzk;
        $byLine[$line]['vat']  += $vatCzk;
        $seenKey = $invId . ':' . $line;
        if (!isset($invoiceLineSeen[$seenKey])) {
            $invoiceLineSeen[$seenKey] = true;
            $byLine[$line]['count']++;
        }
    }

    /**
     * Smí dané plnění figurovat na ř. 47 (hodnota pořízeného majetku)?
     *
     * Doplňující údaj k odpočtu — vstup do ř. 40-45 (tuzemsko 40/41, dovoz CÚ 42,
     * RC mirror 43, korekce 44, registrace 45). NE pro výstupové řádky 3-13
     * samotné (ty se počítají odděleně přes secondary='43' mirror).
     */
    private function countsAsFixedAssetLine(string $primaryLine): bool
    {
        $n = (int) $primaryLine;
        return $n >= 40 && $n <= 45;
    }
}
