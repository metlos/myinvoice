<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use Mpdf\Mpdf;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use MyInvoice\Service\Signing\Pdf\PdfSigningService;

/** Spojí samotné strany faktur do jednoho volitelně podepsaného PDF. */
final class MergedInvoicePdfExporter
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoicePdfRenderer $renderer,
        private readonly ExchangeRateApplier $rateApplier,
        private readonly PdfSigningService $signing,
    ) {}

    /**
     * @param list<int> $invoiceIds
     * @param array<string,mixed> $supplier
     * @return array{path:string,signed:bool}
     */
    public function export(array $invoiceIds, array $supplier, ?int $userId, bool $sign): array
    {
        if ($invoiceIds === []) {
            throw new \InvalidArgumentException('Není vybrána žádná faktura.');
        }

        $supplierId = (int) ($supplier['id'] ?? 0);
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Dodavatel nebyl nalezen.');
        }
        if ($sign && !$this->signing->willSignDocument(
            $supplier,
            'bulk_invoice_export',
            0,
            $userId,
        )) {
            throw new \DomainException(
                'Výsledné PDF nelze podepsat. Zkontroluj aktivní PDF podpisový profil a jeho certifikát.',
            );
        }

        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Nelze vytvořit dočasný adresář pro PDF export.');
        }

        $sourcePaths = [];
        $outputPath = $this->temporaryPath($tmpDir, 'merged-invoices-');
        try {
            foreach ($invoiceIds as $invoiceId) {
                $invoice = $this->invoices->find($invoiceId);
                if ($invoice === null || (int) ($invoice['supplier_id'] ?? 0) !== $supplierId) {
                    throw new \RuntimeException('Jedna z exportovaných faktur nebyla nalezena.');
                }
                if (
                    (string) ($invoice['currency'] ?? 'CZK') !== 'CZK'
                    && empty($invoice['exchange_rate'])
                ) {
                    $this->rateApplier->ensureRate($invoiceId);
                }

                $sourcePath = $this->temporaryPath($tmpDir, 'merged-invoice-source-');
                $sourcePaths[] = $sourcePath;
                $this->renderer->renderUnsignedInvoiceOnly($invoiceId, $sourcePath);
            }

            $this->merge($sourcePaths, $outputPath, $tmpDir);
        } catch (\Throwable $e) {
            @unlink($outputPath);
            throw $e;
        } finally {
            foreach ($sourcePaths as $sourcePath) {
                @unlink($sourcePath);
            }
        }

        if (!$sign) {
            return ['path' => $outputPath, 'signed' => false];
        }

        $signedPath = $this->signing->signSupplierPdfIfEnabled(
            $outputPath,
            $supplier,
            'bulk_invoice_export',
            0,
            $userId,
        );
        if ($signedPath === $outputPath) {
            @unlink($outputPath);
            throw new \DomainException('Elektronický podpis výsledného PDF se nepodařilo vytvořit.');
        }

        return ['path' => $signedPath, 'signed' => true];
    }

    /** @param list<string> $sourcePaths */
    private function merge(array $sourcePaths, string $outputPath, string $tmpDir): void
    {
        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 0,
            'margin_bottom' => 0,
            'margin_left' => 0,
            'margin_right' => 0,
            'tempDir' => $tmpDir,
            ...MpdfFontConfig::options(),
        ]);
        $pdf->SetTitle('');
        $pdf->SetAuthor('');
        $pdf->SetCreator('MyInvoice.cz');

        $firstPage = true;
        foreach ($sourcePaths as $sourcePath) {
            $pageCount = $pdf->setSourceFile($sourcePath);
            for ($page = 1; $page <= $pageCount; $page++) {
                $template = $pdf->importPage($page);
                if (!$firstPage) {
                    $pdf->AddPage();
                }
                $pdf->useTemplate($template, 0, 0, null, null, true);
                $firstPage = false;
            }
        }

        $pdf->Output($outputPath, \Mpdf\Output\Destination::FILE);
    }

    private function temporaryPath(string $directory, string $prefix): string
    {
        $path = tempnam($directory, $prefix);
        if ($path === false) {
            throw new \RuntimeException('Nelze vytvořit dočasný PDF soubor.');
        }
        return $path;
    }
}
