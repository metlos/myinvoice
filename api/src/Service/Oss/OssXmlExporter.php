<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * XML builder pro OSS EU režim (OSSEI1) pro EPO.
 *
 * Implementuje běžné řádky dodávek (VetaR) i opravy minulých období (VetaO).
 */
final class OssXmlExporter
{
    public function __construct(
        private readonly Connection $db,
        private readonly OssLedgerService $ledger,
    ) {}

    /**
     * @return array{xml:string, summary:array<string,mixed>, warnings:list<string>}
     */
    public function build(int $supplierId, int $year, int $quarter): array
    {
        $preview = $this->ledger->preview($supplierId, $year, $quarter);
        if ((int) ($preview['summary']['invalid_correction_count'] ?? 0) > 0) {
            throw new \RuntimeException('OSS XML nelze vytvořit: opravte neplatná původní období uvedená v náhledu.');
        }
        if ((int) ($preview['summary']['conversion_missing_count'] ?? 0) > 0) {
            throw new \RuntimeException('OSS XML nelze vytvořit: pro některé řádky chybí přepočet do měny podání.');
        }
        $supplier = $this->loadSupplier($supplierId);
        $bank = $this->loadReturnBankAccount($supplierId, (string) ($preview['summary']['return_currency'] ?? 'EUR'));
        $warnings = $preview['warnings'] ?? [];

        $vatNumber = $this->vatRoot((string) ($supplier['dic'] ?? ''));
        if ($vatNumber === '') {
            $warnings[] = 'Chybí DIČ dodavatele. OSS XML vyžaduje kmenovou část DIČ.';
        }
        if (($preview['summary']['return_currency'] ?? 'EUR') !== 'EUR') {
            $warnings[] = 'EPO OSS očekává částky v EUR. Zkontrolujte měnu podání v nastavení OSS.';
        }
        if (($bank['iban'] ?? '') === '') {
            $warnings[] = 'Pro OSS XML není vyplněn IBAN účtu v měně podání.';
        }

        $rows = $this->aggregateRows($preview, $supplier, $warnings);
        $corrections = $this->correctionRows($preview, $warnings);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $pisemnost = $dom->createElement('Pisemnost');
        $pisemnost->setAttribute('nazevSW', 'MyInvoice.cz');
        $pisemnost->setAttribute('verzeSW', (string) ($this->loadAppVersion() ?? '0'));
        $dom->appendChild($pisemnost);

        $oss = $dom->createElement('OSSEI1');
        $oss->setAttribute('verzePis', '01.01.04');
        $pisemnost->appendChild($oss);

        $period = (array) $preview['period'];
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', 'OSS');
        $vetaD->setAttribute('dokument', 'EI1');
        $vetaD->setAttribute('year', (string) $year);
        $vetaD->setAttribute('quarter', (string) $quarter);
        $vetaD->setAttribute('trans', (!empty($rows) || !empty($corrections)) ? 'A' : 'N');
        $vetaD->setAttribute('company_name', (string) ($supplier['company_name'] ?? ''));
        $vetaD->setAttribute('vat_number', $vatNumber);
        $vetaD->setAttribute('electronic_interface', '0');
        $periodStart = (string) ($period['start'] ?? '');
        $periodEnd = (string) ($period['end'] ?? '');
        $validFrom = (string) ($preview['settings']['oss_valid_from'] ?? '');
        $validTo = (string) ($preview['settings']['oss_valid_to'] ?? '');
        if ($validFrom !== '' && $validFrom > $periodStart && $validFrom <= $periodEnd) {
            $vetaD->setAttribute('period_start_date', $validFrom);
        }
        if ($validTo !== '' && $validTo >= $periodStart && $validTo < $periodEnd) {
            $vetaD->setAttribute('period_end_date', $validTo);
        }
        if (($bank['iban'] ?? '') !== '') {
            $vetaD->setAttribute('iban_code', (string) $bank['iban']);
        }
        if (($bank['bic'] ?? '') !== '') {
            $vetaD->setAttribute('bic_code', (string) $bank['bic']);
        }
        $vetaD->setAttribute('account_name', (string) ($supplier['company_name'] ?? ''));
        $oss->appendChild($vetaD);

        $vetaP = $dom->createElement('VetaP');
        $vetaP->setAttribute('dic', $vatNumber);
        $oss->appendChild($vetaP);

        foreach ($rows as $r) {
            $vetaR = $dom->createElement('VetaR');
            $vetaR->setAttribute('country', $r['country']);
            $vetaR->setAttribute('state_consumption', $r['state_consumption']);
            $vetaR->setAttribute('supply_type_code', $r['supply_type_code']);
            $vetaR->setAttribute('taxable_amount', $this->formatMoney($r['taxable_amount']));
            $vetaR->setAttribute('vat_amount', $this->formatMoney($r['vat_amount']));
            $vetaR->setAttribute('vat_rate', $this->formatRate($r['vat_rate']));
            $vetaR->setAttribute('vat_rate_type_code', $r['vat_rate_type_code']);
            if ($vatNumber !== '') {
                $vetaR->setAttribute('vat_number', $vatNumber);
            }
            $oss->appendChild($vetaR);
        }

        foreach ($corrections as $correction) {
            $vetaO = $dom->createElement('VetaO');
            $vetaO->setAttribute('correction', $this->formatMoney($correction['correction']));
            $vetaO->setAttribute('quarter', (string) $correction['quarter']);
            $vetaO->setAttribute('state_consumption', $correction['state_consumption']);
            $vetaO->setAttribute('year', (string) $correction['year']);
            $oss->appendChild($vetaO);
        }

        $xml = $dom->saveXML() ?: '';
        $summary = [
            'period' => sprintf('%04d-Q%d', $year, $quarter),
            'form_code' => 'ossei1',
            'scheme' => 'eu',
            'return_currency' => $preview['summary']['return_currency'] ?? 'EUR',
            'rows_count' => count($rows),
            'corrections_count' => count($corrections),
            'total_base' => round(array_sum(array_column($rows, 'taxable_amount')), 2),
            'total_vat' => round(array_sum(array_column($rows, 'vat_amount')), 2),
            'total_corrections' => round(array_sum(array_column($corrections, 'correction')), 2),
            'invoice_count' => $preview['summary']['invoice_count'] ?? 0,
            'submission_deadline' => $period['submission_deadline'] ?? null,
            'warnings' => array_values(array_unique($warnings)),
        ];
        $summary['total_payable'] = round($summary['total_vat'] + $summary['total_corrections'], 2);

        return [
            'xml' => $xml,
            'summary' => $summary,
            'warnings' => $summary['warnings'],
        ];
    }

