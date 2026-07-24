<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Invoice;

use MyInvoice\Action\Invoice\PublicInvoiceGetAction;
use PHPUnit\Framework\TestCase;

/**
 * Web faktura (migrace 0134) — whitelist veřejného payloadu.
 *
 * buildPayload() je jediné místo, které rozhoduje, co veřejný endpoint
 * GET /api/public/invoice/{token} prozradí. Test hlídá, že se do payloadu
 * NEdostanou tokeny, snapshoty, interní vazby ani kontaktní údaje klienta —
 * a naopak že pole potřebná pro vykreslení faktury projdou.
 *
 * Čistě unit — buildPayload je pure static function (bez DB).
 */
final class PublicInvoicePayloadTest extends TestCase
{
    /** Řádek faktury tak, jak ho vrací InvoiceRepository::find() — vč. interních polí. */
    private function invoiceRow(): array
    {
        return [
            'id'                 => 42,
            'supplier_id'        => 1,
            'client_id'          => 7,
            'project_id'         => 3,
            'created_by'         => 1,
            'varsymbol'          => '20260042',
            'invoice_type'       => 'invoice',
            'status'             => 'sent',
            'payment_status'     => 'unpaid',
            'language'           => 'cs',
            'currency'           => 'CZK',
            'currency_symbol'    => 'Kč',
            'currency_decimals'  => 2,
            'issue_date'         => '2026-07-01',
            'tax_date'           => '2026-07-01',
            'due_date'           => '2026-07-15',
            'paid_at'            => null,
            'payment_method'     => 'bank_transfer',
            'reverse_charge'     => false,
            'prices_include_vat' => false,
            'note_above_items'   => 'Fakturujeme Vám za služby.',
            'note_below_items'   => null,
            'amount_to_pay'      => 12100.0,
            'paid_total'         => 0.0,
            'advance_paid_amount'=> 0.0,
            'total_without_vat'  => 10000.0,
            'total_vat'          => 2100.0,
            'total_with_vat'     => 12100.0,
            'rounding'           => 0.0,
            'totals'             => ['without_vat' => 10000.0, 'vat' => 2100.0, 'with_vat' => 12100.0],
            'vat_breakdown'      => [['rate' => 21.0, 'base' => 10000.0, 'vat' => 2100.0, 'total' => 12100.0]],
            'czk_recap'          => null,
            // interní pole, která NESMÍ projít ven
            'approval_token'     => str_repeat('a', 48),
            'public_token'       => str_repeat('b', 48),
            'public_viewed_at'   => null,
            'client_main_email'  => 'klient@example.com',
            'client_snapshot'    => '{"company_name":"Klient s.r.o."}',
            'supplier_snapshot'  => '{"company_name":"Dodavatel s.r.o."}',
            'bank_snapshot'      => '{"account_number":"1000000005"}',
            'pdf_path'           => 'storage/pdf/faktura.pdf',
            'items'              => [[
                'id'                     => 1001,
                'invoice_id'             => 42,
                'description'            => 'Vývoj software',
                'quantity'               => 10.0,
                'unit'                   => 'hod',
                'unit_price_without_vat' => 1000.0,
                'vat_rate_id'            => 1,
                'vat_rate_snapshot'      => 21.0,
                'total_without_vat'      => 10000.0,
                'total_vat'              => 2100.0,
                'total_with_vat'         => 12100.0,
                'order_index'            => 0,
                'item_kind'              => 'standard',
                'linked_work_report_id'  => 55,
            ]],
        ];
    }

