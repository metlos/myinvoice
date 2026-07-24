<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Approval\ApprovalTokenValidator;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * GET /api/public/invoice/{token}/attachment/{attId}
 *
 * Stažení přílohy e-mailu z veřejné web faktury — vzor DownloadAttachmentAction,
 * ale gated tokenem faktury místo SupplierGuard. Vazba attId → invoice_id
 * v `find($attId, $id)` brání stažení přílohy cizí faktury (IDOR).
 * Vždy attachment disposition — public návštěvníkovi nic nevykreslujeme inline.
 */
final class PublicInvoiceAttachmentAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoiceAttachmentRepository $attachments,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');

        $token = (string) ($args['token'] ?? '');
        if (!ApprovalTokenValidator::isValidFormat($token)) {
            return Json::error($response, 'invalid_token', 'Neplatný odkaz.', 404);
        }

        $ref = $this->repo->publicInvoiceRefByToken($token);
        if ($ref === null) {
            return Json::error($response, 'token_invalid_or_expired',
                'Tento odkaz není platný nebo byl zneplatněn.', 404);
        }

        $att = $this->attachments->find((int) ($args['attId'] ?? 0), $ref['id']);
        if ($att === null) {
            return Json::error($response, 'not_found', 'Příloha nenalezena.', 404);
        }

        $path = $this->attachments->pathFor($ref['supplier_id'], $ref['id'], (string) $att['filename']);
        if (!is_file($path)) {
            return Json::error($response, 'not_found', 'Soubor nenalezen na disku.', 404);
        }

        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!is_array($user) || empty($user['id'])) {
            $this->repo->markPublicViewed($ref['id']);
            $this->logger->log('invoice.public_attachment_downloaded', null, 'invoice', $ref['id'],
                ['original_name' => (string) $att['original_name']],
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'), $ref['supplier_id']);
        }

        $safe = preg_replace('/[\r\n"\\\\]/', '_', (string) $att['original_name']);

        $stream = new Stream(fopen($path, 'rb'));
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', (string) $att['mime_type'])
            ->withHeader('Content-Disposition', "attachment; filename=\"{$safe}\"")
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'no-store')
            // Defense-in-depth proti MIME sniffingu / aktivnímu obsahu
            // (parita s DownloadAttachmentAction).
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withBody($stream);
    }
}
