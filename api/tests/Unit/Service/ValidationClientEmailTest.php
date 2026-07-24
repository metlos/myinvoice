<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service;

use MyInvoice\Service\Validation;
use PHPUnit\Framework\TestCase;

/**
 * Hlavní e-mail klienta je nepovinný (#221).
 *
 * Historické doklady e-mail protistrany často nemají. Validace ho proto
 * nesmí vyžadovat — ale když vyplněný je, musí být platný, jinak by se
 * neodesílatelná adresa protáhla až do upomínek a odesílání faktur.
 */
final class ValidationClientEmailTest extends TestCase
{
    /** Povinná pole mimo e-mail, ať testy izolují jen main_email. */
    private function baseClient(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Testovací s.r.o.',
            'street'       => 'Dlouhá 1',
            'city'         => 'Praha',
            'zip'          => '11000',
        ], $overrides);
    }

    public function testMissingEmailKeyIsValid(): void
    {
        $err = Validation::client($this->baseClient());
        $this->assertArrayNotHasKey('main_email', $err);
    }

    public function testEmptyEmailIsValid(): void
    {
        $err = Validation::client($this->baseClient(['main_email' => '']));
        $this->assertArrayNotHasKey('main_email', $err);
    }

    public function testWhitespaceOnlyEmailIsValid(): void
    {
        $err = Validation::client($this->baseClient(['main_email' => '   ']));
        $this->assertArrayNotHasKey('main_email', $err);
    }

    public function testNullEmailIsValid(): void
    {
        $err = Validation::client($this->baseClient(['main_email' => null]));
        $this->assertArrayNotHasKey('main_email', $err);
    }

    public function testValidEmailPasses(): void
    {
        $err = Validation::client($this->baseClient(['main_email' => 'faktury@example.com']));
        $this->assertArrayNotHasKey('main_email', $err);
    }

    public function testInvalidEmailStillFails(): void
    {
        $err = Validation::client($this->baseClient(['main_email' => 'neni-email']));
        $this->assertArrayHasKey('main_email', $err);
    }

    /** Ostatní povinná pole zůstávají povinná — uvolnění se týká jen e-mailu. */
    public function testOtherRequiredFieldsUnaffected(): void
    {
        $err = Validation::client(['main_email' => 'a@b.cz']);
        $this->assertArrayHasKey('company_name', $err);
        $this->assertArrayHasKey('street', $err);
        $this->assertArrayHasKey('city', $err);
        $this->assertArrayHasKey('zip', $err);
    }
}
