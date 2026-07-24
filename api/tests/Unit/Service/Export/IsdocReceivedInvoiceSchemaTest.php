<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Export\IsdocExporter;
use PHPUnit\Framework\TestCase;

/**
 * Ověřuje ISDOC export PŘIJATÉ faktury (PurchaseInvoiceExportService = role-inversion
 * adapter): IsdocExporter::buildXml() se volá nad invoice-shaped polem, kde
 * supplier_snapshot = DODAVATEL (vendor) a client_snapshot = MY (příjemce).
 *
 * ISDOC nemá doc-level „směr" — popisuje AccountingSupplierParty / AccountingCustomerParty,
 * takže inverze rolí je sémanticky správná: dodavatel = supplier, my = customer.
 *
 * Pokrývá i regresi: shape přijaté faktury musí nést vat_breakdown/totals, jinak by
 * TaxTotal i LegalMonetaryTotal vyšly nulové.
 */
final class IsdocReceivedInvoiceSchemaTest extends TestCase
{
    private const XSD = __DIR__ . '/../../../../xsd/isdoc-invoice-6.0.2.xsd';

    private IsdocExporter $exporter;

    protected function setUp(): void
    {
        if (!is_file(self::XSD)) {
            self::markTestSkipped('ISDOC XSD chybí — spusť cmd/download-xsd.sh isdoc.');
        }
        $this->exporter = new IsdocExporter(
            $this->createStub(InvoiceRepository::class),
            $this->createStub(Connection::class),
        );
    }

    public function testReceivedInvoiceIsSchemaValid(): void
    {
        $this->assertValidIsdoc($this->exporter->buildXml($this->receivedInvoice()));
    }

    public function testRolesAreInverted(): void
    {
        $xml = $this->exporter->buildXml($this->receivedInvoice());

        // Dodavatel (vendor) = AccountingSupplierParty.
        self::assertSame('Dodavatel s.r.o.', $this->xpathOne(
            $xml, '//i:AccountingSupplierParty/i:Party/i:PartyName/i:Name'));
        self::assertSame('11122233', $this->xpathOne(
            $xml, '//i:AccountingSupplierParty/i:Party/i:PartyIdentification/i:ID'));

        // My (příjemce) = AccountingCustomerParty.
        self::assertSame('Naše firma s.r.o.', $this->xpathOne(
            $xml, '//i:AccountingCustomerParty/i:Party/i:PartyName/i:Name'));
        self::assertSame('01698401', $this->xpathOne(
            $xml, '//i:AccountingCustomerParty/i:Party/i:PartyIdentification/i:ID'));
    }

    public function testRecapAndTotalsAreNotZero(): void
    {
        // Regrese: bez forwardu vat_breakdown/totals by tu byly nuly.
        $xml = $this->exporter->buildXml($this->receivedInvoice());

        self::assertSame('529.20', $this->xpathOne($xml, '//i:TaxTotal/i:TaxAmount'));
        self::assertSame('2520.00', $this->xpathOne($xml, '//i:TaxTotal/i:TaxSubTotal/i:TaxableAmount'));
        self::assertSame('2520.00', $this->xpathOne($xml, '//i:LegalMonetaryTotal/i:TaxExclusiveAmount'));
        self::assertSame('3049.20', $this->xpathOne($xml, '//i:LegalMonetaryTotal/i:TaxInclusiveAmount'));
    }

    public function testPaymentVariableSymbolIsExportedSeparatelyFromDocumentId(): void
    {
        $xml = $this->exporter->buildXml($this->receivedInvoice([
            'varsymbol'                => 'PF2606001',
            'document_number'          => 'FV-2026-001',
            'internal_document_number' => 'PF2606001',
            'payment_variable_symbol'  => '2026001234',
            'payment_constant_symbol'  => '0558',
            'bank_snapshot'            => [
                'account_number' => '1000000005',
                'bank_code'      => '0100',
                'bank_name'      => 'Komerční banka',
            ],
        ]));

        $this->assertValidIsdoc($xml);
        self::assertSame('FV-2026-001', $this->xpathOne($xml, '//i:Invoice/i:ID'));
        self::assertSame('PF2606001', $this->xpathOne(
            $xml, '//i:Extensions/myi:InternalDocumentNumber'));
        self::assertSame('2026001234', $this->xpathOne(
            $xml, '//i:PaymentMeans/i:Payment/i:Details/i:VariableSymbol'));
        self::assertSame('0558', $this->xpathOne(
            $xml, '//i:PaymentMeans/i:Payment/i:Details/i:ConstantSymbol'));
    }

