<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;

/**
 * Stormware Pohoda XML data package exporter.
 *
 * Spec: https://www.stormware.cz/xml/  (Pohoda mServer XML komunikace)
 * Namespaces:
 *   dat — http://www.stormware.cz/schema/version_2/data.xsd
 *   inv — http://www.stormware.cz/schema/version_2/invoice.xsd
 *   typ — http://www.stormware.cz/schema/version_2/type.xsd
 *
 * Vytváří jeden `<dat:dataPack>` se všemi fakturami za dané období.
 *
 * Směr dokladu řídí `$cfg['direction']` ('issued' = default | 'purchase'):
 * Mapování invoice_type → invoiceType (vydané / přijaté):
 *   invoice      → issuedInvoice        / receivedInvoice
 *   proforma     → issuedAdvanceInvoice / receivedAdvanceInvoice
 *   credit_note  → issuedCreditNotice   / receivedCreditNotice
 *   cancellation → (přeskakuje se — interní storno se do Pohody neexportuje)
 *
 * `partnerIdentity` nese protistranu: u vydané faktury odběratele (client),
 * u přijaté faktury dodavatele (vendor → supplier_snapshot). Hodnoty invoiceType
 * jsou z `inv:invoiceTypeType` (žádný „issuedTaxDocument" — ten v enum NEEXISTUJE).
 *
 * Per-supplier konfigurace (volitelná):
 *   pohoda_account_code  → <inv:account><typ:ids>...</typ:ids></inv:account>
 *   pohoda_centre_code   → <inv:centre><typ:ids>...</typ:ids></inv:centre>
 *   pohoda_activity_code → <inv:activity><typ:ids>...</typ:ids></inv:activity>
 *   pohoda_contract_code → <inv:contract><typ:ids>...</typ:ids></inv:contract>
 *
 * VAT classification (`<inv:classificationVAT>`) — Pohoda schema vyžaduje STRUKTUROVANÉ
 * dítě `<typ:ids>` + `<typ:classificationVATType>` ({inland, nonSubsume}), NE prostý text
 * (validation error: "typ Text v tomto kontextu elementu classificationVAT není povolen").
 * Mapování podle vat_rate_snapshot:
 *   21 %  → UDA5    + inland     (tuzemské plnění základní)
 *   12 %  → UDA5_12 + inland     (snížené)
 *    0 %  → UNX     + nonSubsume (osvobozeno)
 *   reverse_charge → PNAR + nonSubsume (přenesená daňová povinnost)
 */
final class PohodaXmlExporter
{
    public const NS_DAT = 'http://www.stormware.cz/schema/version_2/data.xsd';
    public const NS_INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
    public const NS_TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';

    /**
     * Délkové facety `typ:addressType` z type.xsd. Delší hodnota shodí XSD validaci,
     * takže se ořezává (mb_substr kvůli diakritice). Adresa je identifikační údaj,
     * ne částka — zkrácení je přijatelnější než neexportovatelný doklad.
     */
    private const ADDR_MAX = [
        'company' => 255,  // typ:stringCompany
        'name'    => 64,   // typ:string64
        'street'  => 64,   // typ:string64
        'city'    => 45,   // typ:string45
        'zip'     => 15,   // typ:string15
        'email'   => 98,   // typ:string98
        'phone'   => 40,   // typ:string40
    ];

