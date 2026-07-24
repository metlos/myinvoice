<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/purchase-invoices/import-batches
 *
 * Posledních N dávek hromadného AI importu (#232) — pro dropdown „dohledat import"
 * v seznamu přijatých faktur, ať doklady po hromadném importu nezapadnou mezi
 * stovky ostatních. Vrací id dávky, čas a počet dokladů.
 */
final class PurchaseInvoiceImportBatchesAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId === 0) {
            return Json::ok($response, ['data' => []]);
        }
        $limit = (int) ($request->getQueryParams()['limit'] ?? 20);
        return Json::ok($response, ['data' => $this->repo->recentImportBatches($supplierId, $limit)]);
    }
}
