<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Builder XML pro Souhrnné hlášení (DPHSHV1) — EPO portál MFČR.
 *
 * Verze EPO: 06.01 (platná 2025+).
 *
 * **K čemu slouží:**
 * Výkaz EU dodání zboží/služeb (intra-community supplies) v režimu B2B —
 * dodávky plátcům v jiných členských státech EU. Submit per měsíc (povinnost
 * pro plátce DPH s alespoň jednou EU dodávkou v daném měsíci).
 *
 * **Sekce SH:**
 * Per řádek (group by counterparty VAT_ID + kód plnění):
 *   - Kód plnění (k_pln_eu) dle DPHSHV XSD:
 *     - **0** = Dodání zboží do jiného členského státu (ř.20 DPHDP3, VAT kód "20")
 *     - **1** = Přemístění obchodního majetku do JČS (§ 13 odst. 6)
 *     - **2** = Dodání zboží formou třístranného obchodu prostřední osobou (§ 17, ř.31, VAT kód "31")
 *     - **3** = Poskytnutí služby s místem plnění v JČS (§ 9 odst. 1, ř.21, VAT kód "22")
 *   - DIČ kupujícího BEZ prefixu země (kód země je zvlášť v k_stat), např. 1234567890
 *   - Hodnota plnění v CZK (základ daně, bez DPH)
 *   - Počet plnění
 *
 * ⚠️ Vygenerované XML je POUZE POMŮCKA. Před odesláním ověřit s účetní.
 */
final class SouhrnneHlaseniBuilder
{
    /**
     * Mapování VAT klasifikačních kódů na kód plnění SH (k_pln_eu) dle DPHSHV XSD:
     *   0 = dodání zboží do JČS (§13)
     *   1 = přemístění obchodního majetku do JČS (§13/6)
     *   2 = dodání zboží formou třístranného obchodu prostřední osobou (§17)
     *   3 = poskytnutí služby s místem plnění v JČS (§9/1), daň přiznává příjemce
     *
     * Klasifikační kódy číselníku:
     *   "20" (EU dodání zboží)             → 0
     *   "31" (třístranný obchod, ř.31)     → 2  (prostřední osoba)
     *   "22" (EU služby, ř.21)             → 3
     */
    private const VAT_CODE_TO_SH_TYPE = [
        '20' => '0',  // dodání zboží do JČS
        '31' => '2',  // třístranný obchod — dodání zboží prostřední osobou (§17)
        '22' => '3',  // poskytnutí služby do JČS (§9/1)
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly VatLedgerService $ledger,
    ) {}

