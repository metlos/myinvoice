<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\VatClassificationMapper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DPH přiznání DPHDP3 endpoints:
 *   GET /api/reports/dphdp3/preview?year=2026&month=5  — JSON summary (řádky + warnings)
 *   GET /api/reports/dphdp3?year=2026&month=5          — XML download
 *
 * Permissions: admin nebo accountant.
 *
 * ⚠️ Vygenerované XML je pomůcka. Před odesláním ověřit s účetní/poradcem.
 */
final class DphPriznaniAction
{
    public function __construct(
        private readonly DphPriznaniBuilder $builder,
        private readonly VatClassificationMapper $mapper,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly \MyInvoice\Service\Report\TaxSubmissionArchiver $archiver,
        private readonly \MyInvoice\Service\Currency\MissingExchangeRateFiller $rateFiller,
    ) {}

    /**
     * GET /api/reports/dphdp3/settings → { vat_period, is_vat_payer }
     * Vrátí supplier nastavení potřebné pro UI (měsíční vs kvartální period picker).
     */
    public function settings(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant', 'readonly'], true)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $stmt = $this->db->pdo()->prepare(
            'SELECT vat_period, is_vat_payer, is_identified, taxpayer_type, financial_office_code FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $isIdentified = !((bool) ($row['is_vat_payer'] ?? false)) && (bool) ($row['is_identified'] ?? false);
        return Json::ok($response, [
            // Identifikovaná osoba podává vždy měsíčně — UI nedostane kvartální volbu.
            'vat_period'            => $isIdentified ? 'monthly' : ($row['vat_period'] ?? null),
            'is_vat_payer'          => (bool) ($row['is_vat_payer'] ?? false),
            'is_identified'         => $isIdentified,
            'taxpayer_type'         => $row['taxpayer_type'] ?? null,
            'has_financial_office'  => !empty($row['financial_office_code']),
        ]);
    }

    /**
     * GET /api/reports/dphdp3/drafts-prediction?year=&month=&period= → predikce DPH
     * pro zvolené přiznací období (měsíc / kvartál). Returns:
     *   { year, month, period, vat_output, vat_input, tax_due,
     *     sale_count, sale_draft_count, purchase_count, purchase_draft_count }
     *
     * Pravidla (zařazení do období řeší VatLedgerService — viz tam):
     * - vystavené dle DUZP `COALESCE(tax_date, issue_date)`, přijaté dle pozdějšího
     *   z (DUZP, vystavení) `GREATEST(...)` — odpočet nelze uplatnit dřív, než plátce
     *   drží daňový doklad (§ 73 ZDPH). Drafty často DUZP nemají (`tax_date` NULL).
     * - sale (vydané): invoice_type IN (invoice, credit_note), status NOT IN
     *   (cancelled), tedy bere finalizované doklady i koncepty pro zvolené
     *   období.
     * - purchase (přijaté): status NOT IN (cancelled), bere obojí (doklady
     *   i koncepty).
     * - Multi-currency: total_vat × COALESCE(exchange_rate, 1) → CZK. Drafty
     *   bez nastaveného kurzu se počítají jako 1:1.
     *
     * Default year/month: aktuální datum. Default period: supplier.vat_period
     * (fallback 'monthly').
     */
    public function draftsPrediction(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant', 'readonly'], true)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $pdo = $this->db->pdo();

        $q = $request->getQueryParams();
        $year  = (int) ($q['year']  ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }
        $period = (string) ($q['period'] ?? '');
        if (!in_array($period, ['monthly', 'quarterly'], true)) {
            $stmt = $pdo->prepare('SELECT vat_period FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $period = (string) ($stmt->fetchColumn() ?: 'monthly');
            if (!in_array($period, ['monthly', 'quarterly'], true)) $period = 'monthly';
        }

        // Predikce přes VatLedgerService (includeDrafts=true) — stejná logika jako
        // přiznání (klasifikace, CZK, RC samovyměření), jen vč. konceptů. Dříve tu bylo
        // vlastní inline SQL sčítající total_vat napřímo (bez RC samovyměření).
        $prediction = $this->mapper->predictDph($supplierId, $year, $month, $period);

        return Json::ok($response, array_merge(
            ['year' => $year, 'month' => $month, 'period' => $period],
            $prediction,
        ));
    }

    /**
     * GET /api/reports/dphdp3/trend?months=12 → list měsíčních souhrnů DPH
     * (output, input, due) pro graf.
     */
    public function trend(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant', 'readonly'], true)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $months = max(1, min(36, (int) ($request->getQueryParams()['months'] ?? 12)));
        return Json::ok($response, $this->mapper->monthlyDphTrend($supplierId, $months));
    }

    public function preview(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant', 'readonly'], true)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $year  = (int) ($q['year']  ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }

        $period = (string) ($q['period'] ?? '');
        $period = in_array($period, ['monthly', 'quarterly'], true) ? $period : null;
        try {
            $result = $this->builder->build($supplierId, $year, $month, $period);
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }

        return Json::ok($response, [
            'summary'  => $result['summary'],
            'warnings' => $result['warnings'],
        ]);
    }

    public function download(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant', 'readonly'], true)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $year  = (int) ($q['year']  ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }

        $period = (string) ($q['period'] ?? '');
        $period = in_array($period, ['monthly', 'quarterly'], true) ? $period : null;
        try {
            $result = $this->builder->build($supplierId, $year, $month, $period);
            // #238: doplň chybějící kurzy z ČNB a přebuildi; tvrdá chyba jen když ČNB nemá.
            if (!empty($result['missing_rates'])) {
                $this->rateFiller->fill($supplierId, $result['missing_rates']);
                $result = $this->builder->build($supplierId, $year, $month, $period);
                if (!empty($result['missing_rates'])) {
                    $labels = \MyInvoice\Service\Report\VatLedgerService::missingExchangeRateLabels($result['missing_rates']);
                    return Json::error($response, 'exchange_rate_missing',
                        'Nelze vytvořit XML: ČNB nemá kurz pro doklady ' . implode(', ', $labels)
                        . '. Doplňte kurz ručně u faktury a zkuste znovu.', 422);
                }
            }
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }

        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        // Archivovat + XSD validation
        $isQuarterly = ($result['summary']['period_type'] ?? 'monthly') === 'quarterly';
        $archived = $this->archiver->archive(
            $supplierId, 'dphdp3', $year,
            $isQuarterly ? null : $month,
            $isQuarterly ? (int) ceil($month / 3) : null,
            $result['xml'], $result['summary'], $userId ?: null,
        );

        $this->logger->log('report.dphdp3_downloaded', $userId, null, null, [
            'period'            => sprintf('%04d-%02d', $year, $month),
            'period_type'       => $result['summary']['period_type'] ?? 'monthly',
            'submission_id'     => $archived['submission_id'],
            'validation_status' => $archived['validation_status'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        $filename = $isQuarterly
            ? sprintf('dphdp3-%04d-Q%d.xml', $year, (int) ceil($month / 3))
            : sprintf('dphdp3-%04d-%02d.xml', $year, $month);
        $response->getBody()->write($result['xml']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
