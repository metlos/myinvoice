<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use DOMElement;

/**
 * Sdílený helper pro sestavení `<VetaP>` (identifikace daňového subjektu)
 * a normalizaci CZ-NACE / OKEČ kódu napříč EPO výkazy (DPHDP3, DPHKH1, DPHSHV).
 *
 * VetaP struktura je v DPH/KH/SHV identická per EPO XSD — sdílíme jeden
 * generátor, aby všechny výkazy odpovídaly konzistentně tomu, co posílá
 * skutečné EPO podání: opr_*, sest_*, c_orient, c_pop, c_telef atd.
 */
final class EpoSupplierBlockBuilder
{
    /**
     * Vyplní VetaP atributy z `supplier` row.
     *
     * @param array<string,mixed> $supplier Načteno z `supplier` tabulky včetně
     *                                       cz_nace_code, opr_*, sest_*, street_number_*.
     * @param bool $includeContact Emitovat `email`/`c_telef`? DPHDP3 a DPHKH1 je znají,
     *                             DPHSHV (souhrnné hlášení) NE — tam by je EPO odmítlo
     *                             (VetaP XSD ty atributy nemá). SH volá s `false`.
     */
    public static function fillVetaP(DOMElement $vetaP, array $supplier, bool $includeContact = true): void
    {
        // c_ufo (kód FÚ) je required. Fallback "451" (Praha 1) pokud chybí.
        $vetaP->setAttribute('c_ufo', (string) ($supplier['financial_office_code'] ?: '451'));
        if (!empty($supplier['workplace_code'])) {
            $vetaP->setAttribute('c_pracufo', (string) $supplier['workplace_code']);
        }
        // DIČ — pattern [0-9]{1,10}, strip "CZ" prefix.
        $dic = (string) ($supplier['dic'] ?? '');
        $vetaP->setAttribute('dic', preg_replace('/^CZ/i', '', $dic) ?? $dic);
        // typ_ds = TYP DAŇOVÉHO SUBJEKTU (F = fyzická, P = právnická osoba), NIKOLI typ
        // datové schránky — ten se sem plnil dřív a shodil podání každé právnické
        // osobě („U fyzické osoby musí být kmenová část DIČ tvořena RČ nebo vlastním
        // číslem plátce"). Jediný autoritativní zdroj je `taxpayer_type` (fo/po).
        $isPravnickaOsoba = ($supplier['taxpayer_type'] ?? null) === 'po';
        $vetaP->setAttribute('typ_ds', $isPravnickaOsoba ? 'P' : 'F');

        if ($isPravnickaOsoba) {
            $vetaP->setAttribute('zkrobchjm', (string) $supplier['company_name']);
        } else {
            // Fyzická osoba (OSVČ) — jmeno/prijmeni = sám daňový subjekt.
            //   1) Preferuj strukturovaná pole jméno/příjmení, která už plníme jednateli
            //      u s.r.o. (opr_jmeno/opr_prijmeni) — u OSVČ = tatáž osoba, dá přesnou kontrolu.
            //   2) Fallback: rozdělení company_name s ODSTRANĚNÍM akademických titulů —
            //      jinak „MUDr. Josef Novák" → jmeno=„MUDr.", prijmeni=„Josef Novák" (#200).
            $jmeno = trim((string) ($supplier['opr_jmeno'] ?? ''));
            $prijmeni = trim((string) ($supplier['opr_prijmeni'] ?? ''));
            if ($jmeno === '' || $prijmeni === '') {
                [$jmeno, $prijmeni] = self::splitPersonName((string) ($supplier['company_name'] ?? ''));
            }
            $vetaP->setAttribute('jmeno', $jmeno);
            $vetaP->setAttribute('prijmeni', $prijmeni !== '' ? $prijmeni : $jmeno);
        }

        // Adresa: ulice samotná + čísla popisné/orientační zvlášť (per EPO konvence).
        //   1) Pokud má uživatel samostatné `street_number_pop` / `street_number_orient`,
        //      použijeme je a z `street` odstřihneme trailing čísla (aby se nezdvojovala).
        //   2) Jinak fallback parsing z `street` — typický český formát "Ulice 1104/36"
        //      rozdělí na ulice + č.p. + č.o.
        $rawStreet = (string) ($supplier['street'] ?? '');
        $cpop = trim((string) ($supplier['street_number_pop'] ?? ''));
        $corient = trim((string) ($supplier['street_number_orient'] ?? ''));
        $uliceText = $rawStreet;
        if ($cpop !== '' || $corient !== '') {
            // Manuálně vyplněná čísla → odřízni numerický suffix z ulice (i s "/")
            $uliceText = preg_replace('/\s+\d+[a-zA-Z]?(?:\s*\/\s*\d+[a-zA-Z]?)?\s*$/u', '', $rawStreet) ?? $rawStreet;
            $uliceText = trim($uliceText);
        } elseif ($rawStreet !== '') {
            // Fallback parsing:
            //   "Kardinála Berana 1104/36" → ulice="Kardinála Berana", pop=1104, orient=36
            //   "Hlavní 12"                → ulice="Hlavní", pop=12
            //   "Hlavní 12a"               → ulice="Hlavní", pop=12a (alfa suffix ok)
            if (preg_match('/^(.+?)\s+(\d+[a-zA-Z]?)(?:\s*\/\s*(\d+[a-zA-Z]?))?\s*$/u', $rawStreet, $m)) {
                $uliceText = trim($m[1]);
                $cpop = $m[2];
                if (!empty($m[3])) $corient = $m[3];
            }
        }
        $vetaP->setAttribute('ulice', $uliceText);
        if ($cpop !== '')    $vetaP->setAttribute('c_pop', $cpop);
        if ($corient !== '') $vetaP->setAttribute('c_orient', $corient);
        $vetaP->setAttribute('naz_obce', (string) ($supplier['city'] ?? ''));
        $vetaP->setAttribute('psc', preg_replace('/\s/', '', (string) ($supplier['zip'] ?? '')) ?? '');
        // `stat` = NÁZEV státu z číselníku Země (položka naz_zeme_c25), NE ISO2 kód (#201).
        // ISO2 kód patří do `k_stat` (u řádků protistran). Atribut je optional — pokud
        // zemi neumíme namapovat na číselníkový název, raději ho vynecháme než poslat
        // věcně neplatnou hodnotu.
        $statName = self::countryName((string) ($supplier['country_iso2'] ?? 'CZ'));
        if ($statName !== null) {
            $vetaP->setAttribute('stat', $statName);
        }

        if ($includeContact) {
            if (!empty($supplier['email'])) $vetaP->setAttribute('email', (string) $supplier['email']);
            if (!empty($supplier['phone'])) $vetaP->setAttribute('c_telef', self::normalizePhone((string) $supplier['phone']));
        }

        // Oprávněná osoba (POVINNÉ u PO — jednatel apod.)
        if (!empty($supplier['opr_jmeno']))     $vetaP->setAttribute('opr_jmeno', (string) $supplier['opr_jmeno']);
        if (!empty($supplier['opr_prijmeni']))  $vetaP->setAttribute('opr_prijmeni', (string) $supplier['opr_prijmeni']);
        if (!empty($supplier['opr_postaveni'])) $vetaP->setAttribute('opr_postaveni', (string) $supplier['opr_postaveni']);

        // Sestavitel přiznání (typicky účetní). Příjmení má vlastní sloupec
        // `sest_prijmeni` (sjednoceno s jednatelem opr_*). Když není vyplněno,
        // fallback: split `sest_jmeno` podle první mezery (BC pro stará data).
        if (!empty($supplier['sest_jmeno'])) {
            if (!empty($supplier['sest_prijmeni'])) {
                $vetaP->setAttribute('sest_jmeno', (string) $supplier['sest_jmeno']);
                $vetaP->setAttribute('sest_prijmeni', (string) $supplier['sest_prijmeni']);
            } else {
                $sestParts = explode(' ', trim((string) $supplier['sest_jmeno']), 2);
                $vetaP->setAttribute('sest_jmeno', $sestParts[0] ?? '');
                if (!empty($sestParts[1])) {
                    $vetaP->setAttribute('sest_prijmeni', $sestParts[1]);
                }
            }
        }
        if (!empty($supplier['sest_telefon'])) $vetaP->setAttribute('sest_telef', self::normalizePhone((string) $supplier['sest_telefon']));
        // Pozn.: sest_email a sest_funkce NEJSOU v EPO XSD (DPH/KH/SHV) — držíme je
        // jen v DB pro vnitřní použití (kontakt na účetní v UI).
    }