    /**
     * @param array<string,mixed> $preview
     * @param array<string,mixed> $supplier
     * @param list<string> $warnings
     * @return list<array{country:string,state_consumption:string,supply_type_code:string,taxable_amount:float,vat_amount:float,vat_rate:float,vat_rate_type_code:string}>
     */
    private function aggregateRows(array $preview, array $supplier, array &$warnings): array
    {
        $supplierCountry = strtoupper((string) ($supplier['country_iso2'] ?? 'CZ'));
        $groups = [];
        foreach (($preview['countries'] ?? []) as $country) {
            foreach (($country['rows'] ?? []) as $row) {
                $state = strtoupper((string) ($row['oss_consumer_country'] ?? $row['country'] ?? $country['country'] ?? ''));
                if ($state === '' || $state === '??') {
                    throw new \RuntimeException('OSS XML nelze vytvořit: některý řádek nemá zemi spotřeby.');
                }

                $supplyType = $this->supplyTypeCode($row['supply_type'] ?? null);
                if ($supplyType === null) {
                    throw new \RuntimeException('OSS XML nelze vytvořit: některý řádek nemá platný typ plnění.');
                }

                $rateType = $this->rateTypeCode($row['rate_type'] ?? null);
                if ($rateType === null) {
                    throw new \RuntimeException('OSS XML nelze vytvořit: některý řádek nemá platný typ sazby.');
                }

                $rate = (float) ($row['vat_rate'] ?? 0.0);
                $key = implode('|', [$supplierCountry, $state, $supplyType, $rateType, $this->formatRate($rate)]);
                $groups[$key] ??= [
                    'country' => $supplierCountry,
                    'state_consumption' => $state,
                    'supply_type_code' => $supplyType,
                    'taxable_amount' => 0.0,
                    'vat_amount' => 0.0,
                    'vat_rate' => $rate,
                    'vat_rate_type_code' => $rateType,
                ];
                $groups[$key]['taxable_amount'] += (float) ($row['base_return'] ?? 0.0);
                $groups[$key]['vat_amount'] += (float) ($row['vat_return'] ?? 0.0);
            }
        }

        $rows = array_values($groups);
        usort($rows, static fn (array $a, array $b): int =>
            [$a['state_consumption'], $a['supply_type_code'], $a['vat_rate_type_code'], $a['vat_rate']]
            <=> [$b['state_consumption'], $b['supply_type_code'], $b['vat_rate_type_code'], $b['vat_rate']]
        );
        return $rows;
    }

