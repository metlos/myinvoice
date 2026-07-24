<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Oss;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Oss\OssLedgerService;
use MyInvoice\Service\Oss\OssXmlExporter;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\TestCase;

final class OssXmlExporterTest extends TestCase
{
    public function testOrdinaryRowsAndCorrectionsPassOfficialSchema(): void
    {
        $result = $this->exporter($this->preview())->build(1, 2026, 3);

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($result['xml']));
        $xpath = new \DOMXPath($dom);

        self::assertSame('160.00', $xpath->evaluate('string(/Pisemnost/OSSEI1/VetaR/@taxable_amount)'));
        self::assertSame('36.80', $xpath->evaluate('string(/Pisemnost/OSSEI1/VetaR/@vat_amount)'));
        self::assertSame('-12.34', $xpath->evaluate('string(/Pisemnost/OSSEI1/VetaO/@correction)'));
        self::assertSame('2', $xpath->evaluate('string(/Pisemnost/OSSEI1/VetaO/@quarter)'));
        self::assertSame('', $xpath->evaluate('string(/Pisemnost/OSSEI1/VetaD/@period_start_date)'));
        self::assertSame('passed', (new XmlSchemaValidator())->validate($result['xml'], 'ossei1')['status']);
    }

    public function testInvalidCorrectionPeriodBlocksExport(): void
    {
        $preview = $this->preview();
        $preview['summary']['invalid_correction_count'] = 1;

        $this->expectException(\RuntimeException::class);
        $this->exporter($preview)->build(1, 2026, 3);
    }

    public function testMissingSupplyTypeBlocksExport(): void
    {
        $preview = $this->preview();
        $preview['countries'][0]['rows'][0]['supply_type'] = null;

        $this->expectException(\RuntimeException::class);
        $this->exporter($preview)->build(1, 2026, 3);
    }

    public function testMissingCurrencyConversionBlocksExport(): void
    {
        $preview = $this->preview();
        $preview['summary']['conversion_missing_count'] = 1;

        $this->expectException(\RuntimeException::class);
        $this->exporter($preview)->build(1, 2026, 3);
    }

    public function testArchivedSummaryUsesExportedAmounts(): void
    {
        $preview = $this->preview();
        $preview['summary']['total_base'] = 999.0;
        $preview['summary']['total_vat'] = 999.0;

        $result = $this->exporter($preview)->build(1, 2026, 3);

        self::assertSame(160.0, $result['summary']['total_base']);
        self::assertSame(36.8, $result['summary']['total_vat']);
        self::assertSame(24.46, $result['summary']['total_payable']);
    }

    /** @param array<string,mixed> $preview */
    private function exporter(array $preview): OssXmlExporter
    {
        $supplierStatement = $this->createStub(\PDOStatement::class);
        $supplierStatement->method('execute')->willReturn(true);
        $supplierStatement->method('fetch')->willReturn([
            'id' => 1,
            'company_name' => 'Test Supplier s.r.o.',
            'dic' => 'CZ12345678',
            'country_iso2' => 'CZ',
        ]);

        $bankStatement = $this->createStub(\PDOStatement::class);
        $bankStatement->method('execute')->willReturn(true);
        $bankStatement->method('fetch')->willReturn([
            'iban' => 'CZ1801000000001000000005',
            'bic' => null,
        ]);

        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            static fn (string $sql): \PDOStatement => str_contains($sql, 'FROM supplier')
                ? $supplierStatement
                : $bankStatement
        );

        $connection = $this->createStub(Connection::class);
        $connection->method('pdo')->willReturn($pdo);

        $ledger = $this->createStub(OssLedgerService::class);
        $ledger->method('preview')->willReturn($preview);

        return new OssXmlExporter($connection, $ledger);
    }

    /** @return array<string,mixed> */
    private function preview(): array
    {
        return [
            'period' => [
                'year' => 2026,
                'quarter' => 3,
                'start' => '2026-07-01',
                'end' => '2026-09-30',
                'submission_deadline' => '2026-10-31',
            ],
            'settings' => [
                'oss_enabled' => true,
                'oss_valid_from' => '2026-01-01',
                'oss_valid_to' => null,
                'oss_return_currency' => 'EUR',
            ],
            'summary' => [
                'return_currency' => 'EUR',
                'total_base' => 160.0,
                'total_vat' => 36.8,
                'total_corrections' => -12.34,
                'total_payable' => 24.46,
                'invoice_count' => 2,
                'invalid_correction_count' => 0,
            ],
            'countries' => [[
                'country' => 'SK',
                'rows' => [[
                    'oss_consumer_country' => 'SK',
                    'supply_type' => 'services',
                    'rate_type' => 'standard',
                    'vat_rate' => 23.0,
                    'base_return' => 160.0,
                    'vat_return' => 36.8,
                ]],
            ]],
            'corrections' => [[
                'year' => 2026,
                'quarter' => 2,
                'state_consumption' => 'SK',
                'correction' => -12.34,
            ]],
            'warnings' => [],
        ];
    }
}