    public function testPaymentVariableSymbolRemainsInPaidInvoice(): void
    {
        $xml = $this->exporter->buildXml($this->receivedInvoice([
            'amount_to_pay'             => 0.0,
            'payment_variable_symbol'   => '2026001234',
            'bank_snapshot'             => [
                'account_number' => '1000000005',
                'bank_code'      => '0100',
            ],
        ]));

        $this->assertValidIsdoc($xml);
        self::assertSame('2026001234', $this->xpathOne(
            $xml, '//i:PaymentMeans/i:Payment/i:Details/i:VariableSymbol'));
    }

    public function testReceivedCreditNoteIsSchemaValid(): void
    {
        $this->assertValidIsdoc($this->exporter->buildXml($this->receivedInvoice(['invoice_type' => 'credit_note'])));
    }

    // ─── Helpers ───

    private function xpathOne(string $xml, string $expr): ?string
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('i', 'http://isdoc.cz/namespace/2013');
        $xp->registerNamespace('myi', IsdocExporter::MYINVOICE_EXTENSION_NS);
        return $xp->query($expr)->item(0)?->textContent;
    }

    private function assertValidIsdoc(string $xml): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'Export není well-formed XML.');

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = $dom->schemaValidate(self::XSD);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok) {
            $lines = array_map(
                static fn (\LibXMLError $e): string => sprintf('  [ř. %d] %s', $e->line, trim($e->message)),
                $errors,
            );
            self::fail("ISDOC XML není validní vůči isdoc-invoice-6.0.2.xsd:\n" . implode("\n", $lines));
        }
        self::assertTrue($ok);
    }

    /**
     * Invoice-shape přijaté faktury — zrcadlí výstup
     * PurchaseInvoiceExportService::buildInvoiceShape (invertované role + recap/totals).
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function receivedInvoice(array $overrides = []): array
    {
        $base = [
            'id'                 => 1,
            'invoice_type'       => 'invoice',
            'varsymbol'          => '2026-VF-1',
            'document_number'    => 'FV-2026-001',
            'internal_document_number' => '2026-VF-1',
            'issue_date'         => '2026-06-03',
            'tax_date'           => '2026-06-03',
            'due_date'           => '2026-06-17',
            'currency'           => 'CZK',
            'exchange_rate'      => null,
            'reverse_charge'     => false,
            'prices_include_vat' => false,
            'project_number'     => null,
            'contract_number'    => null,
            'advance_paid_amount' => 0.0,
            'amount_to_pay'      => 3049.2,
            // supplier_snapshot = dodavatel (vendor), client_snapshot = náš tenant
            'supplier_snapshot'  => [
                'ic' => '11122233', 'dic' => 'CZ11122233', 'company_name' => 'Dodavatel s.r.o.',
                'street' => 'Dlouhá 5', 'city' => 'Brno', 'zip' => '60200',
                'country_iso2' => 'CZ', 'main_email' => 'fakturace@dodavatel.cz',
            ],
            'client_snapshot'    => [
                'ic' => '01698401', 'dic' => 'CZ01698401', 'company_name' => 'Naše firma s.r.o.',
                'street' => 'Kardinála Berana 1104/36', 'city' => 'Plzeň', 'zip' => '30100', 'country_iso2' => 'CZ',
            ],
            'items'              => [[
                'description'            => 'Vývoj systému',
                'quantity'               => 1.0,
                'unit'                   => 'ks',
                'unit_price_without_vat' => 2520.0,
                'vat_rate_snapshot'      => 21.0,
                'total_without_vat'      => 2520.0,
                'total_vat'              => 529.2,
                'total_with_vat'         => 3049.2,
            ]],
            'vat_breakdown'      => [['rate' => 21.0, 'base' => 2520.0, 'vat' => 529.2]],
            'totals'             => ['without_vat' => 2520.0, 'with_vat' => 3049.2, 'rounding' => 0.0],
        ];

        return array_merge($base, $overrides);
    }
}
