<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use Mpdf\Mpdf;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Export\MergedInvoicePdfExporter;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Signing\Pdf\PdfSigningService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class MergedInvoicePdfExporterTest extends TestCase
{
    private InvoiceRepository&MockObject $invoices;
    private InvoicePdfRenderer&MockObject $renderer;
    private ExchangeRateApplier&MockObject $rateApplier;
    private PdfSigningService&MockObject $signing;

    protected function setUp(): void
    {
        $this->invoices = $this->createMock(InvoiceRepository::class);
        $this->renderer = $this->createMock(InvoicePdfRenderer::class);
        $this->rateApplier = $this->createMock(ExchangeRateApplier::class);
        $this->signing = $this->createMock(PdfSigningService::class);
    }

    public function testMergesInvoicesInGivenOrderWithoutSigning(): void
    {
        $renderedIds = [];
        $this->invoices->method('find')->willReturnCallback(
            static fn (int $id): array => [
                'id' => $id,
                'supplier_id' => 7,
                'currency' => 'CZK',
                'exchange_rate' => null,
            ],
        );
        $this->renderer->expects(self::exactly(2))
            ->method('renderUnsignedInvoiceOnly')
            ->willReturnCallback(static function (int $id, string $path) use (&$renderedIds): void {
                $renderedIds[] = $id;
                $pdf = new Mpdf(['tempDir' => sys_get_temp_dir(), 'default_font' => 'dejavusans']);
                $pdf->WriteHTML("<p style=\"font-family:dejavusans\">Invoice {$id}</p>");
                $pdf->Output($path, \Mpdf\Output\Destination::FILE);
            });
        $this->rateApplier->expects(self::never())->method('ensureRate');
        $this->signing->expects(self::never())->method('willSignDocument');
        $this->signing->expects(self::never())->method('signSupplierPdfIfEnabled');

        $result = $this->exporter()->export([12, 11], ['id' => 7], 3, false);
        try {
            self::assertFalse($result['signed']);
            self::assertSame([12, 11], $renderedIds);
            self::assertFileExists($result['path']);
            self::assertSame('%PDF', substr((string) file_get_contents($result['path']), 0, 4));

            $reader = new Mpdf(['tempDir' => sys_get_temp_dir(), 'default_font' => 'dejavusans']);
            self::assertSame(2, $reader->setSourceFile($result['path']));
        } finally {
            @unlink($result['path']);
        }
    }

    public function testRejectsInvoiceFromAnotherSupplier(): void
    {
        $this->invoices->method('find')->willReturn([
            'id' => 12,
            'supplier_id' => 99,
            'currency' => 'CZK',
        ]);
        $this->renderer->expects(self::never())->method('renderUnsignedInvoiceOnly');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nebyla nalezena');
        $this->exporter()->export([12], ['id' => 7], 3, false);
    }

    public function testSigningMustBeAvailableBeforeRendering(): void
    {
        $this->signing->expects(self::once())
            ->method('willSignDocument')
            ->with(['id' => 7], 'bulk_invoice_export', 0, 3)
            ->willReturn(false);
        $this->invoices->expects(self::never())->method('find');
        $this->renderer->expects(self::never())->method('renderUnsignedInvoiceOnly');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nelze podepsat');
        $this->exporter()->export([12], ['id' => 7], 3, true);
    }

    public function testSignsOnlyTheMergedResult(): void
    {
        $supplier = ['id' => 7];
        $this->invoices->method('find')->willReturn([
            'id' => 12,
            'supplier_id' => 7,
            'currency' => 'CZK',
            'exchange_rate' => null,
        ]);
        $this->renderer->expects(self::once())
            ->method('renderUnsignedInvoiceOnly')
            ->willReturnCallback(static function (int $id, string $path): void {
                $pdf = new Mpdf(['tempDir' => sys_get_temp_dir(), 'default_font' => 'dejavusans']);
                $pdf->WriteHTML("<p style=\"font-family:dejavusans\">Invoice {$id}</p>");
                $pdf->Output($path, \Mpdf\Output\Destination::FILE);
            });
        $this->signing->expects(self::once())
            ->method('willSignDocument')
            ->with($supplier, 'bulk_invoice_export', 0, 3)
            ->willReturn(true);
        $this->signing->expects(self::once())
            ->method('signSupplierPdfIfEnabled')
            ->willReturnCallback(static function (string $path): string {
                $signed = $path . '.signed';
                copy($path, $signed);
                @unlink($path);
                return $signed;
            });

        $result = $this->exporter()->export([12], $supplier, 3, true);
        try {
            self::assertTrue($result['signed']);
            self::assertStringEndsWith('.signed', $result['path']);
            self::assertFileExists($result['path']);
        } finally {
            @unlink($result['path']);
        }
    }

    private function exporter(): MergedInvoicePdfExporter
    {
        return new MergedInvoicePdfExporter(
            $this->invoices,
            $this->renderer,
            $this->rateApplier,
            $this->signing,
        );
    }
}