    /**
     * Rozdělí celé jméno fyzické osoby na [jmeno, prijmeni] pro EPO VetaP a odstraní
     * akademické tituly (vedoucí i koncové), aby nespadly do `jmeno`/`prijmeni` (#200).
     *
     *   „MUDr. Josef Novák"            → ['Josef', 'Novák']
     *   „prof. Ing. Jan Svoboda, CSc." → ['Jan', 'Svoboda']
     *   „Josef Novák"                  → ['Josef', 'Novák']
     *   „Josef Karel Novák"            → ['Josef', 'Karel Novák']  (víceslovné příjmení)
     *   „Novák"                        → ['Novák', 'Novák']        (BC — prijmeni je required)
     *
     * Titul = token s tečkou (MUDr., Ing., prof., Ph.D. …) nebo ze seznamu bez tečky
     * (CSc., DrSc., MBA, DiS, …). Koncové tituly bývají za čárkou — tu urveme celou.
     *
     * @return array{0:string, 1:string} [jmeno, prijmeni]
     */
    public static function splitPersonName(string $full): array
    {
        // Vše za první čárkou (typicky koncové tituly „, Ph.D.", „, CSc.", „, MBA") pryč.
        $full = preg_replace('/,.*$/us', '', trim($full)) ?? $full;
        $tokens = array_values(array_filter(preg_split('/\s+/u', trim($full)) ?: [], static fn ($t) => $t !== ''));

        $suffixTitles = ['csc', 'drsc', 'mba', 'dis', 'bsc', 'msc', 'ma', 'ba', 'llm', 'phd', 'dr'];
        $isTitle = static function (string $t) use ($suffixTitles): bool {
            if (str_contains($t, '.')) return true;                    // MUDr., Ing., prof., Ph.D.
            return in_array(mb_strtolower(rtrim($t, '.')), $suffixTitles, true);
        };
        // Urvi vedoucí i koncové tituly (nech aspoň 1 token = vlastní jméno).
        while (count($tokens) > 1 && $isTitle($tokens[0])) array_shift($tokens);
        while (count($tokens) > 1 && $isTitle($tokens[count($tokens) - 1])) array_pop($tokens);

        if ($tokens === []) return ['', ''];
        if (count($tokens) === 1) return [$tokens[0], $tokens[0]]; // jen jedno slovo → prijmeni=jmeno

        $jmeno = array_shift($tokens);
        return [$jmeno, implode(' ', $tokens)];
    }

