<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Oss\OssLedgerService;
use MyInvoice\Service\Oss\OssXmlExporter;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class OssReportAction
{
    public function __construct(
        private readonly OssLedgerService $oss,
        private readonly OssXmlExporter $xmlExporter,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly TaxSubmissionArchiver $archiver,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant', 'readonly'], true)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }

        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $year = (int) ($q['year'] ?? date('Y'));
        $quarter = (int) ($q['quarter'] ?? (int) ceil(((int) date('n')) / 3));
        if ($year < 2020 || $year > 2050 || $quarter < 1 || $quarter > 4) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/čtvrtletí.', 400);
        }
        if (!$this->oss->isEnabledFor($supplierId)) {
            return Json::error($response, 'oss_disabled',
                'OSS režim není v nastavení firmy aktivní.', 409);
        }

        try {
            return Json::ok($response, $this->oss->preview($supplierId, $year, $quarter));
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }
    }

    public function download(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant', 'readonly'], true)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }

        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $year = (int) ($q['year'] ?? date('Y'));
        $quarter = (int) ($q['quarter'] ?? (int) ceil(((int) date('n')) / 3));
        if ($year < 2020 || $year > 2050 || $quarter < 1 || $quarter > 4) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/čtvrtletí.', 400);
        }
        if (!$this->oss->isEnabledFor($supplierId)) {
            return Json::error($response, 'oss_disabled',
                'OSS režim není v nastavení firmy aktivní.', 409);
        }

        try {
            $result = $this->xmlExporter->build($supplierId, $year, $quarter);
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }

        $userId = (int) ($user['id'] ?? 0);
        $archived = $this->archiver->archive(
            $supplierId,
            'ossei1',
            $year,
            null,
            $quarter,
            $result['xml'],
            $result['summary'],
            $userId ?: null,
        );

        $this->logger->log('report.ossei1_downloaded', $userId, null, null, [
            'period' => sprintf('%04d-Q%d', $year, $quarter),
            'rows' => $result['summary']['rows_count'] ?? 0,
            'corrections' => $result['summary']['corrections_count'] ?? 0,
            'submission_id' => $archived['submission_id'],
            'validation_status' => $archived['validation_status'],
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        $filename = sprintf('ossei1-%04d-Q%d.xml', $year, $quarter);
        $response->getBody()->write($result['xml']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
