<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/purchase-invoices
 *
 * Vrací seznam přijatých faktur seskupený po měsících (per tenant).
 * Filtry: status, document_kind, vendor_id, year, month, date_from, date_to, currency, q, unpaid_only, overdue
 */
final class ListPurchaseInvoicesAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $filter = (array) ($q['filter'] ?? []);

        $filters = [
            'q'             => isset($q['q']) ? trim((string) $q['q']) : '',
            'status'        => $filter['status']        ?? null,
            'document_kind' => $filter['document_kind'] ?? null,
            'vendor_id'     => $filter['vendor_id']     ?? null,
            'year'          => $filter['year']          ?? null,
            'month'         => $filter['month']         ?? null,
            'date_from'     => $filter['date_from']     ?? null,
            'date_to'       => $filter['date_to']       ?? null,
            'currency'      => $filter['currency']      ?? null,
            'unpaid_only'   => !empty($filter['unpaid_only']),
            'overdue'       => !empty($filter['overdue']),
            'needs_review'  => !empty($filter['needs_review']),
            'payment_ordered' => $filter['payment_ordered'] ?? null,
            'import_batch_id' => $filter['import_batch_id'] ?? null,
            'supplier_id'   => (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0),
        ];

        // CSV split pro multi-select
        foreach (['status', 'document_kind'] as $f) {
            if (is_string($filters[$f]) && $filters[$f] !== '' && str_contains($filters[$f], ',')) {
                $filters[$f] = explode(',', $filters[$f]);
            }
        }

        $page = max(1, (int) ($q['page'] ?? 1));
        $default = (int) $this->config->get('pagination.invoices_per_page', 50);
        $perPage = min(200, max(5, (int) ($q['per_page'] ?? $default)));

        return Json::ok($response, $this->repo->listGroupedByMonth($filters, $page, $perPage));
    }
}