    /**
     * Mapuje ISO2 kód země na NÁZEV státu podle číselníku EPO „Země" (položka
     * `naz_zeme_c25`, max. 25 znaků) pro atribut `VetaP/stat` (#201).
     *
     * Názvy jsou převzaty verbatim z autoritativní tabulky v EPO XSD (dokumentace
     * atributu `k_stat` — „Daňová identifikační čísla členských států EU"). Klíčem
     * je ISO2 (v DB `country_iso2`), takže Řecko je pod „GR" (číselník DPH kódů
     * používá „EL", ale to je jen `k_stat`, ne ISO).
     *
     * Vrací `null` pro zemi mimo mapu — atribut `stat` je v EPO optional, takže je
     * lepší ho vynechat než poslat neplatnou (číselníkově neexistující) hodnotu.
     */
    public static function countryName(string $iso2): ?string
    {
        static $map = [
            'AT' => 'RAKOUSKO',
            'BE' => 'BELGIE',
            'BG' => 'BULHARSKO',
            'CY' => 'KYPR',
            'CZ' => 'ČESKÁ REPUBLIKA',
            'DE' => 'NĚMECKO',
            'DK' => 'DÁNSKO',
            'EE' => 'ESTONSKO',
            'ES' => 'ŠPANĚLSKO',
            'FI' => 'FINSKO',
            'FR' => 'FRANCIE',
            'GB' => 'VELKÁ BRITÁNIE',
            'GR' => 'ŘECKO',
            'HR' => 'CHORVATSKO',
            'HU' => 'MAĎARSKO',
            'IE' => 'IRSKO',
            'IT' => 'ITÁLIE',
            'LT' => 'LITVA',
            'LU' => 'LUCEMBURSKO',
            'LV' => 'LOTYŠSKO',
            'MT' => 'MALTA',
            'NL' => 'NIZOZEMSKO',
            'PL' => 'POLSKO',
            'PT' => 'PORTUGALSKO',
            'RO' => 'RUMUNSKO',
            'SE' => 'ŠVÉDSKO',
            'SI' => 'SLOVINSKO',
            'SK' => 'SLOVENSKO',
        ];

        $key = strtoupper(trim($iso2));
        if ($key === '') {
            $key = 'CZ';
        }

        return $map[$key] ?? null;
    }

    /**
     * Normalizace telefonu pro EPO `c_telef` / `sest_telef`:
     *   - odstraní `+420` / `00420` prefix
     *   - odstraní mezery, pomlčky, závorky
     * Reálné EPO podání uvádí jen 9-místné číslo (např. "722944990"); naše DB
     * může mít formát "+420 722 944 990".
     */
    public static function normalizePhone(string $raw): string
    {
        $s = trim($raw);
        $s = preg_replace('/^(\+|00)420\s*/', '', $s) ?? $s;
        $s = preg_replace('/[\s\-()]+/', '', $s) ?? $s;
        return $s;
    }

    /**
     * Normalizace CZ-NACE / OKEČ hodnoty pro `c_okec`. Hodnoty z UI mohou být
     * "62.02", "62020", "620200" apod. → strip non-digit znaků, ořež na max 6.
     *
     * NEPADUJEME zprava nulami: CZ-NACE číselník EPO je proměnné šířky — většina
     * položek je 5-místná (`62020`), jen pár odvětví má 6-místné podtřídy
     * (`010111`). XSD `totalDigits=6` je MAXIMUM, ne fixní délka; skutečný výčet
     * hodnot žije v externím číselníku MFČR
     * (https://adisspr.mfcr.cz/pmd/dokumentace/ciselniky/). Pravostranné doplnění
     * 5-místného kódu nulou ("62020" → "620200") vyrobí hodnotu mimo číselník
     * a EPO podání odmítne ("hodnota musí být z číselníku"). Validitu proti
     * číselníku zde NEKONTROLUJEME — uživatel zná svou klasifikaci.
     */
    public static function normalizeOkec(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if ($digits === '') return null;
        if (strlen($digits) > 6) $digits = substr($digits, 0, 6);
        return $digits;
    }
}
