# 9. Faktury — seznam a hromadné akce

Faktury jsou srdce systému. Tato kapitola popisuje **seznam faktur** a **hromadné
akce**. Editaci jednotlivé faktury popisuje [10. Editor faktury](10_Faktura_editor.md),
PDF a odeslání e-mailem [11. Faktura PDF](11_Faktura_PDF.md).

## 9.1 Seznam faktur

V hlavním menu **Faktury**.

![Seznam faktur](img/08_faktury_list.webp)

Seznam je seskupený **po měsících vystavení** (sticky header s názvem měsíce).
V každé skupině jsou faktury seřazené podle data vystavení (nejnovější nahoře).

| Sloupec | Význam |
|---|---|
| ☐ | Checkbox pro hromadnou akci |
| Číslo | Variabilní symbol — např. `2605001` (formát YYMMNNN) |
| Typ | 🟦 Faktura / 🟨 Zálohová / 🟥 Dobropis / ⚫ Storno / 🧾 Daňový doklad k platbě |
| Klient | Jméno klienta (klikatelné) |
| Vystaveno | Datum vystavení |
| Splatnost | Datum splatnosti — červeně pokud po dni a faktura není zaplacená |
| Částka | Celková částka v měně faktury |
| Stav | Barevný badge — viz § 9.2 |
| Akce | PDF, Detail, … |

### 9.1.1 Filtry (vlevo)

| Filtr | Hodnoty |
|---|---|
| Stav | Koncept / Vystaveno / Odesláno / Po splatnosti / Upomínka / Zaplaceno / Storno / Dobropis |
| Typ | Faktura / Zálohová / Dobropis / Storno |
| Klient | Dropdown se všemi klienty |
| Zakázka | Závisí na vybraném klientovi |
| Měna | CZK / EUR / … |
| Období | Tento měsíc / minulý měsíc / tento rok / minulý rok / vlastní rozsah |
| Hledat | Volný text — varsymbol, popis položky, jméno klienta |

## 9.2 Stavy faktur

| Stav | Význam | Co lze udělat |
|---|---|---|
| 📝 **Koncept** (`draft`) | Rozpracovaná, neviditelná pro klienta | Editovat, smazat, vystavit |
| ✅ **Vystaveno** (`issued`) | Číslo přiděleno, immutable PDF, ale klientovi nešla | Odeslat e-mailem, zaplatit, upomínka, dobropis, storno |
| 📧 **Odesláno** (`sent`) | E-mail s PDF odešel klientovi | Zaplatit, upomínka |
| ⏰ **Upomínka** (`reminded`) | Upomínkový e-mail odešel | Zaplatit, další upomínka (s cooldownem), dobropis |
| 💰 **Zaplaceno** (`paid`) | Platba přišla a byla spárována | (terminální) |
| 🟠 **Částečně uhrazeno** | Přišla jen část peněz (evidence plateb) — zbytek je dál pohledávka | Doplatit, částečná úhrada, upomínka |
| 🟣 **Přeplaceno** | Evidované platby převyšují částku k úhradě | (řeší se ručně — vratka / dobropis) |
| ⚫ **Storno** (`cancellation`) | Interní storno — faktura ztratila platnost | (terminální) |
| 🔄 **Dobropis** (`credit_note`) | Vytvořen opravný daňový doklad | (terminální) |

> 💡 **Edituj jen koncepty.** Vystavená faktura má immutable snapshot dodavatele,
> klienta a banky — pro změnu je třeba storno + nová faktura, nebo dobropis.
> Admin má v krajní nouzi možnost editace s `?force=1` (s audit logem).

## 9.3 Hromadné akce

Zaškrtni více faktur (checkbox). Checkbox v záhlaví prvního sloupce označí
nebo odznačí všechny načtené faktury v daném měsíci. Nahoře se objeví
lišta s akcemi:

