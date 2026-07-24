<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end test procentuální slevy na úrovni vydané faktury (issue #50).
 *
 * Ověřuje, že InvoiceRepository::replaceItems z header `discount_percent`
 * materializuje zápornou položku „Sleva X %" na každou sazbu DPH a že po
 * recompute sedí základ i DPH PO slevě (= to, co pak sumují DPH výkazy).
 *
 * Používá existující supplier/client/currency/vat_rate z dev DB; uklízí po sobě.
 */
#[Group('integration')]
final class InvoiceDiscountTest extends TestCase
{
    private Connection $db;
    private InvoiceRepository $repo;
    private InvoiceCalculator $calc;

    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private float $vatRate = 0.0;
    private int $userId = 0;

    /** @var int[] */
    private array $createdInvoiceIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) {
                $this->markTestSkipped('Container not available');
            }
            $this->db = $container->get(Connection::class);
            $this->repo = $container->get(InvoiceRepository::class);
            $this->calc = $container->get(InvoiceCalculator::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();

        $supplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        if ($supplierId <= 0) {
            $this->markTestSkipped('Žádný supplier');
        }

        $stmt = $pdo->prepare('SELECT id FROM clients WHERE supplier_id = ? AND archived_at IS NULL LIMIT 1');
        $stmt->execute([$supplierId]);
        $this->clientId = (int) $stmt->fetchColumn();
        if ($this->clientId <= 0) {
            $this->markTestSkipped("Supplier #{$supplierId} nemá klienty");
        }

        $stmt = $pdo->prepare('SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$supplierId]);
        $this->currencyId = (int) $stmt->fetchColumn();
        if ($this->currencyId <= 0) {
            $this->markTestSkipped('Supplier nemá aktivní měnu');
        }

        $row = $pdo->query(
            'SELECT id, rate_percent FROM vat_rates
              WHERE is_reverse_charge = 0 AND rate_percent > 0
                AND (valid_from IS NULL OR valid_from <= CURDATE())
                AND (valid_to IS NULL OR valid_to >= CURDATE())
              ORDER BY is_default DESC, rate_percent DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->markTestSkipped('Žádná použitelná VAT sazba');
        }
        $this->vatRateId = (int) $row['id'];
        $this->vatRate = (float) $row['rate_percent'];

        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($this->userId <= 0) {
            $this->markTestSkipped('Žádný uživatel');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->createdInvoiceIds !== []) {
            $pdo = $this->db->pdo();
            foreach ($this->createdInvoiceIds as $id) {
                $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
            }
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    private function createWithDiscount(float $discountPercent, array $items): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $id = $this->repo->createDraft([
            'invoice_type'     => 'invoice',
            'client_id'        => $this->clientId,
            'issue_date'       => $today,
            'tax_date'         => $today,
            'due_date'         => $today,
            'currency_id'      => $this->currencyId,
            'reverse_charge'   => false,
            'language'         => 'cs',
            'discount_percent' => $discountPercent,
        ], $this->userId);
        $this->createdInvoiceIds[] = $id;
        $this->repo->replaceItems($id, $items);
        $this->calc->recompute($id);
        return $this->repo->find($id);
    }

    public function testDiscountMaterializesNegativeLineAndReducesTotals(): void
    {
        $r = $this->vatRate;
        $inv = $this->createWithDiscount(10.0, [[
            'description'            => 'TEST položka (PHPUnit)',
            'quantity'               => 1,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 1000,
            'vat_rate_id'            => $this->vatRateId,
            'order_index'            => 0,
        ]]);

        // Jedna standardní + jedna slevová položka
        $discountItems = array_values(array_filter($inv['items'], fn ($it) => $it['item_kind'] === 'discount'));
        $standardItems = array_values(array_filter($inv['items'], fn ($it) => $it['item_kind'] === 'standard'));
        $this->assertCount(1, $standardItems, 'Měla by být 1 standardní položka');
        $this->assertCount(1, $discountItems, 'Měla by vzniknout 1 slevová položka');

        // Slevová položka: -10 % ze základu 1000 = -100
        $this->assertEqualsWithDelta(-100.0, (float) $discountItems[0]['total_without_vat'], 0.001);
        $this->assertSame('discount', $discountItems[0]['item_kind']);

        // Totals PO slevě: základ 900, DPH = 900 * sazba, header discount_percent=10
        $this->assertEqualsWithDelta(900.0, (float) $inv['totals']['without_vat'], 0.001);
        $this->assertEqualsWithDelta(round(900.0 * $r / 100, 2), (float) $inv['totals']['vat'], 0.011);
        $this->assertEqualsWithDelta(10.0, (float) $inv['totals']['discount_percent'], 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $inv['totals']['discount_amount'], 0.001);

        // vat_breakdown základ je rovněž po slevě
        $this->assertEqualsWithDelta(900.0, (float) $inv['vat_breakdown'][0]['base'], 0.011);
    }

    public function testOssDiscountCopiesMetadataAndReducesReturnAmounts(): void
    {
        $hasOssColumns = $this->db->pdo()
            ->query("SHOW COLUMNS FROM invoice_items LIKE 'oss_applicable'")
            ->fetchColumn();
        if ($hasOssColumns === false) {
            $this->markTestSkipped('OSS migrace není aplikovaná');
        }

        $inv = $this->createWithDiscount(10.0, [[
            'description'                  => 'TEST OSS položka (PHPUnit)',
            'quantity'                     => 1,
            'unit'                         => 'ks',
            'unit_price_without_vat'       => 1000,
            'vat_rate_id'                  => $this->vatRateId,
            'order_index'                  => 0,
            'oss_applicable'               => true,
            'oss_consumer_country'         => 'DE',
            'oss_rate_type'                => 'standard',
            'oss_supply_type'              => 'goods',
            'oss_taxable_amount_return'    => 40.0,
            'oss_vat_amount_return'        => 7.6,
        ]]);

        $discountItems = array_values(array_filter($inv['items'], fn ($it) => $it['item_kind'] === 'discount'));
        $this->assertCount(1, $discountItems);
        $this->assertTrue($discountItems[0]['oss_applicable']);
        $this->assertSame('DE', $discountItems[0]['oss_consumer_country']);
        $this->assertSame('standard', $discountItems[0]['oss_rate_type']);
        $this->assertSame('goods', $discountItems[0]['oss_supply_type']);
        $this->assertEqualsWithDelta(-4.0, (float) $discountItems[0]['oss_taxable_amount_return'], 0.001);
        $this->assertEqualsWithDelta(-0.76, (float) $discountItems[0]['oss_vat_amount_return'], 0.001);
    }

    public function testZeroDiscountAddsNoDiscountLine(): void
    {
        $inv = $this->createWithDiscount(0.0, [[
            'description'            => 'TEST položka bez slevy (PHPUnit)',
            'quantity'               => 2,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 500,
            'vat_rate_id'            => $this->vatRateId,
            'order_index'            => 0,
        ]]);

        $discountItems = array_filter($inv['items'], fn ($it) => $it['item_kind'] === 'discount');
        $this->assertCount(0, $discountItems, 'Bez slevy nesmí vzniknout slevová položka');
        $this->assertEqualsWithDelta(1000.0, (float) $inv['totals']['without_vat'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $inv['totals']['discount_amount'], 0.001);
    }

    public function testVatRateValidityGuardRejectsExpiredRate(): void
    {
        $pdo = $this->db->pdo();
        $expired = $pdo->query(
            "SELECT id FROM vat_rates WHERE valid_to IS NOT NULL AND valid_to < CURDATE() ORDER BY id LIMIT 1"
        )->fetchColumn();
        if ($expired === false) {
            $this->markTestSkipped('Žádná vypršelá sazba v DB');
        }
        $this->expectException(\DomainException::class);
        \MyInvoice\Service\Invoice\VatRateValidityGuard::assertValidOn($pdo, [(int) $expired], date('Y-m-d'));
    }

    public function testVatRateValidityGuardPassesValidRate(): void
    {
        // Aktuálně platná sazba nesmí hodit výjimku.
        \MyInvoice\Service\Invoice\VatRateValidityGuard::assertValidOn($this->db->pdo(), [$this->vatRateId], date('Y-m-d'));
        $this->assertTrue(true);
    }

    public function testIncomingDiscountItemsAreIgnored(): void
    {
        // Pokud klient pošle „slevovou" položku ručně, repo ji zahodí — sleva se
        // generuje výhradně z discount_percent (žádné zdvojení).
        $inv = $this->createWithDiscount(10.0, [
            [
                'description'            => 'TEST položka (PHPUnit)',
                'quantity'               => 1,
                'unit'                   => 'ks',
                'unit_price_without_vat' => 1000,
                'vat_rate_id'            => $this->vatRateId,
                'order_index'            => 0,
            ],
            [
                'description'            => 'Podvržená sleva',
                'quantity'               => 1,
                'unit'                   => '',
                'unit_price_without_vat' => -999,
                'vat_rate_id'            => $this->vatRateId,
                'order_index'            => 1,
                'item_kind'              => 'discount',
            ],
        ]);

        $discountItems = array_values(array_filter($inv['items'], fn ($it) => $it['item_kind'] === 'discount'));
        $this->assertCount(1, $discountItems, 'Smí být jen 1 generovaná slevová položka');
        $this->assertEqualsWithDelta(-100.0, (float) $discountItems[0]['total_without_vat'], 0.001);
    }
}
