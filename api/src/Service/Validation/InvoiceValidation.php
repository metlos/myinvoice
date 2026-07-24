<?php

declare(strict_types=1);

namespace MyInvoice\Service\Validation;

use MyInvoice\Service\Oss\OssPeriod;

final class InvoiceValidation
{
    /**
     * @param array<int, float>|null $vatRates
     * @param array<int, string>|null $vatRateCountries sazba → stát, pro kontrolu zahraničních sazeb mimo OSS
     * @return array<string, string[]>
     */
    public static function invoice(array $data, ?array $vatRates = null, ?array $vatRateCountries = null): array
    {
        $err = [];

        $type = (string) ($data['invoice_type'] ?? 'invoice');
        if (!in_array($type, ['invoice', 'proforma', 'credit_note', 'cancellation', 'tax_document'], true)) {
            $err['invoice_type'][] = 'Neplatný typ dokladu';
        }

        if (array_key_exists('payment_method', $data) && $data['payment_method'] !== null && $data['payment_method'] !== '') {
            $pm = (string) $data['payment_method'];
            if (!in_array($pm, ['bank_transfer', 'card', 'cash', 'other'], true)) {
                $err['payment_method'][] = 'Neplatný způsob úhrady';
            }
        }

        if (empty($data['client_id']) || !is_numeric($data['client_id'])) {
            $err['client_id'][] = 'Klient je povinný';
        }

        if (isset($data['currency_id']) && (int) $data['currency_id'] <= 0) {
            $err['currency_id'][] = 'Neplatné currency_id';
        }

        if (!empty($data['issue_date']) && !self::isValidDate((string) $data['issue_date'])) {
            $err['issue_date'][] = 'Neplatné datum vystavení';
        }
        if (!empty($data['due_date']) && !self::isValidDate((string) $data['due_date'])) {
            $err['due_date'][] = 'Neplatné datum splatnosti';
        }
        if ($type !== 'proforma' && !empty($data['tax_date']) && !self::isValidDate((string) $data['tax_date'])) {
            $err['tax_date'][] = 'Neplatné DUZP';
        }

        $items = $data['items'] ?? [];
        if (!is_array($items)) {
            $err['items'][] = 'items musí být pole';
        } else {
            foreach (array_values($items) as $i => $item) {
                if (!is_array($item)) {
                    $err["items.{$i}"][] = 'Neplatná položka';
                    continue;
                }
                $err = array_merge($err, InvoiceAmountPolicy::validateItem($item, $i));

                // Zahraniční sazba smí být jen na OSS řádku. Bez téhle kontroly by řádek s cizí
                // sazbou zůstal v tuzemské evidenci (VatLedgerService) a podle prahu roku by se
                // zařadil do české klasifikace — DE 19 % by se vykázalo na snížené sazbě DPHDP3.
                if ($vatRateCountries !== null && empty($item['oss_applicable']) && !empty($item['vat_rate_id'])) {
                    $rateCountry = $vatRateCountries[(int) $item['vat_rate_id']] ?? null;
                    if ($rateCountry !== null && $rateCountry !== 'CZ') {
                        $err["items.{$i}.vat_rate_id"][] = 'Zahraniční sazbu DPH lze použít jen na řádku v režimu OSS';
                    }
                }

                if (!empty($item['oss_applicable'])) {
                    $country = strtoupper(trim((string) ($item['oss_consumer_country'] ?? '')));
                    if (!preg_match('/^[A-Z]{2}$/', $country)) {
                        $err["items.{$i}.oss_consumer_country"][] = 'Země spotřeby musí být dvoupísmenný ISO kód';
                    }

                    $rateType = (string) ($item['oss_rate_type'] ?? '');
                    if (!in_array($rateType, ['standard', 'reduced', 'second_reduced', 'parking'], true)) {
                        $err["items.{$i}.oss_rate_type"][] = 'Neplatný typ OSS sazby';
                    }
                    $supplyType = (string) ($item['oss_supply_type'] ?? '');
                    if (!in_array($supplyType, ['goods', 'services'], true)) {
                        $err["items.{$i}.oss_supply_type"][] = 'Typ OSS plnění musí být zboží nebo služba';
                    }

                    $rateValue = $item['oss_exchange_rate'] ?? null;
                    if ($rateValue !== null && $rateValue !== '') {
                        if (!is_numeric($rateValue) || !is_finite((float) $rateValue)
                            || (float) $rateValue <= 0 || (float) $rateValue > 1000000
                        ) {
                            $err["items.{$i}.oss_exchange_rate"][] = 'OSS kurz musí být kladné číslo v podporovaném rozsahu';
                        }
                    }
                    $rateDate = (string) ($item['oss_exchange_rate_date'] ?? '');
                    if ($rateDate !== '' && !self::isValidDate($rateDate)) {
                        $err["items.{$i}.oss_exchange_rate_date"][] = 'Neplatné datum OSS kurzu';
                    }

                    $manualAmounts = [];
                    foreach (['oss_taxable_amount_return', 'oss_vat_amount_return'] as $field) {
                        $value = $item[$field] ?? null;
                        $manualAmounts[$field] = $value !== null && $value !== '';
                        if ($manualAmounts[$field]
                            && (!is_numeric($value) || !is_finite((float) $value) || abs((float) $value) > 999999999999.99)
                        ) {
                            $err["items.{$i}.{$field}"][] = 'OSS částka je mimo podporovaný rozsah';
                        }
                    }
                    if ($manualAmounts['oss_taxable_amount_return'] !== $manualAmounts['oss_vat_amount_return']) {
                        $err["items.{$i}.oss_taxable_amount_return"][] = 'Ruční OSS základ a DPH musí být vyplněny společně';
                    }

                    $originalPeriod = strtoupper(trim((string) ($item['oss_original_period'] ?? '')));
                    if ($originalPeriod !== '') {
                        if (!preg_match('/^[0-9]{4}Q[1-4]$/', $originalPeriod) || $originalPeriod < '2021Q3') {
                            $err["items.{$i}.oss_original_period"][] = 'Původní OSS období musí být ve formátu RRRRQn a nejdříve Q3 2021';
                        } else {
                            $taxDate = (string) ($data['tax_date'] ?? $data['issue_date'] ?? '');
                            $currentPeriod = OssPeriod::quarterCode($taxDate);
                            if ($currentPeriod !== null && $originalPeriod >= $currentPeriod) {
                                $err["items.{$i}.oss_original_period"][] = 'Původní OSS období musí předcházet období dokladu';
                            }
                        }
                    }
                }
            }
        }

        $advance = (float) ($data['advance_paid_amount'] ?? 0);
        if ($advance < 0) {
            $err['advance_paid_amount'][] = 'Záloha nesmí být záporná';
        }

        if (array_key_exists('discount_percent', $data) && $data['discount_percent'] !== null && $data['discount_percent'] !== '') {
            if (!is_numeric($data['discount_percent'])) {
                $err['discount_percent'][] = 'Sleva musí být číslo';
            } else {
                $d = (float) $data['discount_percent'];
                if ($d < 0 || $d > 100) {
                    $err['discount_percent'][] = 'Sleva musí být mezi 0 a 100 %';
                }
            }
        }

        if ($vatRates !== null) {
            $amountError = InvoiceAmountPolicy::validatePositiveAmountToPay($data, $vatRates);
            if ($amountError !== null) {
                $err['amount_to_pay'][] = $amountError;
            }
        }

        // Volitelný manuální varsymbol u draftu (override automatického číslování).
        // Prázdný / chybějící = generuje se při issue. Max 20 znaků (DB limit).
        if (array_key_exists('varsymbol', $data) && $data['varsymbol'] !== null && $data['varsymbol'] !== '') {
            $vs = (string) $data['varsymbol'];
            if (strlen($vs) > 20) {
                $err['varsymbol'][] = 'Číslo faktury má max 20 znaků';
            }
            if (preg_match('/[\x00-\x1f\x7f]/', $vs)) {
                $err['varsymbol'][] = 'Číslo faktury obsahuje neplatné znaky';
            }
        }

        return $err;
    }

    private static function isValidDate(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