| Akce | Funkce | Aplikuje se na |
|---|---|---|
| **Vystavit znovu (N)** | Vytvoří klony jako nové koncepty s auto-inkrementem měsíce v popiscích položek (`3/2026 → 4/2026`) | Faktury libovolného stavu |
| **Odeslat klientovi (N)** | Hromadně odešle e-mail s PDF přílohou | Vystavené, neodeslané (`issued`) |
| **Označit zaplacené (N)** | Manuálně označí jako zaplacené dnešním datem | Vystavené / odeslané / upomínkované |
| **Upomínka (N)** | Pošle upomínkový e-mail | Po splatnosti, ne zaplacené, cooldown 14 dní mezi upomínkami |
| **PDF export (N)** | Spojí označené doklady do jednoho PDF; volitelně podepíše výsledný soubor | Vystavené, nejvýše 100 |

ISDOC ZIP, Pohoda XML a PDF ZIP se nedělají výběrem v seznamu, ale za celé
období na stránce **Daně → Hromadný export** — viz [15. Exporty](15_Exporty.md).

> ⚠️ **Vystavit znovu** vždy vytvoří **nové koncepty** — nepřevede automaticky
> klony do `issued`. Tím tě chrání před omylem; po klonování si v každé nové
> projdi a klikni „Vystavit" ručně.

U akce **PDF export (N)** se otevře dialog s volbou elektronického podpisu.
Výsledný soubor obsahuje jen vlastní faktury v pořadí seznamu; e-mailové přílohy,
vložené ISDOC soubory a výkazy práce se nepřidávají. Podpis se případně vytvoří
až nad celým sloučeným PDF.

Exportovat lze pouze vystavené doklady. Pokud je ve výběru koncept nebo stornovaný
doklad, export se zastaví a vypíše, které doklady je potřeba z výběru vyřadit —
koncept ještě nemá přidělené číslo, takže by do exportu vstoupil s placeholderem.

### 9.3.1 Workflow měsíční retainer

Typický měsíc:

1. **1. den měsíce** — otevřu Faktury, filtr „Minulý měsíc", označím všechny
   retainerové faktury, klik **Vystavit znovu (N)**.
2. **Dostanu N konceptů** s popisy automaticky inkrementovanými (`Konzultace
   3/2026 → Konzultace 4/2026`).
3. **Projdu, případně upravím** položky (přidám hodiny navíc, slevu, …).
4. **Označím všechny → Vystavit** (hromadná akce — vznikne číselná řada,
   PDF se vygeneruje).
5. **Označím všechny → Odeslat klientovi**.
6. **Hotovo** za 5 minut.

## 9.4 Ikony stavu (legenda)

V horní liště nad seznamem jsou ikony — klik přepne filtr na daný stav:

- 🟢 počet zaplacených tento měsíc
- 🟣 počet odeslaných (čekajících na platbu)
- 🟡 počet vystavených (neodeslaných)
- 🔴 počet po splatnosti
- 🟠 počet upomínkovaných

## 9.5 Vyhledávání

Pole **Hledat** vlevo nahoře. Hledá v:

- Variabilním symbolu (přesná shoda i prefix)
- Popisu položek (LIKE)
- Jménu klienta
- Čísle projektu / smlouvy

Funguje fulltext česky i anglicky.

## 9.6 Tipy

- **Nepoužívej hromadné odesílání bez review** — pokud máš v koncepcích
  drobné chyby (špatná částka, chybějící popis), pošlou se klientovi všechny
  najednou.
- **„Označit zaplacené" je manuální fallback** — primárně se faktury označují
  zaplacenými automaticky při importu bankovního výpisu (viz [24. Banka](24_Banka.md)).
  Částečné platby a evidenci úhrad popisuje [§ 11.1.2](11_Faktura_PDF.md).
- **Filtr „Po splatnosti"** je nejrychlejší způsob, jak zjistit, kdo dluží —
  klik na řádek a hned máš tlačítko **Upomínka**.
- **Klik na číslo faktury** otevře [Detail faktury](11_Faktura_PDF.md).
- **Klik na ikonu PDF** stáhne přímo PDF (bez otvírání detailu).
