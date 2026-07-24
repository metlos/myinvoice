<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hromadný AI import — označení dávky + rychlá změna typu dokladu (#232).
 *
 * Ověřuje:
 *   - setImportBatchId() + filtr listGroupedByMonth[import_batch_id] vrátí jen dávku
 *   - recentImportBatches() vrátí dávku se správným počtem
 *   - updateDocumentKind(): receipt→invoice projde; přechod z/na advance je odmítnut;
 *     stornovaný doklad nelze měnit; neplatný typ je odmítnut.
 *
 * Izolováno pod existujícím supplierem, vše uklizeno v tearDown.
 */
#[Group('integration')]
final class PurchaseImportBatchAndKindTest extends TestCase
{
    private Connection $db;
    private PurchaseInvoiceRepository $repo;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;

    /** @var int[] */
    private array $vendorIds = [];
    /** @var int[] */
    private array $piIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container  = Bootstrap::buildApp()->getContainer();
            $this->db   = $container->get(Connection::class);
            $this->repo = $container->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->piIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->vendorIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testImportBatchTaggingAndFilter(): void
    {
        $vendor = $this->vendor('Dávka dodavatel', 'CZ21000001');
        $batch  = 'testbatch' . substr(md5((string) $vendor), 0, 12);

        $a = $this->repo->createDraft($this->payload($vendor, 'PIB-A', 'receipt'), $this->userId, $this->supplierId);
        $b = $this->repo->createDraft($this->payload($vendor, 'PIB-B', 'receipt'), $this->userId, $this->supplierId);
        $c = $this->repo->createDraft($this->payload($vendor, 'PIB-C', 'invoice'), $this->userId, $this->supplierId);
        $this->piIds = array_merge($this->piIds, [$a, $b, $c]);

        $this->repo->setImportBatchId($a, $this->supplierId, $batch);
        $this->repo->setImportBatchId($b, $this->supplierId, $batch);
        // $c záměrně bez dávky

        $ids = $this->flatIds($this->repo->listGroupedByMonth([
            'supplier_id'     => $this->supplierId,
            'import_batch_id' => $batch,
            'year'            => 2099,
        ], 1, 100));

        self::assertContains($a, $ids);
        self::assertContains($b, $ids);
        self::assertNotContains($c, $ids, 'doklad bez dávky nesmí projít filtrem import_batch_id');

        $batches = $this->repo->recentImportBatches($this->supplierId, 50);
        $found = null;
        foreach ($batches as $row) {
            if ($row['import_batch_id'] === $batch) { $found = $row; break; }
        }
        self::assertNotNull($found, 'recentImportBatches musí dávku vrátit');
        self::assertSame(2, $found['count']);
    }

    public function testUpdateDocumentKindReceiptToInvoice(): void
    {
        $vendor = $this->vendor('Typ dodavatel', 'CZ21000002');
        $id = $this->repo->createDraft($this->payload($vendor, 'PIK-1', 'receipt'), $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        self::assertNull($this->repo->updateDocumentKind($id, $this->supplierId, 'invoice'));
        self::assertSame('invoice', $this->storedKind($id));
    }

    public function testUpdateDocumentKindRejectsAdvanceAndInvalidAndCancelled(): void
    {
        $vendor = $this->vendor('Guard dodavatel', 'CZ21000003');

        // advance → invoice zamítnuto
        $adv = $this->repo->createDraft($this->payload($vendor, 'PIK-ADV', 'advance'), $this->userId, $this->supplierId);
        $this->piIds[] = $adv;
        self::assertNotNull($this->repo->updateDocumentKind($adv, $this->supplierId, 'invoice'),
            'přechod ZE zálohy musí být odmítnut (settlement vazby)');
        self::assertSame('advance', $this->storedKind($adv));

        // invoice → advance zamítnuto
        $inv = $this->repo->createDraft($this->payload($vendor, 'PIK-INV', 'invoice'), $this->userId, $this->supplierId);
        $this->piIds[] = $inv;
        self::assertNotNull($this->repo->updateDocumentKind($inv, $this->supplierId, 'advance'),
            'přechod NA zálohu musí být odmítnut');

        // neplatný typ
        self::assertNotNull($this->repo->updateDocumentKind($inv, $this->supplierId, 'nonsense'));

        // stornovaný doklad
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status='cancelled' WHERE id = ?")->execute([$inv]);
        self::assertNotNull($this->repo->updateDocumentKind($inv, $this->supplierId, 'receipt'),
            'stornovaný doklad nelze měnit');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return int[] */
    private function flatIds(array $grouped): array
    {
        $ids = [];
        foreach ($grouped['data'] ?? [] as $g) {
            foreach ($g['invoices'] ?? [] as $inv) {
                $ids[] = (int) $inv['id'];
            }
        }
        return $ids;
    }

    private function storedKind(int $piId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT document_kind FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$piId]);
        return (string) $stmt->fetchColumn();
    }

    private function vendor(string $name, string $dic): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "v@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->vendorIds[] = $id;
        return $id;
    }

    private function payload(int $vendorId, string $number, string $kind): array
    {
        return [
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $number,
            'document_kind'         => $kind,
            'issue_date'            => '2099-06-10',
            'tax_date'              => '2099-06-10',
            'due_date'              => '2099-06-24',
            'currency_id'           => $this->currencyId,
        ];
    }
}