    /**
     * @return array{xml: string, summary: array<string,mixed>, warnings: list<string>}
     */
    public function build(int $supplierId, int $year, int $month, string $period = 'monthly'): array
    {
        $supplier = $this->loadSupplier($supplierId);
        $warnings = $this->validateSupplier($supplier);

        if ($period === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $startMonth = ($quarter - 1) * 3 + 1;
            // Konec kvartálu = poslední den měsíce quarter*3, NEZÁVISLE na předaném
            // $month (jinak build(..., 4, 'quarterly') utne období na duben a zahodí
            // květen+červen). Stejná logika jako DphBookBuilder::build().
            $endMonth = $quarter * 3;
            $start = sprintf('%04d-%02d-01', $year, $startMonth);
        } else {
            $quarter = null;
            $endMonth = $month;
            $start = sprintf('%04d-%02d-01', $year, $month);
        }
        $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endMonth)))->modify('last day of this month')->format('Y-m-d');

        $missingRates = [];
        $rows = $this->collectEuSupplies($supplierId, $start, $end, $missingRates);

        // #238: EU dodávky v cizí měně bez zafixovaného kurzu. NEházíme chybu — vrátíme
        // je v `missing_rates`; akce při stažení je doplní z ČNB, náhled jen varuje.
        if ($missingRates !== []) {
            $warnings[] = 'Chybí kurz u EU dodávek v cizí měně: '
                . implode(', ', VatLedgerService::missingExchangeRateLabels($missingRates))
                . '. Při stažení XML se doplní z ČNB.';
        }

        $periodLabel = $period === 'quarterly' && $quarter !== null
            ? "tomto čtvrtletí"
            : "tomto měsíci";
        if (empty($rows)) {
            $warnings[] = "V {$periodLabel} nejsou žádné EU dodávky — SH se nepodává.";
        }

        // § 102 odst. 6 ZDPH: kvartální podání SH je přípustné JEN u výhradně
        // poskytovaných služeb (kód plnění 3). Jakmile je v období dodání zboží do JČS
        // (sh_type '0' nebo třístranný obchod '2'), musí se podávat MĚSÍČNĚ.
        if ($period === 'quarterly') {
            foreach ($rows as $r) {
                if (in_array((string) $r['sh_type'], ['0', '2'], true)) {
                    $warnings[] = 'Dodání zboží do JČS vyžaduje měsíční podání souhrnného '
                        . 'hlášení (§ 102 odst. 6 ZDPH) — kvartální podání je přípustné jen '
                        . 'u výhradně poskytovaných služeb. Toto kvartální podání obsahuje zboží.';
                    break;
                }
            }
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $pisemnost = $dom->createElement('Pisemnost');
        $pisemnost->setAttribute('nazevSW', 'MyInvoice.cz');
        $pisemnost->setAttribute('verzeSW', (string) ($this->loadAppVersion() ?? '0'));
        $dom->appendChild($pisemnost);

        $shv = $dom->createElement('DPHSHV');
        $shv->setAttribute('verzePis', '06.01');
        $pisemnost->appendChild($shv);

        // VetaD — typ podání + perioda (mesic pro měsíční, ctvrt pro kvartální)
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', 'DPH');
        $vetaD->setAttribute('rok', (string) $year);
        if ($period === 'quarterly' && $quarter !== null) {
            $vetaD->setAttribute('ctvrt', (string) $quarter);
        } else {
            $vetaD->setAttribute('mesic', (string) $month);
        }
        // shvies_forma: EPO povoluje pouze [RN] — R = řádné, N = následné (opravné).
        // (Pozn.: dřívější 'B' bylo omylem převzato z KH — DPHSHV žádné 'B' nezná
        //  a EPO ho odmítá „...neodpovídá regulárnímu výrazu [RN]". Issue #238.)
        $vetaD->setAttribute('shvies_forma', 'R');
        $vetaD->setAttribute('dokument', 'SHV');
        $shv->appendChild($vetaD);

        // VetaP — identifikace poplatníka. Sdílený helper (stejný jako DPHDP3/DPHKH1):
        // odstranění akademických titulů + rozdělení jména na jmeno/prijmeni (#200) a
        // adresy na ulice/c_pop/c_orient. `includeContact: false`, protože DPHSHV XSD
        // atributy email/c_telef nezná (na rozdíl od DPH/KH) — EPO by je odmítlo.
        $vetaP = $dom->createElement('VetaP');
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $supplier, includeContact: false);
        $shv->appendChild($vetaP);

        // VetaR — jednotlivé řádky souhrnného hlášení (per VAT_ID + typ plnění).
        // Pozn.: schéma EPO2 přejmenovalo dřívější VetaA1 → VetaR a atributy:
        //   vatid_pod  → c_vat
        //   kod_plneni → k_pln_eu
        // VetaS je vyhrazena pro storna (oprava předchozích období) — nepoužíváme.
        $totalRows = 0;
        $totalAmount = 0.0;
        $rowNum = 0;
        foreach ($rows as $r) {
            $rowNum++;
            $v = $dom->createElement('VetaR');
            $v->setAttribute('c_rad', (string) $rowNum);
            // k_storno se v ŘÁDNÉM hlášení NEvyplňuje — EPO ho odmítá („Pro řádné
            // souhrnné hlášení nesmí být vyplněn kód storna"). Slouží jen pro storno
            // řádky v NÁSLEDNÉM hlášení (to zatím negenerujeme). Issue #238.
            // k_stat = kód státu pro DPH/VIES (Řecko má ISO "GR", ale DPH kód "EL").
            $v->setAttribute('k_stat', KontrolniHlaseniBuilder::khCountryCode($r['country_iso2']));
            // c_vat = DIČ BEZ prefixu země (kód země nese k_stat). Issue #238.
            $v->setAttribute('c_vat', $r['vat_id']);
            $v->setAttribute('k_pln_eu', $r['sh_type']);
            $v->setAttribute('pln_hodnota', $this->formatAmount($r['amount']));
            $v->setAttribute('pln_pocet', (string) $r['count']);
            $shv->appendChild($v);
            $totalRows++;
            $totalAmount += $r['amount'];
        }

        // Termín podání: 25. dne měsíce následujícího po konci období
        $deadlineMonth = $endMonth + 1;
        $deadlineYear = $year;
        if ($deadlineMonth > 12) { $deadlineMonth -= 12; $deadlineYear++; }
        $deadline = sprintf('%04d-%02d-25', $deadlineYear, $deadlineMonth);

        return [
            'xml'     => $dom->saveXML() ?: '',
            'summary' => [
                'period'              => $period === 'quarterly' && $quarter !== null
                    ? sprintf('%04d-Q%d', $year, $quarter)
                    : sprintf('%04d-%02d', $year, $month),
                'rows_count'          => $totalRows,
                'total_amount'        => round($totalAmount, 2),
                'rows'                => $rows,
                'submission_deadline' => $deadline,
            ],
            'warnings' => $warnings,
            'missing_rates' => $missingRates,
        ];
    }

    /**
     * Sebere EU dodávky (vystavené faktury s VAT kódem 20/22 + EU klient s DIČ).
     * Agreguje per (country_iso2, vat_id, sh_type).
     *
     * @return list<array{country_iso2:string, vat_id:string, sh_type:string,
     *                   amount:float, count:int, counterparty_name:string}>
     */
    private function collectEuSupplies(int $supplierId, string $start, string $end, array &$missingRates = []): array
    {
        // Projekce kanonických řádků (VatLedgerService) — vystavená EU B2B plnění:
        // kód 20/21/22, EU země (≠ CZ) s DIČ. base_czk je už PŘEPOČTENÝ na CZK kurzem
        // faktury (oprava staré chyby — SH dříve sčítalo total_without_vat v cizí měně).
        $result = [];
        $missingSeen = [];
        foreach ($this->ledger->rows($supplierId, $start, $end, includeDrafts: false) as $r) {
            if ($r['source'] !== 'sale') continue;
            $code = $r['code'];
            if ($code === null || !isset(self::VAT_CODE_TO_SH_TYPE[$code])) continue;
            if (!$r['country_is_eu'] || $r['country_iso2'] === 'CZ' || $r['country_iso2'] === null) continue;

            // c_vat = DIČ BEZ prefixu země (strhne jen prefix odpovídající zemi, ne
            // libovolná 2 písmena — FR má alfanumerickou vnitrostátní část; GR→EL).
            // Používáme sdílenou (a proti VIES ověřenou) normalizaci z KH. Issue #238.
            $vatId = KontrolniHlaseniBuilder::cleanEuVatId(
                (string) ($r['counterparty_dic'] ?? ''),
                (string) $r['country_iso2'],
            );
            if ($vatId === '') continue; // bez DIČ nelze podat SH

            // Daňová pojistka: EU dodávka v cizí měně bez zafixovaného kurzu by se
            // vykázala s náhradním kurzem 1.0 (EUR jako CZK). Sesbíráme ji do
            // $missingRates — akce ji při stažení doplní z ČNB (issue #238).
            if (!empty($r['exchange_rate_missing'])) {
                $key = 'sale:' . (int) $r['invoice_id'];
                if (!isset($missingSeen[$key])) {
                    $missingSeen[$key] = true;
                    $doc = (string) ($r['doc_number'] ?? '') ?: ('#' . (string) $r['invoice_id']);
                    $missingRates[] = [
                        'invoice_id' => (int) $r['invoice_id'],
                        'source'     => 'sale',
                        'currency'   => (string) $r['currency'],
                        'tax_date'   => isset($r['tax_date']) ? (string) $r['tax_date'] : null,
                        'issue_date' => isset($r['issue_date']) ? (string) $r['issue_date'] : null,
                        'doc'        => $doc,
                    ];
                }
            }

            $shType = self::VAT_CODE_TO_SH_TYPE[$code];
            $key = "{$r['country_iso2']}|{$vatId}|{$shType}";
            if (!isset($result[$key])) {
                $result[$key] = [
                    'country_iso2'      => $r['country_iso2'],
                    'vat_id'            => $vatId,
                    'sh_type'           => $shType,
                    'amount'            => 0.0,
                    'count'             => 0,
                    'counterparty_name' => (string) $r['counterparty_name'],
                    '_invoice_ids'      => [],
                ];
            }
            $result[$key]['amount'] += (float) $r['base_czk'];
            // Počet plnění = počet DISTINCT faktur (řádky jsou per-položka).
            $result[$key]['_invoice_ids'][(int) $r['invoice_id']] = true;
        }
        // Finalizace: count = počet distinct faktur, odstranit pomocné pole.
        return array_map(static function (array $row): array {
            $row['count'] = count($row['_invoice_ids']);
            unset($row['_invoice_ids']);
            return $row;
        }, array_values($result));
    }

    /**
     * Note: Souhrnné hlášení **nevyžaduje** být plátcem DPH.
     * Podávají ho i **identifikované osoby** (neplátci, kteří poskytují služby EU plátcům
     * nebo nakupují zboží z EU nad limit). DIČ je u identifikované osoby ve formátu
     * CZ + RČ/IČO, prefix CZ se v SH XML ponechává.
     *
     * @return list<string>
     */
    private function validateSupplier(array $s): array
    {
        $w = [];
        if (empty($s['financial_office_code'])) $w[] = 'Chybí kód finančního úřadu.';
        if (empty($s['dic'])) $w[] = 'Chybí DIČ (povinné i pro identifikovanou osobu).';
        return $w;
    }

    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.ic, s.dic, s.is_vat_payer, s.is_identified,
                    s.taxpayer_type, s.financial_office_code,
                    s.workplace_code, s.data_box_id,
                    s.email, s.phone, s.cz_nace_code,
                    s.street_number_pop, s.street_number_orient,
                    s.opr_jmeno, s.opr_prijmeni, s.opr_postaveni,
                    s.sest_jmeno, s.sest_prijmeni, s.sest_telefon, s.sest_email, s.sest_funkce
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
        return (string) (int) ceil($amount);
    }
}