    private function payload(): array
    {
        return PublicInvoiceGetAction::buildPayload(
            $this->invoiceRow(),
            [
                'company_name' => 'Dodavatel s.r.o.',
                'ic'           => '12345678',
                'dic'          => 'CZ12345678',
                'is_vat_payer' => 1,
                'street'       => 'Ulice 1',
                'city'         => 'Praha',
                'zip'          => '11000',
                'email'        => 'info@dodavatel.cz',
                // interní / citlivá pole z supplier řádku
                'id'                     => 1,
                'logo_path'              => 'storage/logo/1.png',
                'email_branding_enabled' => 1,
                'smtp_password'          => 'tajne',
            ],
            [
                'company_name' => 'Klient s.r.o.',
                'ic'           => '87654321',
                'street'       => 'Náměstí 2',
                'city'         => 'Brno',
                'zip'          => '60200',
                // interní pole z clients řádku (resolveClient dělá SELECT c.*)
                'id'           => 7,
                'main_email'   => 'klient@example.com',
                'phone'        => '+420777888999',
                'note'         => 'interní poznámka ke klientovi',
            ],
            ['account_number' => '1000000005', 'bank_code' => '0100', 'bank_name' => 'KB', 'iban' => null, 'bic' => null],
            'data:image/png;base64,QR',
            [[
                'id'            => 9,
                'invoice_id'    => 42,
                'filename'      => 'att_9_abcdef.pdf',
                'original_name' => 'Smlouva.pdf',
                'size_bytes'    => 12345,
                'sha256'        => str_repeat('c', 64),
                'mime_type'     => 'application/pdf',
                'uploaded_by'   => 1,
                'uploaded_at'   => '2026-07-01 10:00:00',
            ]],
        );
    }

    public function testContainsFieldsNeededForRendering(): void
    {
        $p = $this->payload();

        self::assertSame('20260042', $p['invoice']['varsymbol']);
        self::assertSame('sent', $p['invoice']['status']);
        self::assertSame(12100.0, $p['invoice']['amount_to_pay']);
        self::assertSame('Vývoj software', $p['invoice']['items'][0]['description']);
        self::assertSame(21.0, $p['invoice']['items'][0]['vat_rate_snapshot']);
        self::assertNotEmpty($p['invoice']['vat_breakdown']);
        self::assertSame('Dodavatel s.r.o.', $p['supplier']['company_name']);
        self::assertSame('CZ12345678', $p['supplier']['dic']);
        self::assertSame('Klient s.r.o.', $p['client']['company_name']);
        self::assertSame('1000000005', $p['bank']['account_number']);
        self::assertSame('data:image/png;base64,QR', $p['qr_data_uri']);
    }

    public function testDoesNotLeakInternalInvoiceFields(): void
    {
        $inv = $this->payload()['invoice'];

        foreach ([
            'id', 'supplier_id', 'client_id', 'project_id', 'created_by',
            'approval_token', 'public_token', 'public_viewed_at',
            'client_main_email', 'client_snapshot', 'supplier_snapshot',
            'bank_snapshot', 'pdf_path',
        ] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $inv, "Interní pole '$forbidden' nesmí být ve veřejném payloadu");
        }

        foreach (['id', 'invoice_id', 'vat_rate_id', 'linked_work_report_id'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $inv['items'][0], "Interní pole položky '$forbidden' nesmí být ve veřejném payloadu");
        }
    }

    public function testDoesNotLeakInternalPartyFields(): void
    {
        $p = $this->payload();

        foreach (['id', 'logo_path', 'email_branding_enabled', 'smtp_password'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $p['supplier'], "Pole dodavatele '$forbidden' nesmí být ve veřejném payloadu");
        }
        foreach (['id', 'main_email', 'phone', 'note'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $p['client'], "Pole klienta '$forbidden' nesmí být ve veřejném payloadu");
        }
    }

    public function testAttachmentsWhitelisted(): void
    {
        $atts = $this->payload()['attachments'];

        self::assertCount(1, $atts);
        self::assertSame('Smlouva.pdf', $atts[0]['original_name']);
        self::assertSame(9, $atts[0]['id']);
        self::assertSame(12345, $atts[0]['size_bytes']);
        foreach (['filename', 'sha256', 'uploaded_by', 'uploaded_at', 'invoice_id', 'mime_type'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $atts[0], "Pole přílohy '$forbidden' nesmí být ve veřejném payloadu");
        }
    }

    public function testBankNullPassesThrough(): void
    {
        $p = PublicInvoiceGetAction::buildPayload($this->invoiceRow(), [], [], null, null);
        self::assertNull($p['bank']);
        self::assertNull($p['qr_data_uri']);
        self::assertSame([], $p['attachments']);
    }
}