    private readonly InvoiceExportDataResolver $dataResolver;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly TaxConstantsRepository $taxConstants,
        ?InvoiceExportDataResolver $dataResolver = null,
    ) {
        $this->dataResolver = $dataResolver ?? new InvoiceExportDataResolver($db);
    }

    /**
     * Hranice základní sazby (bucket high vs low) pro rok dokladu — z číselníku
     * daňových konstant místo natvrdo 20,5. Hranice low/low2 (11,5 / 9,5) zůstávají
     * fixní: jsou to středy historických snížených sazeb (12/10 %), které Pohoda
     * kategorie low/low2 přímo kopírují.
     */
    private function highBoundary(array $invoice): float
    {
        $date = (string) ($invoice['tax_date'] ?? $invoice['issue_date'] ?? '');
        $year = $date !== '' ? (int) substr($date, 0, 4) : (int) date('Y');
        return $this->taxConstants->vatBucketThreshold($year);
    }

    /**
     * @param int[] $invoiceIds
     * @return array{filename:string, content:string, mime:string}
     */
    public function export(array $invoiceIds, int $supplierId, string $monthLabel = ''): array
    {
        $invoices = [];
        foreach ($invoiceIds as $id) {
            $inv = $this->repo->find((int) $id);
            if ($inv !== null && $inv['invoice_type'] !== 'cancellation') {
                $invoices[] = $inv;
            }
        }

        if (empty($invoices)) {
            throw new \RuntimeException('Žádné faktury k exportu (cancellation se přeskakuje).');
        }

        // Supplier config + IČO pro dataPackHeader
        $stmt = $this->db->pdo()->prepare(
            'SELECT ic, pohoda_account_code, pohoda_centre_code, pohoda_activity_code, pohoda_contract_code
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $cfg = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $xml = $this->buildXml($invoices, $cfg);

        $base = 'pohoda-' . ($monthLabel !== '' ? $monthLabel : date('Y-m-d'));
        return [
            'filename' => "$base.xml",
            'content'  => $xml,
            'mime'     => 'application/xml',
        ];
    }

    /**
     * @param array $invoices Pole faktur z InvoiceRepository::find().
     * @param array $cfg supplier row (ic + pohoda_*_code).
     */
    public function buildXml(array $invoices, array $cfg): string
    {
        // UTF-8 — moderní Pohoda (2010+) UTF-8 akceptuje, žádné mojibake na
        // exotičtější diakritice, konzistentní s ISDOC i zbytkem aplikace.
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $dataPack = $dom->createElementNS(self::NS_DAT, 'dat:dataPack');
        $dataPack->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:inv', self::NS_INV);
        $dataPack->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:typ', self::NS_TYP);
        $dataPack->setAttribute('version', '2.0');
        $dataPack->setAttribute('id', 'myinvoice-' . date('YmdHis'));
        $dataPack->setAttribute('ico', (string) ($cfg['ic'] ?? ''));
        $dataPack->setAttribute('application', 'MyInvoice.cz');
        $dataPack->setAttribute('note', 'Export ' . date('Y-m-d H:i'));
        $dom->appendChild($dataPack);

        // Směr dokladů v balíčku: 'purchase' = přijaté faktury (protistrana = dodavatel,
        // typ dokladu received*), jinak vydané (issued*). Nastavuje PurchaseInvoiceExportService.
        $isPurchase = ($cfg['direction'] ?? '') === 'purchase';

        foreach ($invoices as $idx => $invoice) {
            $item = $dom->createElementNS(self::NS_DAT, 'dat:dataPackItem');
            $item->setAttribute('version', '2.0');
            $item->setAttribute('id', 'inv-' . $invoice['id']);
            $dataPack->appendChild($item);

            $inv = $dom->createElementNS(self::NS_INV, 'inv:invoice');
            $inv->setAttribute('version', '2.0');
            $item->appendChild($inv);

            // Header
            $hdr = $dom->createElementNS(self::NS_INV, 'inv:invoiceHeader');
            $invType = $isPurchase
                ? match ($invoice['invoice_type']) {
                    'proforma'    => 'receivedAdvanceInvoice',
                    'credit_note' => 'receivedCreditNotice',
                    default       => 'receivedInvoice',
                }
                : match ($invoice['invoice_type']) {
                    'proforma'     => 'issuedAdvanceInvoice',
                    'credit_note'  => 'issuedCreditNotice',
                    // „issuedTaxDocument" NENÍ v invoiceTypeType (XSD) — daňový doklad
                    // k přijaté platbě exportujeme jako běžnou vydanou fakturu.
                    'tax_document' => 'issuedInvoice',
                    default        => 'issuedInvoice',
                };
            $this->el($dom, $hdr, self::NS_INV, 'inv:invoiceType', $invType);

            $vs = (string) ($invoice['varsymbol'] ?? '');
            // Evidenční číslo dokladu (numberRequested) jen u VYDANÝCH — je to NAŠE číslo
            // z naší číselné řady. U PŘIJATÉ faktury je `varsymbol` číslo DODAVATELE; vnucovat
            // ho do naší řady (navíc numberRequested má checkDuplicity=true → import spadne na
            // duplicitě a u nečíselného čísla je to i špatný typ) je chyba — necháme Pohodu
            // přidělit interní číslo z agendy přijatých faktur (element vynecháme).
            if (!$isPurchase && $vs !== '') {
                $num = $dom->createElementNS(self::NS_INV, 'inv:number');
                $this->el($dom, $num, self::NS_TYP, 'typ:numberRequested', $vs);
                $hdr->appendChild($num);
            }

            // Variabilní symbol je platební pole Pohody → musí být číselný (max 10). `varsymbol`
            // může nést nečíselné znaky (číslo dokladu dodavatele i naše řada `2026-00001`),
            // proto normalizujeme stejně jako pro banku/QR. Prázdný symVar neemitujeme.
            $symVar = VariableSymbolNormalizer::forPayment($vs);
            if ($symVar !== '') {
                $this->el($dom, $hdr, self::NS_INV, 'inv:symVar', $symVar);
            }
            $this->el($dom, $hdr, self::NS_INV, 'inv:date', (string) $invoice['issue_date']);
            if (!empty($invoice['tax_date'])) {
                $this->el($dom, $hdr, self::NS_INV, 'inv:dateTax', (string) $invoice['tax_date']);
                $this->el($dom, $hdr, self::NS_INV, 'inv:dateAccounting', (string) $invoice['tax_date']);
            }
            $this->el($dom, $hdr, self::NS_INV, 'inv:dateDue', (string) $invoice['due_date']);

            // Klasifikace DPH (per-faktura — vezme se nejvyšší VAT rate z položek; mix se v praxi
            // řeší per-položka v invoiceItem). Pohoda vyžaduje strukturované dítě, ne prostý text.
            // `typ:ids` jsou členění DPH kódy Pohody (UDA5 = USKUTEČNĚNÉ/výstupní plnění) —
            // platí pro VYDANÉ. U PŘIJATÝCH faktur (vstupní DPH / nárok na odpočet) by výstupní
            // kód byl chybný směr a navíc je členění specifické pro konkrétní instalaci Pohody,
            // proto kód neposíláme a necháme Pohodu doplnit správné členění pro agendu
            // receivedInvoice; uvádíme jen typ (inland/nonSubsume).
            // U zálohové/proforma faktury se classificationVAT dle schématu nepoužívá → vynecháme.
            if (($invoice['invoice_type'] ?? '') !== 'proforma') {
                $defaultVatClass = $this->classifyVat($invoice);
                $classEl = $dom->createElementNS(self::NS_INV, 'inv:classificationVAT');
                if (!$isPurchase) {
                    $this->el($dom, $classEl, self::NS_TYP, 'typ:ids', $defaultVatClass['ids']);
                }
                $this->el($dom, $classEl, self::NS_TYP, 'typ:classificationVATType', $defaultVatClass['type']);
                $hdr->appendChild($classEl);
            }

            // Číslo objednávky / poznámka
            if (!empty($invoice['note_above_items'])) {
                $this->el($dom, $hdr, self::NS_INV, 'inv:text', mb_substr((string) $invoice['note_above_items'], 0, 240));
            } else {
                $this->el($dom, $hdr, self::NS_INV, 'inv:text', 'Faktura ' . ($invoice['varsymbol'] ?? ''));
            }

            // Per-faktura číslo zakázky (z projektu) — přepíše se po reimportu zpět na project_number
            if (!empty($invoice['project_number'])) {
                $this->el($dom, $hdr, self::NS_INV, 'inv:numberOrder', (string) $invoice['project_number']);
            }

            // Účet / středisko / činnost / zakázka (per-supplier)
            if (!empty($cfg['pohoda_account_code'])) {
                $this->codeRef($dom, $hdr, 'inv:account', (string) $cfg['pohoda_account_code']);
            }
            if (!empty($cfg['pohoda_centre_code'])) {
                $this->codeRef($dom, $hdr, 'inv:centre', (string) $cfg['pohoda_centre_code']);
            }
            if (!empty($cfg['pohoda_activity_code'])) {
                $this->codeRef($dom, $hdr, 'inv:activity', (string) $cfg['pohoda_activity_code']);
            }
            if (!empty($cfg['pohoda_contract_code'])) {
                $this->codeRef($dom, $hdr, 'inv:contract', (string) $cfg['pohoda_contract_code']);
            }

            // Obchodní partner (partnerIdentity): u vydané faktury odběratel (client),
            // u přijaté faktury dodavatel (vendor ze supplier_snapshot). Pohoda do
            // partnerIdentity vždy plní protistranu dokladu.
            $client = $isPurchase ? $this->resolveSupplier($invoice) : $this->resolveClient($invoice);
            $partner = $dom->createElementNS(self::NS_INV, 'inv:partnerIdentity');
            $address = $dom->createElementNS(self::NS_TYP, 'typ:address');
            $this->el($dom, $address, self::NS_TYP, 'typ:company', $this->addr($client['company_name'] ?? '', 'company'));
            if (!empty($client['ic']))  $this->el($dom, $address, self::NS_TYP, 'typ:ico', (string) $client['ic']);
            if (!empty($client['dic'])) $this->el($dom, $address, self::NS_TYP, 'typ:dic', (string) $client['dic']);
            $this->el($dom, $address, self::NS_TYP, 'typ:street', $this->addr($client['street'] ?? '', 'street'));
            $this->el($dom, $address, self::NS_TYP, 'typ:city',   $this->addr($client['city'] ?? '', 'city'));
            $this->el($dom, $address, self::NS_TYP, 'typ:zip',    $this->addr($client['zip'] ?? '', 'zip'));
            if (!empty($client['country_iso2'])) {
                $country = $dom->createElementNS(self::NS_TYP, 'typ:country');
                $this->el($dom, $country, self::NS_TYP, 'typ:ids', (string) $client['country_iso2']);
                $address->appendChild($country);
            }
            $email = $client['email'] ?? $client['main_email'] ?? null;
            if ($email) $this->el($dom, $address, self::NS_TYP, 'typ:email', $this->addr($email, 'email'));
            if (!empty($client['phone'])) $this->el($dom, $address, self::NS_TYP, 'typ:phone', $this->addr($client['phone'], 'phone'));
            $partner->appendChild($address);
            $hdr->appendChild($partner);

            // Reverse charge (přenesená daň. povinnost) je vyjádřen přes <inv:classificationVAT>
            // = PNAR (viz classifyVat). <inv:isExecuted> se zde NEPOUŽÍVÁ — je to posting
            // příznak („zlikvidováno"), ne reverse charge (issue #41).

            $inv->appendChild($hdr);

            // Foreign currency? Pak summary musí mít jak homeCurrency (CZK z czk_recap),
            // tak foreignCurrency (EUR + kurz). Položky pro foreign-currency fakturu jdou
            // do inv:foreignCurrency (Pohoda si CZK dopočítá z global kurzu).
            $invCurrency = (string) ($invoice['currency'] ?? 'CZK');
            $isForeign = $invCurrency !== 'CZK';
            $exchangeRate = $isForeign ? (float) ($invoice['exchange_rate'] ?? 1.0) : 1.0;

            // Detail (položky)
            $detail = $dom->createElementNS(self::NS_INV, 'inv:invoiceDetail');
            foreach ($invoice['items'] ?? [] as $item) {
                $row = $dom->createElementNS(self::NS_INV, 'inv:invoiceItem');
                // Pohoda invoice.xsd omezuje text položky na 90 znaků (facet maxLength) —
                // delší popisy ořízneme, jinak XSD validace spadne (mb_substr kvůli diakritice).
                $this->el($dom, $row, self::NS_INV, 'inv:text', mb_substr((string) ($item['description'] ?? ''), 0, 90));
                $this->el($dom, $row, self::NS_INV, 'inv:quantity', $this->fmt((float) $item['quantity']));
                $this->el($dom, $row, self::NS_INV, 'inv:unit', (string) ($item['unit'] ?? 'ks'));
                // CoefficientOfRefundables (1 = celé)
                $this->el($dom, $row, self::NS_INV, 'inv:coefficient', '1.0');
                // payVAT: false = pricing without VAT in unit price (default)
                $this->el($dom, $row, self::NS_INV, 'inv:payVAT', 'false');
                // Sazba DPH
                $rate = (float) ($item['vat_rate_snapshot'] ?? 0);
                $rateCode = match (true) {
                    $rate >= $this->highBoundary($invoice) => 'high',
                    $rate >= 11.5 => 'low',
                    $rate >= 9.5  => 'low2',  // 10% (Pohoda historic)
                    default       => 'none',
                };
                $vatRate = $dom->createElementNS(self::NS_INV, 'inv:rateVAT');
                $vatRate->appendChild($dom->createTextNode($rateCode));
                $row->appendChild($vatRate);

                // CZK invoice → homeCurrency; foreign → foreignCurrency (s EUR cenami,
                // Pohoda dopočítá CZK z kurzu uvedeného v summary)
                $blockName = $isForeign ? 'inv:foreignCurrency' : 'inv:homeCurrency';
                $block = $dom->createElementNS(self::NS_INV, $blockName);
                // payVAT=false → unitPrice musí být BEZ DPH. V režimu „ceny s DPH" nese
                // unit_price_without_vat brutto, proto dopočítáme netto z řádkového základu.
                $qtyItem = (float) $item['quantity'];
                $unitPriceNet = (!empty($invoice['prices_include_vat']) && $qtyItem != 0.0)
                    ? round(((float) ($item['total_without_vat'] ?? 0)) / $qtyItem, 2)
                    : (float) $item['unit_price_without_vat'];
                $this->el($dom, $block, self::NS_TYP, 'typ:unitPrice', $this->fmt($unitPriceNet));
                $this->el($dom, $block, self::NS_TYP, 'typ:price',     $this->fmt((float) ($item['total_without_vat'] ?? 0)));
                $this->el($dom, $block, self::NS_TYP, 'typ:priceVAT',  $this->fmt((float) ($item['total_vat'] ?? 0)));
                $this->el($dom, $block, self::NS_TYP, 'typ:priceSum',  $this->fmt((float) ($item['total_with_vat'] ?? 0)));
                $row->appendChild($block);

                $detail->appendChild($row);
            }
            $inv->appendChild($detail);

            // Summary
            $sum = $dom->createElementNS(self::NS_INV, 'inv:invoiceSummary');
            $this->el($dom, $sum, self::NS_INV, 'inv:roundingDocument', 'none');
            $this->el($dom, $sum, self::NS_INV, 'inv:roundingVAT', 'none');

            $totals = $invoice['totals'] ?? [];
            $bd = $invoice['vat_breakdown'] ?? [];

            // homeCurrency = VŽDY v CZK. Pro CZK fakturu z totals/vat_breakdown, pro
            // foreign fakturu primárně z czk_recap (přepočet ČNB kurzem po sazbách).
            // Když czk_recap chybí (typicky přijaté faktury), přepočteme buckety z měny
            // dokladu na CZK kurzem — jinak by homeCurrency nesla cizoměnové částky
            // označené jako CZK. (Pohoda u cizoměnového dokladu CZK stranu sice ignoruje
            // a dopočítá z foreignCurrency × kurz, ale nesmí tam být chybná měna.)
            $homeCurrency = $dom->createElementNS(self::NS_INV, 'inv:homeCurrency');
            if ($isForeign && !empty($invoice['czk_recap'])) {
                $homeBuckets = $this->bucketsFromCzkRecap($invoice['czk_recap'], $this->highBoundary($invoice));
            } else {
                $homeBuckets = $this->bucketsFromBreakdown($bd, $this->highBoundary($invoice));
                if ($isForeign && $exchangeRate > 0.0) {
                    foreach ($homeBuckets as $k => $v) {
                        $homeBuckets[$k] = $v * $exchangeRate;
                    }
                }
            }

            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:priceNone',    $this->fmt($homeBuckets['none']));
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:priceLow',     $this->fmt($homeBuckets['low']));
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:priceLowVAT',  $this->fmt($homeBuckets['lowVat']));
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:priceLowSum',  $this->fmt($homeBuckets['low'] + $homeBuckets['lowVat']));
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:priceHigh',    $this->fmt($homeBuckets['high']));
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:priceHighVAT', $this->fmt($homeBuckets['highVat']));
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:priceHighSum', $this->fmt($homeBuckets['high'] + $homeBuckets['highVat']));
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:price3', '0.00');
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:price3VAT', '0.00');
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:price3Sum', '0.00');
            // `round` je typ:typeRound = xsd:choice → musí obalit <typ:priceRound>, ne nést
            // prostou hodnotu. Emitujeme jen u CZK dokladu s reálným zaokrouhlením.
            // POZOR: `typeCurrencyHome` NEMÁ `priceSum` — celkovou částku si Pohoda dopočítá
            // z bucketů + round (dřív tu byl neplatný <typ:priceSum>).
            $rounding = (float) ($totals['rounding'] ?? 0);
            if (!$isForeign && $rounding !== 0.0) {
                $roundWrap = $dom->createElementNS(self::NS_TYP, 'typ:round');
                $this->el($dom, $roundWrap, self::NS_TYP, 'typ:priceRound', $this->fmt($rounding));
                $homeCurrency->appendChild($roundWrap);
            }
            $sum->appendChild($homeCurrency);

            // foreignCurrency — jen pro non-CZK faktury. Obsahuje měnu, kurz, množství
            // a totals v cizí měně. Pohoda po importu má jak CZK účetní hodnoty
            // (homeCurrency), tak originál v cizí měně (foreignCurrency).
            if ($isForeign) {
                // `typeCurrencyForeign` (XSD) povoluje JEN: currency, rate, amount, priceSum, round.
                // Per-sazbové buckety (priceNone/priceLow/…) sem NEpatří — ty jsou pouze v homeCurrency
                // (CZK účetní hodnoty). Cizoměnový doklad nese jen celkovou částku v priceSum.
                $foreign = $dom->createElementNS(self::NS_INV, 'inv:foreignCurrency');
                $cur = $dom->createElementNS(self::NS_TYP, 'typ:currency');
                $this->el($dom, $cur, self::NS_TYP, 'typ:ids', $invCurrency);
                $foreign->appendChild($cur);
                $this->el($dom, $foreign, self::NS_TYP, 'typ:rate', number_format($exchangeRate, 6, '.', ''));
                $this->el($dom, $foreign, self::NS_TYP, 'typ:amount', '1');
                $this->el($dom, $foreign, self::NS_TYP, 'typ:priceSum', $this->fmt((float) ($totals['with_vat'] ?? 0)));
                $sum->appendChild($foreign);
            }

            $inv->appendChild($sum);
        }

        return (string) $dom->saveXML();
    }

    /** <inv:account><typ:ids>CODE</typ:ids></inv:account> */
    private function codeRef(\DOMDocument $dom, \DOMElement $parent, string $name, string $code): void
    {
        $wrap = $dom->createElementNS(self::NS_INV, $name);
        $this->el($dom, $wrap, self::NS_TYP, 'typ:ids', $code);
        $parent->appendChild($wrap);
    }

    /** Ořízne adresní pole na délkový facet z type.xsd (viz self::ADDR_MAX). */
    private function addr(mixed $value, string $field): string
    {
        return mb_substr(trim((string) $value), 0, self::ADDR_MAX[$field]);
    }

    private function el(\DOMDocument $dom, \DOMElement $parent, string $ns, string $name, string $value): \DOMElement
    {
        $el = $dom->createElementNS($ns, $name);
        $el->appendChild($dom->createTextNode($value));
        $parent->appendChild($el);
        return $el;
    }

    /**
     * @return array{ids:string, type:string}  ids = zkratka členění v Pohodě, type = enum {inland, nonSubsume}
     */
    private function classifyVat(array $invoice): array
    {
        if (!empty($invoice['reverse_charge'])) {
            return ['ids' => 'PNAR', 'type' => 'nonSubsume'];
        }
        $bd = $invoice['vat_breakdown'] ?? [];
        if (empty($bd)) {
            return ['ids' => 'UDA5', 'type' => 'inland'];
        }
        $maxRate = 0.0;
        foreach ($bd as $b) {
            if ((float) $b['rate'] > $maxRate) $maxRate = (float) $b['rate'];
        }
        if ($maxRate >= $this->highBoundary($invoice)) return ['ids' => 'UDA5',    'type' => 'inland'];
        if ($maxRate >= 11.5)                          return ['ids' => 'UDA5_12', 'type' => 'inland'];
        return ['ids' => 'UNX', 'type' => 'nonSubsume'];
    }

    private function resolveClient(array $invoice): array
    {
        return $this->dataResolver->client($invoice);
    }

    private function resolveSupplier(array $invoice): array
    {
        return $this->dataResolver->supplier($invoice);
    }

    private function fmt(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * @param list<array{rate: float, base: float, vat: float}> $breakdown
     * @return array{none: float, low: float, lowVat: float, high: float, highVat: float}
     */
    private function bucketsFromBreakdown(array $breakdown, float $highBoundary): array
    {
        $out = ['none' => 0.0, 'low' => 0.0, 'lowVat' => 0.0, 'high' => 0.0, 'highVat' => 0.0];
        foreach ($breakdown as $b) {
            $r = (float) $b['rate'];
            if ($r >= $highBoundary) {
                $out['high']    += (float) $b['base'];
                $out['highVat'] += (float) $b['vat'];
            } elseif ($r >= 11.5) {
                $out['low']    += (float) $b['base'];
                $out['lowVat'] += (float) $b['vat'];
            } else {
                $out['none'] += (float) $b['base'];
            }
        }
        return $out;
    }

    /**
     * @param array{breakdown: list<array{rate: float, base_czk: float, vat_czk: float}>} $recap
     * @return array{none: float, low: float, lowVat: float, high: float, highVat: float}
     */
    private function bucketsFromCzkRecap(array $recap, float $highBoundary): array
    {
        $out = ['none' => 0.0, 'low' => 0.0, 'lowVat' => 0.0, 'high' => 0.0, 'highVat' => 0.0];
        foreach ($recap['breakdown'] ?? [] as $b) {
            $r = (float) $b['rate'];
            if ($r >= $highBoundary) {
                $out['high']    += (float) $b['base_czk'];
                $out['highVat'] += (float) $b['vat_czk'];
            } elseif ($r >= 11.5) {
                $out['low']    += (float) $b['base_czk'];
                $out['lowVat'] += (float) $b['vat_czk'];
            } else {
                $out['none'] += (float) $b['base_czk'];
            }
        }
        return $out;
    }
}
