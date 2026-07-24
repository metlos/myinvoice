<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\EpoSupplierBlockBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rozdělení jména fyzické osoby (OSVČ) na jmeno/prijmeni pro EPO VetaP, vč. odstranění
 * akademických titulů (issue #200 — „MUDr. Josef Novák" nesmí dát jmeno="MUDr.").
 */
final class EpoSupplierBlockBuilderTest extends TestCase
{
    /**
     * @return iterable<string, array{0:string, 1:string, 2:string}>
     */
    public static function names(): iterable
    {
        yield 'prostý' => ['Josef Novák', 'Josef', 'Novák'];
        yield 'vedoucí titul' => ['MUDr. Josef Novák', 'Josef', 'Novák'];
        yield 'více vedoucích titulů' => ['prof. Ing. Jan Svoboda', 'Jan', 'Svoboda'];
        yield 'koncový titul za čárkou' => ['Ing. Jan Svoboda, CSc.', 'Jan', 'Svoboda'];
        yield 'vedoucí i koncový titul' => ['MUDr. Josef Novák, Ph.D.', 'Josef', 'Novák'];
        yield 'koncový titul bez tečky' => ['Jan Svoboda, MBA', 'Jan', 'Svoboda'];
        yield 'víceslovné příjmení' => ['Josef Karel Novák', 'Josef', 'Karel Novák'];
        yield 'jen jedno slovo' => ['Novák', 'Novák', 'Novák'];
        yield 'jen titul + jméno' => ['Bc. Anna', 'Anna', 'Anna'];
        yield 'extra mezery' => ['  Ing.   Petr   Dvořák ', 'Petr', 'Dvořák'];
        yield 'prázdné' => ['', '', ''];
    }

    #[DataProvider('names')]
    public function testSplitPersonNameStripsTitles(string $full, string $expJmeno, string $expPrijmeni): void
    {
        [$jmeno, $prijmeni] = EpoSupplierBlockBuilder::splitPersonName($full);
        $this->assertSame($expJmeno, $jmeno, "jmeno pro „{$full}\"");
        $this->assertSame($expPrijmeni, $prijmeni, "prijmeni pro „{$full}\"");
    }

    /** Fyzická osoba bez strukturovaných polí → fallback split company_name bez titulu (#200). */
    public function testFyzickaOsobaFallbackFromCompanyName(): void
    {
        $v = $this->fillFor(['taxpayer_type' => 'fo', 'company_name' => 'MUDr. Josef Novák']);
        $this->assertSame('Josef', $v->getAttribute('jmeno'));
        $this->assertSame('Novák', $v->getAttribute('prijmeni'));
        $this->assertSame('', $v->getAttribute('zkrobchjm'), 'fyzická osoba nemá zkrobchjm');
    }

    /** Strukturovaná pole (jako u jednatele s.r.o.) mají přednost před parsováním jména. */
    public function testFyzickaOsobaPrefersStructuredNames(): void
    {
        $v = $this->fillFor([
            'taxpayer_type' => 'fo',
            'company_name'  => 'MUDr. Josef Novák',
            'opr_jmeno'     => 'Josef',
            'opr_prijmeni'  => 'Novák',
        ]);
        $this->assertSame('Josef', $v->getAttribute('jmeno'));
        $this->assertSame('Novák', $v->getAttribute('prijmeni'));
    }

    /** Právnická osoba → celý název do zkrobchjm, jmeno/prijmeni prázdné. */
    public function testPravnickaOsobaUsesCompanyName(): void
    {
        $v = $this->fillFor(['taxpayer_type' => 'po', 'company_name' => 'ACME s.r.o.']);
        $this->assertSame('ACME s.r.o.', $v->getAttribute('zkrobchjm'));
        $this->assertSame('', $v->getAttribute('jmeno'));
    }

    /** #201 — `stat` musí být NÁZEV státu z číselníku (naz_zeme_c25), ne ISO2 kód „CZ". */
    public function testStatUsesCountryNameNotIsoCode(): void
    {
        $v = $this->fillFor(['country_iso2' => 'CZ']);
        $this->assertSame('ČESKÁ REPUBLIKA', $v->getAttribute('stat'));
    }

    /** Prázdný country_iso2 → default ČR. */
    public function testStatDefaultsToCzechRepublic(): void
    {
        $v = $this->fillFor(['country_iso2' => '']);
        $this->assertSame('ČESKÁ REPUBLIKA', $v->getAttribute('stat'));
    }

    /** Zahraniční daňový subjekt → číselníkový název dané země. */
    public function testStatForForeignCountry(): void
    {
        $v = $this->fillFor(['country_iso2' => 'SK']);
        $this->assertSame('SLOVENSKO', $v->getAttribute('stat'));
    }

    /** Země mimo mapu → atribut `stat` se vynechá (je optional), ne neplatná hodnota. */
    public function testStatOmittedForUnknownCountry(): void
    {
        $v = $this->fillFor(['country_iso2' => 'XX']);
        $this->assertFalse($v->hasAttribute('stat'), 'neznámá země nesmí nastavit stat');
    }

    /**
     * @return iterable<string, array{0:string, 1:?string}>
     */
    public static function countries(): iterable
    {
        yield 'CZ' => ['CZ', 'ČESKÁ REPUBLIKA'];
        yield 'lowercase cz' => ['cz', 'ČESKÁ REPUBLIKA'];
        yield 'prázdné → CZ' => ['', 'ČESKÁ REPUBLIKA'];
        yield 'SK' => ['SK', 'SLOVENSKO'];
        yield 'DE' => ['DE', 'NĚMECKO'];
        yield 'GR (ISO, ne EL)' => ['GR', 'ŘECKO'];
        yield 'GB' => ['GB', 'VELKÁ BRITÁNIE'];
        yield 'neznámá' => ['XX', null];
    }

    #[DataProvider('countries')]
    public function testCountryName(string $iso2, ?string $expected): void
    {
        $this->assertSame($expected, EpoSupplierBlockBuilder::countryName($iso2));
    }

    /**
     * typ_ds musí vycházet z `taxpayer_type` (typ daňového subjektu). Dřív se
     * plnil z typu datové schránky, který nikdo nevyplňoval → u s.r.o. padalo
     * "F" a EPO odmítlo podání kvůli kmenové části DIČ.
     */
    public function testTypDsPodleTypuDanovehoSubjektu(): void
    {
        $po = $this->fillFor(['taxpayer_type' => 'po', 'company_name' => 'Firma s.r.o.']);
        $this->assertSame('P', $po->getAttribute('typ_ds'));
        $this->assertSame('Firma s.r.o.', $po->getAttribute('zkrobchjm'));

        $fo = $this->fillFor(['taxpayer_type' => 'fo', 'company_name' => 'Josef Novák']);
        $this->assertSame('F', $fo->getAttribute('typ_ds'));

        $neznamy = $this->fillFor(['taxpayer_type' => null, 'company_name' => 'Josef Novák']);
        $this->assertSame('F', $neznamy->getAttribute('typ_ds'));
    }

    /**
     * DPHDP3/DPHKH1 znají email/c_telef, DPHSHV NE — souhrnné hlášení volá
     * s `includeContact: false` a XSD by kontaktní atributy odmítlo (#200).
     */
    public function testContactAttributesEmittedByDefault(): void
    {
        $v = $this->fillFor(['email' => 'a@b.cz', 'phone' => '+420 722 944 990']);
        $this->assertSame('a@b.cz', $v->getAttribute('email'));
        $this->assertSame('722944990', $v->getAttribute('c_telef'));
    }

    public function testContactAttributesSuppressedForSouhrnneHlaseni(): void
    {
        $v = $this->fillFor(['email' => 'a@b.cz', 'phone' => '+420 722 944 990'], includeContact: false);
        $this->assertFalse($v->hasAttribute('email'), 'DPHSHV VetaP nesmí mít email');
        $this->assertFalse($v->hasAttribute('c_telef'), 'DPHSHV VetaP nesmí mít c_telef');
        // Ostatní atributy zůstávají vyplněné (kontrola, že jsme neshodili celý blok).
        $this->assertSame('ČESKÁ REPUBLIKA', $v->getAttribute('stat'));
    }

    /** Adresa se v DPHSHV rozdělí na ulice/c_pop (#200 — dřív „Nová 158" celé v ulice). */
    public function testAddressSplitAppliesForSouhrnneHlaseni(): void
    {
        $v = $this->fillFor(['street' => 'Nová 158'], includeContact: false);
        $this->assertSame('Nová', $v->getAttribute('ulice'));
        $this->assertSame('158', $v->getAttribute('c_pop'));
    }

    /** @param array<string,mixed> $overrides */
    private function fillFor(array $overrides, bool $includeContact = true): \DOMElement
    {
        $supplier = array_merge([
            'financial_office_code' => '451', 'dic' => 'CZ1234567890',
            'taxpayer_type' => 'fo', 'company_name' => '', 'street' => 'Hlavní 1', 'city' => 'Praha',
            'zip' => '11000', 'country_iso2' => 'CZ',
        ], $overrides);
        $dom = new \DOMDocument();
        $v = $dom->createElement('VetaP');
        EpoSupplierBlockBuilder::fillVetaP($v, $supplier, $includeContact);
        return $v;
    }
}
