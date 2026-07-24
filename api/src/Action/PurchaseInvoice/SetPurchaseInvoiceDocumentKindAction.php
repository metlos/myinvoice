<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/purchase-invoices/{id}/document-kind
 *
 * Rychlá změna typu dokladu (#232) — používá se hlavně po hromadném AI importu,
 * kdy AI klasifikuje účtenku/paragon jako `receipt` („Doklad o úhradě"), ale
 * účetní ji chce vést jako `invoice`. Řádkové totály ani prices_include_vat se
 * nemění; jde jen o zařazení. Přechod z/na `advance` je vyloučen (settlement
 * vazby se řeší jen v editoru).
 *
 * Body: { document_kind: "invoice"|"receipt"|"credit_note" }
 * Vrací aktualizovaný invoice payload (jako Get).
 */
final class SetPurchaseInvoiceDocumentKindAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $kind = (string) ($body['document_kind'] ?? '');
        $before = (string) ($existing['document_kind'] ?? '');

        $err = $this->repo->updateDocumentKind($id, $supplierId, $kind);
        if ($err !== null) {
            return Json::error($response, 'invalid_document_kind', $err, 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.document_kind_changed', $user['id'] ?? null, 'purchase_invoice', $id, [
            'from' => $before,
            'to'   => $kind,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id, $supplierId));
    }
}
