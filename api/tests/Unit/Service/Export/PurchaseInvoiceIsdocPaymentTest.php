<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Export\PohodaXmlExporter;
use MyInvoice\Service\Export\PurchaseInvoiceExportService;
use PHPUnit\Framework\TestCase;

/**
 * Regrese pro mapování platebních údajů z purchase_invoices do ISDOC.
 */
final class PurchaseInvoiceIsdocPaymentTest extends TestCase
{
    private const XSD = __DIR__ . '/../../../../xsd/isdoc-invoice-6.0.2.xsd';

    public function testPaymentVariableSymbolAndBankAccountAreExported(): void
    {
        $xml = $this->export([
            'amount_to_pay'            => 0.0,
            'payment_account_number'   => '1000000005',
            'payment_bank_code'        => '0100',
            'payment_variable_symbol'  => '0001234567',
            'payment_constant_symbol'  => '0558',
        ]);

        $this->assertValidIsdoc($xml);
        self::assertSame('FV-2026-001', $this->xpathOne($xml, '//i:Invoice/i:ID'));
        self::assertSame('PF2607001', $this->xpathOne(
            $xml, '//i:Extensions/myi:InternalDocumentNumber'));
        self::assertSame('1000000005', $this->xpathOne(
            $xml, '//i:PaymentMeans/i:Payment/i:Details/i:ID'));
        self::assertSame('0100', $this->xpathOne(
            $xml, '//i:PaymentMeans/i:Payment/i:Details/i:BankCode'));
        self::assertSame('0001234567', $this->xpathOne(
            $xml, '//i:PaymentMeans/i:Payment/i:Details/i:VariableSymbol'));
        self::assertSame('0558', $this->xpathOne(
            $xml, '//i:PaymentMeans/i:Payment/i:Details/i:ConstantSymbol'));
    }

    public function testVendorInvoiceNumberIsVariableSymbolFallback(): void
    {
        $xml = $this->export([
            'vendor_invoice_number'    => 'FV 2026/77',
            'payment_variable_symbol'  => null,
            'payment_account_number'   => null,
            'payment_bank_code'        => null,
        ]);

        $this->assertValidIsdoc($xml);
        self::assertSame('FV 2026/77', $this->xpathOne($xml, '//i:Invoice/i:ID'));
        self::assertSame('202677', $this->xpathOne(
            $xml, '//i:PaymentMeans/i:Payment/i:Details/i:VariableSymbol'));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function export(array $overrides): string
    {
        $purchaseInvoice = array_merge([
            'id'                       => 42,
            'vendor_id'                => 8,
            'document_kind'            => 'invoice',
            'varsymbol'                => 'PF2607001',
            'vendor_invoice_number'    => 'FV-2026-001',
            'issue_date'               => '2026-07-01',
            'tax_date'                 => '2026-07-01',
            'due_date'                 => '2026-07-15',
            'currency'                 => 'CZK',
            'exchange_rate'            => null,
            'reverse_charge'           => false,
            'prices_include_vat'       => false,
            'language'                 => 'cs',
            'vendor_snapshot'          => [
                'ic'           => '11122233',
                'dic'          => 'CZ11122233',
                'company_name' => 'Dodavatel s.r.o.',
                'street'       => 'Dlouhá 5',
                'city'         => 'Brno',
                'zip'          => '60200',
                'country_iso2' => 'CZ',
            ],
            'items'                    => [[
                'description'            => 'Vývoj systému',
                'quantity'               => 1.0,
                'unit'                   => 'ks',
                'unit_price_without_vat' => 1000.0,
                'vat_rate_snapshot'      => 21.0,
                'total_without_vat'      => 1000.0,
                'total_vat'              => 210.0,
                'total_with_vat'         => 1210.0,
            ]],
            'vat_breakdown'             => [[
                'vat_rate'    => 21.0,
                'without_vat' => 1000.0,
                'vat'         => 210.0,
                'with_vat'    => 1210.0,
            ]],
            'totals'                    => [
                'without_vat'         => 1000.0,
                'vat'                 => 210.0,
                'with_vat'            => 1210.0,
                'rounding'            => 0.0,
                'advance_paid_amount' => 0.0,
                'amount_to_pay'       => 1210.0,
            ],
            'amount_to_pay'             => 1210.0,
            'advance_paid_amount'       => 0.0,
            'payment_account_number'    => '1000000005',
            'payment_bank_code'         => '0100',
            'payment_iban'              => null,
            'payment_bic'               => null,
            'payment_variable_symbol'   => '2026000001',
            'payment_constant_symbol'   => null,
        ], $overrides);

        $repo = $this->createMock(PurchaseInvoiceRepository::class);
        $repo->expects(self::once())
            ->method('find')
            ->with(42, 7)
            ->willReturn($purchaseInvoice);

        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([7])->willReturn(true);
        $statement->expects(self::once())->method('fetch')->with(\PDO::FETCH_ASSOC)->willReturn([
            'ic'           => '01698401',
            'dic'          => 'CZ01698401',
            'company_name' => 'Naše firma s.r.o.',
            'street'       => 'Kardinála Berana 1104/36',
            'city'         => 'Plzeň',
            'zip'          => '30100',
            'country_iso2' => 'CZ',
        ]);

        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);

        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        $isdoc = new IsdocExporter(
            $this->createStub(InvoiceRepository::class),
            $db,
        );
        $service = new PurchaseInvoiceExportService(
            $db,
            $repo,
            $isdoc,
            $this->createStub(PohodaXmlExporter::class),
        );

        return $service->toIsdocXml(42, 7);
    }

    private function xpathOne(string $xml, string $expression): ?string
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('i', 'http://isdoc.cz/namespace/2013');
        $xpath->registerNamespace('myi', IsdocExporter::MYINVOICE_EXTENSION_NS);

        return $xpath->query($expression)->item(0)?->textContent;
    }

    private function assertValidIsdoc(string $xml): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'Export není well-formed XML.');

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $valid = $dom->schemaValidate(self::XSD);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$valid) {
            self::fail(implode("\n", array_map(
                static fn (\LibXMLError $error): string => trim($error->message),
                $errors,
            )));
        }
        self::assertTrue($valid);
    }
}
