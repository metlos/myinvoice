<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\InvoiceRepository;

/**
 * Jediný vlastník URL formátu web faktury `/invoice/{token}` (vzor
 * WorkReportLinkService::publicUrl) — používá ho management endpoint
 * (PublicLinkAction) i e-mail (InvoiceEmailVarsBuilder). Cesta musí ladit
 * s Vue routou ve web/src/router/index.ts.
 */
final class InvoicePublicLinkService
{
    public function __construct(
        private readonly Config $config,
        private readonly InvoiceRepository $invoices,
    ) {}

    /**
     * URL pro daný token. Bez nakonfigurovaného app.url vrací relativní cestu —
     * UI („kopírovat odkaz") tak vždy něco dostane; e-mailová cesta absolutnost
     * hlídá v ensureUrl().
     */
    public function url(string $token): string
    {
        return rtrim((string) $this->config->get('app.url', ''), '/') . '/invoice/' . $token;
    }

    /**
     * Absolutní URL web faktury pro e-mail; token vytvoří lazy při prvním
     * použití. Null pro draft (veřejná stránka koncepty nezobrazuje) a bez
     * app.url (relativní odkaz je v e-mailu k ničemu).
     */
    public function ensureUrl(array $invoice): ?string
    {
        if (($invoice['status'] ?? '') === 'draft') {
            return null;
        }
        if (rtrim((string) $this->config->get('app.url', ''), '/') === '') {
            return null;
        }
        $token = (string) ($invoice['public_token'] ?? '');
        if ($token === '') {
            $token = $this->invoices->ensurePublicToken((int) $invoice['id']);
        }
        return $this->url($token);
    }
}
