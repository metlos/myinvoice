<?php

declare(strict_types=1);

namespace MyInvoice\Service;

/**
 * Lehké validační helpery — vrací array chyb (prázdné = OK).
 */
final class Validation
{
    /**
     * @return array<string, string[]>
     */
    public static function client(array $data): array
    {
        $err = [];

        if (empty($data['company_name']) || !is_string($data['company_name']) || trim($data['company_name']) === '') {
            $err['company_name'][] = 'Firma / jméno je povinné';
        }
        if (empty($data['street']) || trim((string) $data['street']) === '') {
            $err['street'][] = 'Ulice je povinná';
        }
        if (empty($data['city']) || trim((string) $data['city']) === '') {
            $err['city'][] = 'Město je povinné';
        }
        if (empty($data['zip']) || trim((string) $data['zip']) === '') {
            $err['zip'][] = 'PSČ je povinné';
        }
        // Hlavní e-mail je nepovinný (#221) — historické doklady ho často nemají.
        // Když ale vyplněný je, musí být platný.
        $mainEmail = trim((string) ($data['main_email'] ?? ''));
        if ($mainEmail !== '' && !filter_var($mainEmail, FILTER_VALIDATE_EMAIL)) {
            $err['main_email'][] = 'Hlavní email musí být platný';
        }
        if (!empty($data['phone']) && strlen((string) $data['phone']) > 40) {
            $err['phone'][] = 'Telefon je příliš dlouhý';
        }
        if (!empty($data['ic']) && !preg_match('/^\d{8}$/', (string) $data['ic'])) {
            $err['ic'][] = 'IČO musí mít 8 číslic';
        }
        $lang = $data['language'] ?? 'cs';
        if (!in_array($lang, ['cs', 'en'], true)) {
            $err['language'][] = 'Jazyk musí být cs nebo en';
        }
        $curId = $data['currency_default_id'] ?? null;
        if ($curId !== null && (!is_numeric($curId) || (int) $curId <= 0)) {
            $err['currency_default_id'][] = 'Neplatné currency_default_id';
        }
        if (isset($data['hourly_rate']) && (float) $data['hourly_rate'] < 0) {
            $err['hourly_rate'][] = 'Hodinová sazba nesmí být záporná';
        }
        return $err;
    }

    /**
     * @return array<string, string[]>
     */
    public static function project(array $data): array
    {
        $err = [];

        if (empty($data['client_id']) || !is_numeric($data['client_id'])) {
            $err['client_id'][] = 'Klient je povinný';
        }
        if (empty($data['name']) || trim((string) $data['name']) === '') {
            $err['name'][] = 'Název zakázky je povinný';
        }
        $due = (int) ($data['payment_due_days'] ?? 0);
        if ($due < 1 || $due > 365) {
            $err['payment_due_days'][] = 'Splatnost musí být 1–365 dní';
        }
        if (isset($data['hourly_rate']) && (float) $data['hourly_rate'] < 0) {
            $err['hourly_rate'][] = 'Hodinová sazba nesmí být záporná';
        }
        // Akceptujeme buď currency_id (preferováno) nebo legacy currency code (resolveCurrencyId si poradí)
        if (isset($data['currency_id']) && (int) $data['currency_id'] <= 0) {
            $err['currency_id'][] = 'Neplatné currency_id';
        }
        $status = $data['status'] ?? 'active';
        if (!in_array($status, ['active', 'paused', 'closed'], true)) {
            $err['status'][] = 'Status musí být active, paused nebo closed';
        }

        // Billing emails (0..3)
        $emails = $data['billing_emails'] ?? [];
        if (!is_array($emails)) {
            $err['billing_emails'][] = 'billing_emails musí být pole';
        } elseif (count($emails) > 3) {
            $err['billing_emails'][] = 'Maximálně 3 fakturační emaily';
        } else {
            foreach ($emails as $i => $entry) {
                if (!is_array($entry)) continue;
                $em = (string) ($entry['email'] ?? '');
                if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                    $err["billing_emails.{$i}"][] = 'Neplatný email';
                }
            }
        }

        return $err;
    }
}
