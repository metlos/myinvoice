<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;

/**
 * Builder XML pro Kontrolní hlášení (DPHKH1) — EPO portál MFČR.
 *
 * Verze EPO: 03.01 (platná 2025-2026).
 *
 * Periodicita (§ 101e zákona 235/2004 Sb.):
 *   - **PO** (právnická osoba) — VŽDY měsíčně (odst. 1).
 *   - **FO** (fyzická osoba/OSVČ) — ve lhůtě přiznání k DPH; pro kvartální plátce
 *     lze podávat kvartálně (odst. 2).
 *
 * Sekce KH:
 *   - **A.1** Plnění v režimu přenesené daňové povinnosti (dodavatel)
 *   - **A.2** Pořízení zboží z jiného členského státu (intra-EU acquisition)
 *   - **A.3** Plnění uskutečněná § 92a/b (dodání investičního zlata)
 *   - **A.4** Tuzemská plnění s DPH nad 10 000 Kč (vystavené)
 *   - **A.5** Tuzemská plnění s DPH **do** 10 000 Kč (sumace)
 *   - **B.1** Plnění v režimu přenesené daňové povinnosti (odběratel)
 *   - **B.2** Tuzemská přijatá plnění s DPH nad 10 000 Kč
 *   - **B.3** Tuzemská přijatá plnění s DPH **do** 10 000 Kč (sumace)
 *
 * ⚠️ Vygenerované XML je POUZE POMŮCKA. Před odesláním vždy ověřit s účetní.
 */
