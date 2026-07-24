<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Approval\ApprovalTokenValidator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * GET /api/public/invoice/{token}/pdf
 *
 * Stažení PDF z veřejné web faktury (bez auth, jen token) — vzor PdfAction,
 * ale bez SupplierGuard (identitu nahrazuje tajný token) a bez regenerate
 * (public návštěvník nesmí vynutit přegenerování). Draft 404 jako u GET.
 */
final class PublicInvoicePdfAction
{
    public function __construct(
        private readonly InvoicePdfRenderer $renderer,
        private readonly InvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        // Vypni HTML error output (deprecation warnings z 3rd party libs by jinak
        // skončily v PDF binary streamu).
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');

        $token = (string) ($args['token'] ?? '');
        if (!ApprovalTokenValidator::isValidFormat($token)) {
            return Json::error($response, 'invalid_token', 'Neplatný odkaz.', 404);
        }

        // Lehký lookup — plnou fakturu si render() načte sám.
        $ref = $this->repo->publicInvoiceRefByToken($token);
        if ($ref === null) {
            return Json::error($response, 'token_invalid_or_expired',
                'Tento odkaz není platný nebo byl zneplatněn.', 404);
        }
        $id = $ref['id'];

        // Zachyť případné echo/warning z 3rd party libs během renderu.
        ob_start();
        try {
            $path = $this->renderer->render($id);
        } catch (\Throwable) {
            ob_end_clean();
            // Public endpoint — žádné interní detaily chyby ven
            return Json::error($response, 'pdf_failed', 'PDF se nepodařilo vygenerovat.', 500);
        }
        ob_end_clean();

        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!is_array($user) || empty($user['id'])) {
            $this->repo->markPublicViewed($id);
            $this->logger->log('invoice.public_pdf_downloaded', null, 'invoice', $id, [],
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'), $ref['supplier_id']);
        }

        $download = !empty($request->getQueryParams()['download']);
        $filename = basename($path);
        $disposition = $download ? "attachment; filename=\"{$filename}\"" : "inline; filename=\"{$filename}\"";

        $stream = new Stream(fopen($path, 'rb'));
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($stream);
    }
}
