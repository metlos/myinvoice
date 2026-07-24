<?php

declare(strict_types=1);

namespace MyInvoice\Service\Validation;

use MyInvoice\Bootstrap;

/**
 * XSD validation pro EPO XML výkazy MFČR.
 *
 * Schémata jsou součástí `api/xsd/`, takže validace funguje offline. Pokud pro
 * některý form_code schéma chybí, vrátí se `status=skipped`; XML se i tak
 * archivuje a stáhne.
 */
final class XmlSchemaValidator
{
    /**
     * @return array{status: 'passed'|'failed'|'skipped', errors: list<string>}
     */
    public function validate(string $xml, string $formCode): array
    {
        $schemaPath = $this->resolveSchemaPath($formCode);
        if ($schemaPath === null || !is_file($schemaPath)) {
            return ['status' => 'skipped', 'errors' => []];
        }

        $errors = [];

        // PHP libxml errors collector
        libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($xml);
        if (!$loaded) {
            foreach (libxml_get_errors() as $err) {
                $errors[] = trim($err->message) . ' (line ' . $err->line . ')';
            }
            libxml_clear_errors();
            libxml_use_internal_errors(false);
            return ['status' => 'failed', 'errors' => $errors];
        }

        $valid = @$dom->schemaValidate($schemaPath);
        if (!$valid) {
            foreach (libxml_get_errors() as $err) {
                $errors[] = trim($err->message) . ' (line ' . $err->line . ', column ' . $err->column . ')';
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        return [
            'status' => $valid ? 'passed' : 'failed',
            'errors' => array_slice($errors, 0, 50), // cap pro DB JSON column size
        ];
    }

    /**
     * Zda je schema dostupné pro daný form_code (pro UI hint).
     */
    public function hasSchema(string $formCode): bool
    {
        $path = $this->resolveSchemaPath($formCode);
        return $path !== null && is_file($path);
    }

    /**
     * Whitelist form_code → XSD filename. Zároveň brání path injection (klíče
     * jsou fixní). EPO výkazy MFČR + ISDOC (formát faktur) — soubory commitnuté
     * v `api/xsd/` (public, ~400 KB celkem). Dřív byly v `storage/xsd/`
     * (gitignored), což si vynucovalo `cmd/download-xsd.sh` setup krok.
     */
    private const SCHEMA_FILES = [
        'dphdp3' => 'dphdp3.xsd',
        'dphkh1' => 'dphkh1.xsd',
        'dphshv' => 'dphshv.xsd',
        'ossei1' => 'ossei1.xsd',
        'dpfdp5' => 'dpfdp5.xsd',
        'dppdp9' => 'dppdp9.xsd',
        'isdoc'  => 'isdoc-invoice-6.0.2.xsd',
    ];

    private function resolveSchemaPath(string $formCode): ?string
    {
        $file = self::SCHEMA_FILES[$formCode] ?? null;
        if ($file === null) {
            return null;
        }
        return Bootstrap::rootDir() . '/api/xsd/' . $file;
    }
}