    /**
     * @param array<string,mixed> $preview
     * @param list<string> $warnings
     * @return list<array{year:int,quarter:int,state_consumption:string,correction:float}>
     */
    private function correctionRows(array $preview, array &$warnings): array
    {
        $rows = [];
        foreach (($preview['corrections'] ?? []) as $correction) {
            $state = strtoupper((string) ($correction['state_consumption'] ?? ''));
            $year = (int) ($correction['year'] ?? 0);
            $quarter = (int) ($correction['quarter'] ?? 0);
            if (!preg_match('/^[A-Z]{2}$/', $state) || $year < 2021 || $quarter < 1 || $quarter > 4) {
                $warnings[] = 'OSS XML vynechal opravu s neplatným obdobím nebo zemí spotřeby.';
                continue;
            }
            if ($year === 2021 && $quarter < 3) {
                $warnings[] = 'OSS XML vynechal opravu období před Q3 2021.';
                continue;
            }
            $amount = (float) ($correction['correction'] ?? 0.0);
            if (abs($amount) < 0.005) {
                $warnings[] = 'OSS XML vynechal nulovou souhrnnou opravu pro ' . $state . '.';
                continue;
            }
            $rows[] = [
                'year' => $year,
                'quarter' => $quarter,
                'state_consumption' => $state,
                'correction' => $amount,
            ];
        }
        return $rows;
    }

    private function supplyTypeCode(mixed $value): ?string
    {
        return match ((string) $value) {
            'goods' => 'G',
            'services' => 'S',
            default => null,
        };
    }

    private function rateTypeCode(mixed $value): ?string
    {
        return match ((string) $value) {
            'standard' => 'Z',
            'reduced', 'second_reduced', 'parking' => 'S',
            default => null,
        };
    }

    /** @return array<string,mixed> */
    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.dic, COALESCE(c.iso2, 'CZ') AS country_iso2
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Supplier #{$supplierId} nenalezen.");
        }
        return $row;
    }

    /** @return array{iban:string,bic:string} */
    private function loadReturnBankAccount(int $supplierId, string $currency): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT iban, bic
               FROM currencies
              WHERE supplier_id = ?
                AND code = ?
                AND is_active = 1
           ORDER BY is_default DESC, id
              LIMIT 1"
        );
        $stmt->execute([$supplierId, strtoupper($currency)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'iban' => strtoupper(preg_replace('/\s+/', '', (string) ($row['iban'] ?? '')) ?? ''),
            'bic' => strtoupper(preg_replace('/\s+/', '', (string) ($row['bic'] ?? '')) ?? ''),
        ];
    }

    private function vatRoot(string $dic): string
    {
        $dic = strtoupper(trim($dic));
        $dic = preg_replace('/^CZ/', '', $dic) ?? $dic;
        return preg_replace('/\D/', '', $dic) ?? '';
    }

    private function formatMoney(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }

    private function formatRate(float $rate): string
    {
        return number_format(round($rate, 2), 2, '.', '');
    }

    private function loadAppVersion(): ?string
    {
        $verFile = __DIR__ . '/../../../../VERSION';
        return is_file($verFile) ? trim((string) file_get_contents($verFile)) : null;
    }
}
