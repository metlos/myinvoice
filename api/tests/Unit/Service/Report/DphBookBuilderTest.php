<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Report\DphBookBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Kniha DPH — bilance „Výsledná DPH" (vat_balance).
 *
 * Regression: reverse charge (samovyměření přijaté služby/zboží ze zahraničí) se
 * MUSÍ v bilanci vyrušit — samovyměřená daň je na výstupu (ř.3–13), zrcadlový
 * odpočet na vstupu (ř.43). Dřív builder účtoval RC primární řádek do `received`
 * (dle prefixu sekce 43) a mirror ř.43 vynechával, čímž bilanci o samovyměřenou
 * daň podhodnocoval a nesedělo to s DPH přiznáním.
 */
final class DphBookBuilderTest extends TestCase
{
    private PDO $pdo;
    private DphBookBuilder $builder;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->seed();

        $config = $this->createStub(\MyInvoice\Infrastructure\Config\Config::class);
        $conn = new Connection($config);
        $ref = new \ReflectionClass($conn);
        $ref->getProperty('pdo')->setValue($conn, $this->pdo);

        $taxConstants = new TaxConstantsRepository($conn);
        $this->builder = new DphBookBuilder($conn, new VatLedgerService($conn, $taxConstants), $taxConstants);
    }

    public function testReverseChargeNetsToZeroInBalance(): void
    {
        // Prodej: základ 1000, DPH 210 (21 %) → ř.1 (výstup).
        $this->insertSale(1, '2026-05-10', 1000.0, 210.0, '1');
        // Přijatá služba ze 3. země (US), reverse charge, doklad bez DPH: samovyměření
        // 1000 × 21 % = 210 na ř.12 (výstup) + zrcadlový odpočet 210 na ř.43 (vstup).
        $this->insertPurchase(10, 300, '2026-05-12', 1000.0, 0.0, 21.0, '24');

        $r = $this->builder->build(1, 2026, 5, 'monthly');

        // Daň na výstupu = prodej 210 + samovyměření 210 = 420.
        $this->assertSame(420.0, $r['totals']['issued']['vat'], 'issued musí obsahovat samovyměření RC (ř.12)');
        // Odpočet na vstupu = zrcadlo ř.43 = 210.
        $this->assertSame(210.0, $r['totals']['received']['vat'], 'received musí obsahovat mirror ř.43');
        // Bilance = 420 − 210 = 210 → RC se vyrušil, zůstává jen daň z prodeje.
        $this->assertSame(210.0, $r['totals']['vat_balance'], 'reverse charge se v bilanci vyruší (net 0)');
    }

    public function testEuServiceLandsOnLine5(): void
    {
        // Přijatá služba z EU (DE) → kód 24e, primary ř.5, mirror ř.43.
        $this->insertPurchase(11, 301, '2026-05-15', 2000.0, 0.0, 21.0, '24e');

        $r = $this->builder->build(1, 2026, 5, 'monthly');
        $keys = array_column($r['sections'], 'key');
        $this->assertContains('43.005', $keys, 'EU služba má primary sekci ř.5');
        $this->assertContains('43.043', $keys, 'a zrcadlový odpočet ř.43');
        // Samovyměření 2000 × 21 % = 420 na výstup, mirror 420 na vstup → bilance 0.
        $this->assertSame(420.0, $r['totals']['issued']['vat']);
        $this->assertSame(420.0, $r['totals']['received']['vat']);
        $this->assertSame(0.0, $r['totals']['vat_balance']);
    }

    // ───── helpers ───────────────────────────────────────────────────────────

    private function createSchema(): void
    {
        $this->pdo->exec("CREATE TABLE currencies (id INTEGER PRIMARY KEY, code TEXT NOT NULL)");
        $this->pdo->exec("INSERT INTO currencies (id, code) VALUES (1, 'CZK')");
        $this->pdo->exec("CREATE TABLE countries (id INTEGER PRIMARY KEY, iso2 TEXT NOT NULL, is_eu INTEGER NOT NULL DEFAULT 0)");
        $this->pdo->exec("INSERT INTO countries (id, iso2, is_eu) VALUES (1,'CZ',1), (4,'DE',1), (9,'US',0)");
        $this->pdo->exec("CREATE TABLE tax_constants (year INTEGER PRIMARY KEY, data TEXT NOT NULL)");
        $this->pdo->exec("CREATE TABLE supplier (
            id INTEGER PRIMARY KEY, company_name TEXT NOT NULL DEFAULT '', street TEXT NULL,
            city TEXT NULL, zip TEXT NULL, country_id INTEGER NULL, ic TEXT NULL, dic TEXT NULL,
            is_vat_payer INTEGER NOT NULL DEFAULT 1
        )");
        $this->pdo->exec("INSERT INTO supplier (id, company_name, country_id, is_vat_payer) VALUES (1,'Test s.r.o.',1,1)");
        $this->pdo->exec("CREATE TABLE clients (
            id INTEGER PRIMARY KEY, company_name TEXT NOT NULL DEFAULT '', dic TEXT NULL, country_id INTEGER NULL
        )");
        $this->pdo->exec("INSERT INTO clients (id, company_name, dic, country_id) VALUES
            (200,'Odběratel CZ','CZ22222220',1), (300,'Anthropic US',NULL,9), (301,'Vendor DE','DE123',4)");
        $this->pdo->exec("CREATE TABLE vat_classifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NULL, code TEXT NOT NULL, label TEXT NOT NULL,
            direction TEXT NOT NULL, dphdp3_line TEXT NULL, dphdp3_line_secondary TEXT NULL, kh_section TEXT NULL,
            vat_rate REAL NULL, is_reverse_charge INTEGER NOT NULL DEFAULT 0, kod_pred_pl TEXT NULL,
            kh_regime_code TEXT NULL, kh_bad_debt TEXT NULL, display_order INTEGER NOT NULL DEFAULT 0,
            archived INTEGER NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE purchase_invoices (
            id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL, vendor_id INTEGER NOT NULL, varsymbol TEXT NULL,
            vendor_invoice_number TEXT NULL, document_kind TEXT NULL, issue_date TEXT NOT NULL, tax_date TEXT NULL,
            currency_id INTEGER NOT NULL, exchange_rate REAL NULL, reverse_charge INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'received', vat_classification_code TEXT NULL,
            vat_deduction TEXT NOT NULL DEFAULT 'full', vat_deduction_percent REAL NOT NULL DEFAULT 100,
            is_fixed_asset INTEGER NOT NULL DEFAULT 0, total_with_vat REAL NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE purchase_invoice_items (
            id INTEGER PRIMARY KEY, purchase_invoice_id INTEGER NOT NULL, vat_rate_snapshot REAL NOT NULL,
            description TEXT NULL, total_without_vat REAL NOT NULL, total_vat REAL NOT NULL,
            vat_classification_code TEXT NULL, is_fixed_asset INTEGER NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE invoices (
            id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL, client_id INTEGER NULL, varsymbol TEXT NULL,
            issue_date TEXT NOT NULL, tax_date TEXT NULL, currency_id INTEGER NOT NULL, exchange_rate REAL NULL,
            reverse_charge INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'issued',
            invoice_type TEXT NOT NULL DEFAULT 'invoice', vat_classification_code TEXT NULL, total_with_vat REAL NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE invoice_items (
            id INTEGER PRIMARY KEY, invoice_id INTEGER NOT NULL, vat_rate_snapshot REAL NOT NULL,
            description TEXT NULL, total_without_vat REAL NOT NULL, total_vat REAL NOT NULL,
            vat_classification_code TEXT NULL, oss_applicable INTEGER NOT NULL DEFAULT 0
        )");
    }

    private function seed(): void
    {
        $rows = [
            ['1',   'Sale 21 %',           'sale',     '1',  null, 'A.4', 21.0, 0],
            ['24',  'Služba ze 3. země',   'purchase', '12', '43', null, 21.0, 1],
            ['24e', 'Služba z EU',         'purchase', '5',  '43', null, 21.0, 1],
        ];
        $stmt = $this->pdo->prepare("INSERT INTO vat_classifications
            (supplier_id, code, label, direction, dphdp3_line, dphdp3_line_secondary, kh_section, vat_rate, is_reverse_charge, display_order, archived)
            VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)");
        foreach ($rows as $r) $stmt->execute($r);
    }

    private function insertSale(int $id, string $date, float $base, float $vat, string $code): void
    {
        $this->pdo->prepare("INSERT INTO invoices (id, supplier_id, client_id, varsymbol, issue_date, tax_date, currency_id, exchange_rate, status, invoice_type, total_with_vat)
            VALUES (?, 1, 200, ?, ?, ?, 1, 1, 'issued', 'invoice', ?)")
            ->execute([$id, (string) $id, $date, $date, $base + $vat]);
        $this->pdo->prepare("INSERT INTO invoice_items (id, invoice_id, vat_rate_snapshot, description, total_without_vat, total_vat, vat_classification_code)
            VALUES (?, ?, 21.0, 'prodej', ?, ?, ?)")
            ->execute([$id, $id, $base, $vat, $code]);
    }

    private function insertPurchase(int $id, int $vendorId, string $date, float $base, float $vat, float $rate, string $code): void
    {
        $this->pdo->prepare("INSERT INTO purchase_invoices (id, supplier_id, vendor_id, varsymbol, issue_date, tax_date, currency_id, exchange_rate, reverse_charge, status, vat_classification_code, total_with_vat)
            VALUES (?, 1, ?, ?, ?, ?, 1, 1, 1, 'received', ?, ?)")
            ->execute([$id, $vendorId, (string) $id, $date, $date, $code, $base + $vat]);
        $this->pdo->prepare("INSERT INTO purchase_invoice_items (id, purchase_invoice_id, vat_rate_snapshot, description, total_without_vat, total_vat, vat_classification_code)
            VALUES (?, ?, ?, 'služba', ?, ?, ?)")
            ->execute([$id, $id, $rate, $base, $vat, $code]);
    }
}
