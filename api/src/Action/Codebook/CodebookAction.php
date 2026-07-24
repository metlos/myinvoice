<?php

declare(strict_types=1);

namespace MyInvoice\Action\Codebook;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Číselníky pro frontend (countries, currencies, vat_rates).
 * Cache-friendly — frontend si může uložit do localStorage.
 */
final class CodebookAction
{
    public function __construct(private readonly Connection $db) {}

    public function countries(Request $request, Response $response): Response
    {
        $rows = $this->db->pdo()->query(
            'SELECT id, iso2, iso3, name_cs, name_en, is_eu FROM countries ORDER BY name_cs'
        )->fetchAll(\PDO::FETCH_ASSOC);
        return Json::ok($response, array_map(fn (array $r) => [
            'id'      => (int) $r['id'],
            'iso2'    => $r['iso2'],
            'iso3'    => $r['iso3'],
            'name_cs' => $r['name_cs'],
            'name_en' => $r['name_en'],
            'is_eu'   => (bool) $r['is_eu'],
        ], $rows));
    }

    public function currencies(Request $request, Response $response): Response
    {
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        // Default: jen aktivní (pro vystavené faktury). ?include_inactive=1 vrátí všechny
        // (přijaté faktury — vendor's currency může být v měně, ve které nemáme bankovní účet).
        $includeInactive = !empty($request->getQueryParams()['include_inactive']);
        $where = $includeInactive ? 'supplier_id = ?' : 'supplier_id = ? AND is_active = 1';
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default
               FROM currencies WHERE $where
              ORDER BY is_active DESC, code, is_default DESC, label"
        );
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return Json::ok($response, array_map(fn (array $r) => [
            'id'        => (int) $r['id'],
            'code'      => $r['code'],
            'label'     => $r['label'],
            'symbol'    => $r['symbol'],
            'name_cs'   => $r['name_cs'],
            'name_en'   => $r['name_en'],
            'decimals'  => (int) $r['decimals'],
            'is_active' => (bool) $r['is_active'],
            'is_default'=> (bool) $r['is_default'],
        ], $rows));
    }

    public function units(Request $request, Response $response): Response
    {
        $rows = $this->db->pdo()->query(
            'SELECT id, code, label_cs, label_en, is_default, display_order
               FROM units ORDER BY display_order, code'
        )->fetchAll(\PDO::FETCH_ASSOC);
        return Json::ok($response, array_map(fn (array $r) => [
            'id'            => (int) $r['id'],
            'code'          => $r['code'],
            'label_cs'      => $r['label_cs'],
            'label_en'      => $r['label_en'],
            'is_default'    => (bool) $r['is_default'],
            'display_order' => (int) $r['display_order'],
        ], $rows));
    }

    /**
     * GET /api/codebooks/years
     *
     * Vrací distinct roky podle `issue_date` z `invoices` a `purchase_invoices`
     * aktuálního supplier — sjednocené, sestupně. Slouží jako zdroj pro year
     * dropdowny v list page UI (per issue #33: dropdown byl hardcoded na
     * posledních 5 let → historická data starší než to byla v UI neviditelná).
     *
     * Vždy doplníme aktuální rok a předchozí, aby filter nikdy neměl prázdný
     * dropdown (např. čerstvý setup bez faktur).
     *
     * @return array{invoices: list<int>, purchase_invoices: list<int>, combined: list<int>}
     */
    public function years(Request $request, Response $response): Response
    {
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($sid === 0) {
            return Json::ok($response, ['invoices' => [], 'purchase_invoices' => [], 'combined' => []]);
        }

        $pdo = $this->db->pdo();

        $invStmt = $pdo->prepare(
            'SELECT DISTINCT YEAR(issue_date) AS y FROM invoices WHERE supplier_id = ? ORDER BY y DESC'
        );
        $invStmt->execute([$sid]);
        $invYears = array_map(static fn ($v) => (int) $v, $invStmt->fetchAll(\PDO::FETCH_COLUMN));

        $purStmt = $pdo->prepare(
            'SELECT DISTINCT YEAR(issue_date) AS y FROM purchase_invoices WHERE supplier_id = ? ORDER BY y DESC'
        );
        $purStmt->execute([$sid]);
        $purYears = array_map(static fn ($v) => (int) $v, $purStmt->fetchAll(\PDO::FETCH_COLUMN));

        // Doplnit aktuální + minulý rok aby dropdown nikdy nebyl prázdný (fresh setup)
        // a aby uživatel mohl vždy filtrovat na aktuální období i bez existujících dokladů.
        $currentYear = (int) date('Y');
        $combined = array_unique(array_merge($invYears, $purYears, [$currentYear, $currentYear - 1]));
        rsort($combined);

        return Json::ok($response, [
            'invoices'          => $invYears,
            'purchase_invoices' => $purYears,
            'combined'          => array_values($combined),
        ]);
    }

    public function vatRates(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $country = strtoupper((string) ($q['country'] ?? 'CZ'));
        $allCountries = in_array($country, ['', '*', 'ALL'], true);
        $activeOn = (string) ($q['active_on'] ?? date('Y-m-d'));

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, rate_percent, country, label_cs, label_en, is_default, is_reverse_charge,
                    valid_from, valid_to, display_order
               FROM vat_rates
              WHERE (? = 1 OR country = ?)
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY country, display_order, rate_percent DESC'
        );
        $stmt->execute([$allCountries ? 1 : 0, $country, $activeOn, $activeOn]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return Json::ok($response, array_map(fn (array $r) => [
            'id'                => (int) $r['id'],
            'code'              => $r['code'],
            'rate_percent'      => (float) $r['rate_percent'],
            'country'           => $r['country'],
            'label_cs'          => $r['label_cs'],
            'label_en'          => $r['label_en'],
            'is_default'        => (bool) $r['is_default'],
            'is_reverse_charge' => (bool) $r['is_reverse_charge'],
            'valid_from'        => $r['valid_from'],
            'valid_to'          => $r['valid_to'],
            'display_order'     => (int) $r['display_order'],
        ], $rows));
    }
}