final class KontrolniHlaseniBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly VatLedgerService $ledger,
        // Limit A.4/A.5 a B.2/B.3 (10 000 Kč) + práh základní/snížená sazba — per
        // rok období z číselníku daňových konstant (admin override), ne natvrdo.
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    /**
     * @return array{xml: string, summary: array<string,mixed>, warnings: list<string>}
     */
    public function build(int $supplierId, int $year, int $month, string $period = 'monthly'): array
    {
        $supplier = $this->loadSupplier($supplierId);
        $warnings = $this->validateSupplier($supplier, $period);

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

        // Všechny sekce z jedné projekce kanonických řádků (VatLedgerService).
        ['a1' => $a1, 'a2' => $a2, 'a4' => $a4, 'a5' => $a5, 'b1' => $b1, 'b2' => $b2, 'b3' => $b3,
         'missing_rates' => $missingRates]
            = $this->collectSections($supplierId, $start, $end);
        // #238: doklady v cizí měně bez kurzu — akce je při stažení doplní z ČNB.
        if ($missingRates !== []) {
            $warnings[] = 'Chybí kurz u dokladů v cizí měně: '
                . implode(', ', VatLedgerService::missingExchangeRateLabels($missingRates))
                . '. Při stažení XML se doplní z ČNB.';
        }
        $a1 = $this->filterReverseChargeRowsWithDic($a1, 'A.1', $warnings);
        $b1 = $this->filterReverseChargeRowsWithDic($b1, 'B.1', $warnings);
        $a4 = $this->filterKhAttributeConflicts($a4, 'A.4', $warnings);
        $b2 = $this->filterKhAttributeConflicts($b2, 'B.2', $warnings);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $pisemnost = $dom->createElement('Pisemnost');
        $pisemnost->setAttribute('nazevSW', 'MyInvoice.cz');
        $pisemnost->setAttribute('verzeSW', (string) ($this->loadAppVersion() ?? '0'));
        $dom->appendChild($pisemnost);

        $dphkh = $dom->createElement('DPHKH1');
        $dphkh->setAttribute('verzePis', '03.01');
        $pisemnost->appendChild($dphkh);

        // VetaD — identifikační údaje (mesic pro měsíční, ctvrt pro kvartální)
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('dokument', 'KH1');
        $vetaD->setAttribute('k_uladis', 'DPH');
        if ($period === 'quarterly' && $quarter !== null) {
            $vetaD->setAttribute('ctvrt', (string) $quarter);
        } else {
            $vetaD->setAttribute('mesic', (string) $month);
        }
        $vetaD->setAttribute('rok', (string) $year);
        $vetaD->setAttribute('d_poddp', date('d.m.Y')); // datum podání (dnes)
        $vetaD->setAttribute('khdph_forma', 'B'); // B = řádné podání
        $dphkh->appendChild($vetaD);

        // VetaP — identifikace plátce (sdíleno s DPHDP3 přes EpoSupplierBlockBuilder)
        $vetaP = $dom->createElement('VetaP');
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $supplier);
        $dphkh->appendChild($vetaP);

        // VetaA1 — Přenesená daňová povinnost (dodavatel).
        // XSD vyžaduje: dic_odb, c_evid_dd, duzp (NE "dppd"), zakl_dane1, kod_pred_pl.
        // kod_pred_pl '5' = obecný tuzemský reverse charge (defaultní hodnota, MFČR
        // číselník Kód předmětů plnění; ideálně by mělo přicházet z vat_classification_code).
        $rowNum = 0;
        foreach ($a1 as $r) {
            $cleanDic = self::cleanDic($r['counterparty_dic'] ?? '');
            $rowNum++;
            $v = $dom->createElement('VetaA1');
            $v->setAttribute('c_radku', (string) $rowNum);
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dic_odb', $cleanDic);
            $v->setAttribute('duzp', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base']));
            $v->setAttribute('kod_pred_pl', $this->resolveKodPredPl($r['kod_pred_pl'] ?? null, $warnings));
            $dphkh->appendChild($v);
        }

        // VetaA2 — pořízení zboží z jiného členského státu (intra-EU acquisition).
        // Per XSD: k_stat (země dodavatele), vatid_dod (DIČ bez prefixu země),
        // c_evid_dd (číslo dokladu dodavatele), dppd (datum povinnosti přiznat daň
        // — required), zakl_dane1/dan1 (21%), zakl_dane2/dan2 (12%).
        // Plnění je z definice samovyměřené (vendor fakturuje bez DPH, my si daň
        // přiznáme sami) — `dan1`/`dan2` = base × sazba/100, ne pii.total_vat
        // (které je 0 pro RC).
        $rowNum = 0;
        foreach ($a2 as $r) {
            // VAT ID dodavatele z JČS je ALFANUMERICKÉ (např. IE3668997OH → "3668997OH",
            // AT U12345678 → "U12345678") — cleanDic() je jen pro české číselné DIČ a
            // písmena by zahodil. Některé doklady (3. země / neplátce v EU) VAT ID nemají
            // → atribut zůstává prázdný, což XSD (minLength 0) povoluje.
            $isEuSupplier = !empty($r['country_is_eu']);
            $vatId = $isEuSupplier
                ? self::cleanEuVatId($r['counterparty_dic'] ?? '', $r['country_iso2'] ?? '')
                : '';
            $rowNum++;
            $v = $dom->createElement('VetaA2');
            $v->setAttribute('c_radku', (string) $rowNum);
            $kStat = $isEuSupplier ? self::khCountryCode($r['country_iso2'] ?? '') : '';
            if ($kStat !== '') $v->setAttribute('k_stat', $kStat);
            if ($vatId !== '') $v->setAttribute('vatid_dod', $vatId);
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dppd', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base21']));
            $v->setAttribute('dan1',       $this->formatAmount($r['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($r['base12']));
            $v->setAttribute('dan2',       $this->formatAmount($r['vat12']));
            $dphkh->appendChild($v);
        }

        // VetaA4 — tuzemská plnění nad 10 000 Kč (vystavené)
        $rowNum = 0;
        foreach ($a4 as $r) {
            $cleanDic = self::cleanDic($r['counterparty_dic'] ?? '');
            if ($cleanDic === '') continue;
            $rowNum++;
            $taxDate = $this->formatDate($r['tax_date']);
            $v = $dom->createElement('VetaA4');
            $v->setAttribute('c_radku', (string) $rowNum);
            $v->setAttribute('dic_odb', $cleanDic);
            $v->setAttribute('c_evid_dd', (string) $r['varsymbol']);
            $v->setAttribute('dppd', $taxDate);
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base21']));
            $v->setAttribute('dan1', $this->formatAmount($r['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($r['base12']));
            $v->setAttribute('dan2', $this->formatAmount($r['vat12']));
            $v->setAttribute('kod_rezim_pl', (string) ($r['kh_regime_code'] ?? '0'));
            $v->setAttribute('zdph_44', (string) ($r['kh_bad_debt'] ?? 'N'));
            $dphkh->appendChild($v);
        }

        // VetaA5 — tuzemská plnění do 10 000 Kč (sumace, 1 řádek)
        if ($a5['count'] > 0) {
            $v = $dom->createElement('VetaA5');
            $v->setAttribute('zakl_dane1', $this->formatAmount($a5['base21']));
            $v->setAttribute('dan1', $this->formatAmount($a5['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($a5['base12']));
            $v->setAttribute('dan2', $this->formatAmount($a5['vat12']));
            $dphkh->appendChild($v);
        }

        // VetaB1 — Přenesená daňová povinnost (odběratel)
        $rowNum = 0;
        foreach ($b1 as $r) {
            $cleanDic = self::cleanDic($r['counterparty_dic'] ?? '');
            $rowNum++;
            $v = $dom->createElement('VetaB1');
            $v->setAttribute('c_radku', (string) $rowNum);
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dic_dod', $cleanDic);
            // XSD VetaB1 zná atribut 'duzp' (NE 'dppd' jako A.2/B.2) — odběratel v režimu
            // přenesení přiznává daň ke DUZP. Zároveň B.1 vykazuje SAMOVYMĚŘENOU daň
            // (dan1/dan2), ne jen základ — příjemce si daň sám přiznává (a odečítá).
            $v->setAttribute('duzp', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base21']));
            $v->setAttribute('dan1', $this->formatAmount($r['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($r['base12']));
            $v->setAttribute('dan2', $this->formatAmount($r['vat12']));
            $v->setAttribute('kod_pred_pl', $this->resolveKodPredPl($r['kod_pred_pl'] ?? null, $warnings));
            $dphkh->appendChild($v);
        }

        // VetaB2 — přijatá tuzemská nad 10 000 Kč.
        // XSD vyžaduje: pomer (A/N — poměrný odpočet podle §75) a zdph_44
        // (N = běžné, P = oprava nedobytné pohledávky podle §74b, A = §44 do 31.3.2019).
        // Default: oba 'N' (běžný odpočet, žádná oprava).
        $rowNum = 0;
        foreach ($b2 as $r) {
            $cleanDic = self::cleanDic($r['counterparty_dic'] ?? '');
            if ($cleanDic === '') continue;
            $rowNum++;
            $v = $dom->createElement('VetaB2');
            $v->setAttribute('c_radku', (string) $rowNum);
            $v->setAttribute('dic_dod', $cleanDic);
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dppd', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base21']));
            $v->setAttribute('dan1', $this->formatAmount($r['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($r['base12']));
            $v->setAttribute('dan2', $this->formatAmount($r['vat12']));
            // pomer = A když byl uplatněn poměrný odpočet §75 (částky jsou už zkrácené ve VatLedgerService).
            $v->setAttribute('pomer', !empty($r['is_pomer']) ? 'A' : 'N');
            $v->setAttribute('zdph_44', (string) ($r['kh_bad_debt'] ?? 'N'));
            $dphkh->appendChild($v);
        }

        // VetaB3 — přijatá tuzemská do 10 000 Kč (sumace)
        if ($b3['count'] > 0) {
            $v = $dom->createElement('VetaB3');
            $v->setAttribute('zakl_dane1', $this->formatAmount($b3['base21']));
            $v->setAttribute('dan1', $this->formatAmount($b3['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($b3['base12']));
            $v->setAttribute('dan2', $this->formatAmount($b3['vat12']));
            $dphkh->appendChild($v);
        }

        // VetaC — rekapitulace plnění za období (obrat = uskutečněná, pln = přijatá).
        // Sumace všech sekcí: A4+A5 (sales), B2+B3 (purchases), A1 (RC sales),
        // B1 (RC purchases), A2 (EU acquisitions → celk_zd_a2).
        $obrat23 = 0.0; $obrat5 = 0.0;
        foreach ($a4 as $r) { $obrat23 += (float) $r['base21']; $obrat5 += (float) $r['base12']; }
        $obrat23 += (float) ($a5['base21'] ?? 0); $obrat5 += (float) ($a5['base12'] ?? 0);
        $pln23 = 0.0; $pln5 = 0.0;
        foreach ($b2 as $r) { $pln23 += (float) $r['base21']; $pln5 += (float) $r['base12']; }
        $pln23 += (float) ($b3['base21'] ?? 0); $pln5 += (float) ($b3['base12'] ?? 0);
        $plnRezPren = 0.0; foreach ($a1 as $r) { $plnRezPren += (float) $r['base']; }
        $rezPren23 = 0.0; $rezPren5 = 0.0;
        foreach ($b1 as $r) {
            $rezPren23 += (float) $r['base21'];
            $rezPren5 += (float) $r['base12'];
        }
        $vetaC = $dom->createElement('VetaC');
        $vetaC->setAttribute('obrat23',      $this->formatAmount($obrat23));
        $vetaC->setAttribute('obrat5',       $this->formatAmount($obrat5));
        $vetaC->setAttribute('pln23',        $this->formatAmount($pln23));
        $vetaC->setAttribute('pln5',         $this->formatAmount($pln5));
        $vetaC->setAttribute('pln_rez_pren', $this->formatAmount($plnRezPren));
        $vetaC->setAttribute('rez_pren23',   $this->formatAmount($rezPren23));
        $vetaC->setAttribute('rez_pren5',    $this->formatAmount($rezPren5));
        // celk_zd_a2 = celkový základ pořízení zboží z JČS (sekce A.2)
        $celkA2 = 0.0;
        foreach ($a2 as $r) { $celkA2 += (float) $r['base21'] + (float) $r['base12']; }
        $vetaC->setAttribute('celk_zd_a2',   $this->formatAmount($celkA2));
        $dphkh->appendChild($vetaC);

        // Termín podání = 25. dne měsíce následujícího po konci období.
        // U kvartálního podání je rozhodující konec kvartálu ($endMonth), NE předaný
        // $month (jinak build(..., 4, 'quarterly') = Q2 vrátí termín 25.05. místo 25.07.).
        $deadlineMonth = $endMonth + 1;
        $deadlineYear = $year;
        if ($deadlineMonth > 12) { $deadlineMonth -= 12; $deadlineYear++; }
        $deadline = sprintf('%04d-%02d-25', $deadlineYear, $deadlineMonth);

        return [
            'xml'      => $dom->saveXML() ?: '',
            'summary'  => [
                'period'              => $period === 'quarterly' && $quarter !== null
                    ? sprintf('%04d-Q%d', $year, $quarter)
                    : sprintf('%04d-%02d', $year, $month),
                'a1_count'            => count($a1),
                'a2_count'            => count($a2),
                'a4_count'            => count($a4),
                'a5_count_aggregated' => $a5['count'],
                'b1_count'            => count($b1),
                'b2_count'            => count($b2),
                'b3_count_aggregated' => $b3['count'],
                'submission_deadline' => $deadline,
            ],
            'warnings' => $warnings,
            'missing_rates' => $missingRates,
        ];
    }

    /**
     * Projekce kanonických řádků (VatLedgerService) na sekce KH. Nahrazuje 5 původních
     * SQL kolektorů + loadInvoiceVatBreakdown. Per faktura agregujeme základ/daň po
     * sazbách + příznaky sekce, pak směrujeme:
     *   - A.1 = vystavený reverse charge
     *   - A.2 = pořízení zboží z JČS (kh_section A.2; samovyměřená daň ze služby)
     *   - A.4/A.5 = vystavená tuzemská zdanitelná (nad/do limitu + DIČ)
     *   - B.1 = přijatý tuzemský RC (ne A.2)
     *   - B.2/B.3 = přijatá tuzemská zdanitelná
     * Práh `abs()`, plnění bez DIČ → sumace, bez zdanitelného základu → vyloučeno.
     *
     * @return array{a1:list<array<string,mixed>>, a2:list<array<string,mixed>>,
     *   a4:list<array<string,mixed>>, a5:array<string,mixed>, b1:list<array<string,mixed>>,
     *   b2:list<array<string,mixed>>, b3:array<string,mixed>}
     */
    private function collectSections(int $supplierId, string $start, string $end): array
    {
        // Konstanty pro rok OBDOBÍ výkazu (ne aktuální) — zpětně generované KH za
        // staré období musí použít tehdejší limit/sazby.
        $periodYear = (int) substr($start, 0, 4);
        $itemThreshold = $this->taxConstants->khItemThreshold($periodYear);
        $bucket = $this->taxConstants->vatBucketThreshold($periodYear);

        // Agregace kanonických řádků per (zdroj, faktura).
        $ledgerRows = $this->ledger->rows($supplierId, $start, $end, includeDrafts: false);
        // Daňová pojistka (issue #238): non-CZK doklad bez kurzu by se do KH dostal
        // s náhradním kurzem 1.0 (cizí měna jako CZK). NEházíme chybu — vrátíme doklady
        // bez kurzu, akce je při stažení doplní z ČNB (náhled jen varuje).
        $missingRates = VatLedgerService::missingExchangeRateRows($ledgerRows);
        $inv = [];
        foreach ($ledgerRows as $r) {
            $key = $r['source'] . ':' . $r['invoice_id'];
            if (!isset($inv[$key])) {
                $inv[$key] = [
                    'source'                => $r['source'],
                    'varsymbol'             => $r['doc_number'],
                    'vendor_invoice_number' => $r['vendor_invoice_number'],
                    'tax_date'              => $r['tax_date'],
                    'dic'                   => self::cleanDic($r['counterparty_dic']),
                    'dic_raw'               => $r['counterparty_dic'], // syrové VAT ID pro A.2 (EU alfanum.)
                    'country_iso2'          => $r['country_iso2'],
                    'country_is_eu'         => $r['country_is_eu'],
                    'total_czk'             => (float) $r['total_with_vat_czk'],
                    'kod_pred_pl'           => null, // KH kód předmětu plnění (RC) z klasifikace
                    'is_rc' => false, 'has_a1' => false, 'has_a2' => false, 'has_b1' => false, 'is_pomer' => false,
                    'a1_base' => 0.0,
                    'dom_base21' => 0.0, 'dom_vat21' => 0.0, 'dom_base12' => 0.0, 'dom_vat12' => 0.0,
                    'a2_base21' => 0.0, 'a2_vat21' => 0.0, 'a2_base12' => 0.0, 'a2_vat12' => 0.0,
                    'b1_base21' => 0.0, 'b1_vat21' => 0.0, 'b1_base12' => 0.0, 'b1_vat12' => 0.0,
                    'kh_regime_codes' => [], 'kh_bad_debt_codes' => [],
                ];
            }
            $g = &$inv[$key];
            if ($r['is_reverse_charge']) $g['is_rc'] = true;
            if ($r['kh_section'] === 'A.1') $g['has_a1'] = true;
            if ($r['kh_section'] === 'A.2') $g['has_a2'] = true;
            if ($r['kh_section'] === 'B.1') $g['has_b1'] = true;
            if (!empty($r['vat_deduction_partial'])) $g['is_pomer'] = true;
            if (!empty($r['kod_pred_pl'])) $g['kod_pred_pl'] = (string) $r['kod_pred_pl'];
            $base = (float) $r['base_czk'];
            $vat  = (float) $r['vat_czk'];
            $is21 = $r['vat_rate'] >= $bucket;
            // Rozřazení základu/daně do KH kbelíků PODLE SEKCE klasifikace — každá položka
            // přispěje jen do JEDNÉ sekce. Tím se mixed faktura (např. §92 RC řádek +
            // běžný 21% řádek) rozdělí správně (RC část do A.1/B.1, zdanitelná do A.4/B.2),
            // místo aby celý součet spadl do jedné sekce (issue — audit KH/DPH 2026-07).
            // khEligible: vystavené vždy, přijaté jen s nárokem na odpočet (dphdp3_line != NULL);
            // přijaté bez nároku (kód 42, dphdp3_line=NULL) do KH nepatří, DPHDP3 je taky vynechává.
            $khEligible = $r['source'] === 'sale' || $r['dphdp3_line'] !== null;
            switch ($r['kh_section']) {
                case 'A.1': // tuzemský §92 dodavatel — jen základ (VetaA1 nemá sazbové sloupce)
                    $g['a1_base'] += $base;
                    break;
                case 'A.2': // přeshraniční samovyměřené (§ 24 služby, § 25 pořízení zboží z JČS)
                    if ($is21) { $g['a2_base21'] += $base; $g['a2_vat21'] += $vat; }
                    elseif ($r['vat_rate'] > 0) { $g['a2_base12'] += $base; $g['a2_vat12'] += $vat; }
                    break;
                case 'B.1': // tuzemský §92 příjemce — samovyměřená daň (vat z rcSelfAssess)
                    if ($is21) { $g['b1_base21'] += $base; $g['b1_vat21'] += $vat; }
                    elseif ($r['vat_rate'] > 0) { $g['b1_base12'] += $base; $g['b1_vat12'] += $vat; }
                    break;
                default:
                    // Tuzemská zdanitelná plnění (A.4/A.5, B.2/B.3). RC bez KH sekce — dovoz
                    // zboží ze 3. země (kód 25), dodání/služba do EU (kód 20/22) — se sem
                    // NESMÍ dostat (do KH nepatří, jen DPHDP3/SHV) → guard !is_reverse_charge.
                    if ($khEligible && !$r['is_reverse_charge']) {
                        $g['kh_regime_codes'][(string) ($r['kh_regime_code'] ?? '0')] = true;
                        $g['kh_bad_debt_codes'][(string) ($r['kh_bad_debt'] ?? 'N')] = true;
                        if ($is21) { $g['dom_base21'] += $base; $g['dom_vat21'] += $vat; }
                        elseif ($r['vat_rate'] > 0) { $g['dom_base12'] += $base; $g['dom_vat12'] += $vat; }
                    }
            }
            unset($g);
        }

        $a1 = []; $a2 = []; $a4 = []; $b1 = []; $b2 = [];
        $a5 = ['count' => 0, 'base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0];
        $b3 = ['count' => 0, 'base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0];

        foreach ($inv as $g) {
            $hasDic = $g['dic'] !== '';
            // § 101e: „nad 10 000 Kč" = OSTŘE více → přesně 10 000 patří do sumace
            // A.5/B.3, ne do jednotlivé A.4/B.2. Proto '>' (ne '>=').
            $overLimit = abs($g['total_czk']) > $itemThreshold;
            // Tuzemská zdanitelná část faktury (může být 0 u čistě RC/osvobozeného dokladu).
            // Faktura může přispět SOUČASNĚ do RC sekce (A.1/B.1/A.2) i do A.4/A.5/B.2/B.3
            // (mixed doklad) — proto žádný `continue`, sekce se vyhodnocují nezávisle.
            $domZero = abs($g['dom_base21']) < 0.005 && abs($g['dom_base12']) < 0.005;

            if ($g['source'] === 'sale') {
                // A.1 — tuzemský režim přenesení (§ 92a–92e, kód 25s). Jen položky sekce A.1.
                if ($g['has_a1'] && abs($g['a1_base']) >= 0.005) {
                    $a1[] = ['counterparty_dic' => $g['dic_raw'], 'vendor_invoice_number' => $g['varsymbol'],
                             'tax_date' => $g['tax_date'], 'base' => $g['a1_base'],
                             'kod_pred_pl' => $g['kod_pred_pl']];
                }
                // A.4/A.5 — tuzemská zdanitelná část (RC/osvobozené/EU dodání/vývoz nepřispěly).
                if (!$domZero) {
                    $regimeCodes = array_keys($g['kh_regime_codes']);
                    $badDebtCodes = array_keys($g['kh_bad_debt_codes']);
                    $row = ['varsymbol' => $g['varsymbol'], 'tax_date' => $g['tax_date'], 'counterparty_dic' => $g['dic'],
                            'base21' => $g['dom_base21'], 'vat21' => $g['dom_vat21'],
                            'base12' => $g['dom_base12'], 'vat12' => $g['dom_vat12'],
                            'kh_regime_code' => count($regimeCodes) === 1 ? $regimeCodes[0] : null,
                            'kh_bad_debt' => count($badDebtCodes) === 1 ? $badDebtCodes[0] : null,
                            'kh_attribute_conflict' => count($regimeCodes) > 1 || count($badDebtCodes) > 1];
                    if (($overLimit || $row['kh_bad_debt'] === 'P') && $hasDic) {
                        $a4[] = $row;
                    } else {
                        $a5['count']++; $a5['base21'] += $g['dom_base21']; $a5['vat21'] += $g['dom_vat21'];
                        $a5['base12'] += $g['dom_base12']; $a5['vat12'] += $g['dom_vat12'];
                    }
                }
            } else { // purchase
                // A.2 — přeshraniční samovyměřená plnění (§ 24 služby z EU i 3. země,
                // § 25 pořízení zboží z JČS). vatid_dod nese syrové EU VAT ID (alfanum.).
                if ($g['has_a2']) {
                    $a2[] = ['vendor_invoice_number' => $g['vendor_invoice_number'], 'tax_date' => $g['tax_date'],
                             'counterparty_dic' => $g['dic_raw'], 'country_iso2' => $g['country_iso2'],
                             'country_is_eu' => $g['country_is_eu'],
                             'base21' => $g['a2_base21'], 'vat21' => $g['a2_vat21'],
                             'base12' => $g['a2_base12'], 'vat12' => $g['a2_vat12']];
                }
                // B.1 — tuzemský režim přenesení (§ 92a–92e) příjemce. Per-sazbové agregáty
                // nesou i samovyměřenou daň (vat z rcSelfAssess) — B.1 ji vykazuje, ne jen základ.
                if ($g['has_b1']) {
                    $b1[] = ['counterparty_dic' => $g['dic_raw'], 'vendor_invoice_number' => $g['vendor_invoice_number'],
                             'tax_date' => $g['tax_date'], 'base' => $g['b1_base21'] + $g['b1_base12'],
                             'base21' => $g['b1_base21'], 'vat21' => $g['b1_vat21'],
                             'base12' => $g['b1_base12'], 'vat12' => $g['b1_vat12'],
                             'kod_pred_pl' => $g['kod_pred_pl']];
                }
                // B.2/B.3 — tuzemská přijatá zdanitelná (s nárokem). RC bez KH sekce (dovoz
                // ze 3. země kód 25) a plnění bez nároku (kód 42) do dom_* nepřispěly.
                if (!$domZero) {
                    $badDebtCodes = array_keys($g['kh_bad_debt_codes']);
                    $row = ['vendor_invoice_number' => $g['vendor_invoice_number'], 'tax_date' => $g['tax_date'],
                            'counterparty_dic' => $g['dic'], 'base21' => $g['dom_base21'], 'vat21' => $g['dom_vat21'],
                            'base12' => $g['dom_base12'], 'vat12' => $g['dom_vat12'], 'is_pomer' => $g['is_pomer'],
                            'kh_bad_debt' => count($badDebtCodes) === 1 ? $badDebtCodes[0] : null,
                            'kh_attribute_conflict' => count($badDebtCodes) > 1];
                    if (($overLimit || $row['kh_bad_debt'] === 'P') && $hasDic) {
                        $b2[] = $row;
                    } else {
                        $b3['count']++; $b3['base21'] += $g['dom_base21']; $b3['vat21'] += $g['dom_vat21'];
                        $b3['base12'] += $g['dom_base12']; $b3['vat12'] += $g['dom_vat12'];
                    }
                }
            }
        }

        return ['a1' => $a1, 'a2' => $a2, 'a4' => $a4, 'a5' => $a5, 'b1' => $b1, 'b2' => $b2, 'b3' => $b3, 'missing_rates' => $missingRates];
    }

    /** @return list<string> warnings */
    private function validateSupplier(array $s, string $period = 'monthly'): array
    {
        $w = [];
        if (!$s['is_vat_payer']) {
            // Identifikovaná osoba (§ 6g–6l, issue #94) KH nepodává NIKDY (§ 101c
            // jen plátci) — přeshraniční povinnosti pokrývá DPHDP3 typ I + SHV.
            $w[] = !empty($s['is_identified'])
                ? 'Identifikovaná osoba kontrolní hlášení nepodává (§ 101c — jen plátci DPH). Přeshraniční plnění patří do přiznání DPH (typ I) a souhrnného hlášení.'
                : 'Tenant není plátce DPH — KH nemusí být relevantní.';
        }
        if ($period === 'quarterly' && ($s['taxpayer_type'] ?? '') === 'po') {
            $w[] = 'Právnické osoby podávají kontrolní hlášení VŽDY měsíčně (§ 101e odst. 1 zákona 235/2004 Sb.). Kvartální podání je povoleno pouze fyzickým osobám.';
        }
        if (empty($s['financial_office_code'])) $w[] = 'Chybí kód finančního úřadu.';
        if (empty($s['dic'])) $w[] = 'Chybí DIČ.';
        return $w;
    }

    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.ic, s.dic, s.is_vat_payer, s.is_identified,
                    s.taxpayer_type, s.vat_period, s.financial_office_code,
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

    /**
     * KH „Kód předmětu plnění" (VetaA1/VetaB1.kod_pred_pl) z klasifikace. Není-li na
     * klasifikaci vyplněn, spadne na '5' (odpad/šrot §92c) + jednorázový warning —
     * nejčastější tuzemský RC jsou ale stavební/montážní práce (§92e, kód '4').
     *
     * @param list<string> $warnings by-ref
     */
    private function resolveKodPredPl(?string $value, array &$warnings): string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }
        $w = 'U některých plnění v přenesené povinnosti není na klasifikaci vyplněn kód '
           . 'předmětu plnění — použit default „5" (odpad/šrot §92c). Ověřte správný kód '
           . '(stavební/montážní práce = „4").';
        if (!in_array($w, $warnings, true)) {
            $warnings[] = $w;
        }
        return '5';
    }

    /**
     * Tuzemské RC vyžaduje číselnou kmenovou část DIČ. Neplatný řádek nesmí zůstat
     * v rekapitulaci VetaC, když jej nelze emitovat do A.1/B.1.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string> $warnings
     * @return list<array<string,mixed>>
     */
    private function filterReverseChargeRowsWithDic(array $rows, string $section, array &$warnings): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($section, &$warnings): bool {
            if (self::isValidCzechDic($row['counterparty_dic'] ?? '')) {
                return true;
            }
            $number = (string) ($row['vendor_invoice_number'] ?? 'bez čísla');
            $warnings[] = "Doklad {$number} nelze uvést v KH {$section}: chybí platné české DIČ protistrany. Doplňte DIČ před podáním.";
            return false;
        }));
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $warnings
     * @return list<array<string,mixed>>
     */
    private function filterKhAttributeConflicts(array $rows, string $section, array &$warnings): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($section, &$warnings): bool {
            if (empty($row['kh_attribute_conflict'])) {
                return true;
            }
            $number = (string) ($row['varsymbol'] ?? $row['vendor_invoice_number'] ?? 'bez čísla');
            $warnings[] = "Doklad {$number} nelze uvést v KH {$section}: položky mají rozdílný režim plnění nebo příznak opravy nedobytné pohledávky. Sjednoťte klasifikaci před podáním.";
            return false;
        }));
    }

    private static function isValidCzechDic(?string $dic): bool
    {
        $value = strtoupper(trim((string) $dic));
        return preg_match('/^(?:CZ)?[0-9]{1,10}$/', $value) === 1;
    }

    /** DIČ pro KH XML — odstraní CZ prefix, jen číslice. */
    /** Public static: stejnou normalizaci DIČ používá DphBookBuilder pro efektivní KH sekci. */
    public static function cleanDic(?string $dic): string
    {
        if (!$dic) return '';
        // CZ12345678 → 12345678. Pattern v XSD je [0-9]{1,10}, takže strip vše ne-digit po prefixu.
        $clean = preg_replace('/^CZ/i', '', strtoupper(trim($dic))) ?? '';
        return preg_replace('/[^0-9]/', '', $clean) ?? '';
    }

    /**
     * VAT ID dodavatele z jiného členského státu pro VetaA2.vatid_dod (KH oddíl A.2).
     *
     * Na rozdíl od českého DIČ (jen číslice, viz cleanDic) je EU VAT ID alfanumerické a
     * u řady států obsahuje písmena (IE 1234567X, AT U12345678, NL 123456789B01, …).
     * XSD vyžaduje formát BEZ kódu členského státu — odstraníme prefix země, mezery a
     * oddělovače, zachováme alfanumerickou kmenovou část.
     *
     * Strháváme JEN prefix odpovídající kódu země — buď ISO 3166 (country_iso2), nebo
     * VIES/DPH kód (u Řecka se liší: ISO "GR" vs VIES "EL", takže akceptujeme obojí).
     * NEstrháváme libovolná 2 písmena — některá DIČ mají alfanumerickou vnitrostátní
     * část (FR: „FRAB123456789" → „AB123456789", ne „123456789"). Issue #238.
     */
    public static function cleanEuVatId(?string $vatId, ?string $countryIso2): string
    {
        if (!$vatId) return '';
        $s = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($vatId))) ?? '';
        $iso  = strtoupper(trim((string) $countryIso2)); // ISO 3166 (GR)
        $vies = self::khCountryCode($countryIso2);        // VIES/DPH kód (EL pro Řecko)
        foreach ([$vies, $iso] as $prefix) {
            if ($prefix !== '' && str_starts_with($s, $prefix)) {
                return substr($s, strlen($prefix));
            }
        }
        return $s;
    }

    /**
     * Kód státu pro KH (VetaA2.k_stat / prefix VAT ID). Vychází z ISO 3166-1 alpha-2,
     * ale s odchylkami EU registru DPH: Řecko má ISO "GR", ale DPH kód "EL".
     */
    public static function khCountryCode(?string $iso2): string
    {
        $c = strtoupper(trim((string) $iso2));
        return $c === 'GR' ? 'EL' : $c;
    }

    /** Date pro KH XML — convert YYYY-MM-DD na DD.MM.YYYY (EPO datum format). */
    private function formatDate(?string $isoDate): string
    {
        if (!$isoDate) return '';
        try {
            return (new \DateTimeImmutable($isoDate))->format('d.m.Y');
        } catch (\Throwable) {
            return '';
        }
    }

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
