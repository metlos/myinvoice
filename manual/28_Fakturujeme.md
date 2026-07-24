# 28. Fakturujeme — daňový průvodce

> ⚠️ **Správnost faktury je vždy na uživateli.** MyInvoice.cz je fakturační
> nástroj — generuje doklady, eviduje je a umí je exportovat účetní. Není to
> daňový poradce. Sazba DPH, místo plnění, OSS / IOSS, přenesená daňová
> povinnost, registrace k DPH v cizí zemi — to vše je odpovědnost vystavitele
> faktury, nikoli aplikace. **Vždy konzultuj nestandardní situace s účetní
> nebo daňovým poradcem.** Cena za 30 minut konzultace je řádově nižší než
> sankce za špatně vystavenou fakturu.

Tato kapitola popisuje, jak MyInvoice vystavuje doklady — co dělá automaticky,
kde tě nechá rozhodnout sebe a kde aplikace končí (a tvůj účetní začíná).

## 28.1 Plátce vs. neplátce DPH

Příznak **plátce DPH** je u dodavatele (`Nastavení → Dodavatel`) — určuje
chování celé aplikace. Změna se projeví okamžitě u nově vystavovaných faktur;
už vystavené doklady se nemění.

| Co se mění | Plátce DPH | Neplátce DPH |
|---|---|---|
| Záhlaví dokladu | „Faktura — daňový doklad" | „Faktura" |
| Sloupec „DPH %" v tabulce položek | ano | **skrytý** |
| Sloupec „S DPH" | ano | **skrytý** (jen „Celkem") |
| Volba sazby DPH u položky | ano | **skrytá**, interně se ukládá 0 % |
| Reverse charge checkbox | ano (pro EU klienty s VAT ID) | **skrytý** |
| Sumace DPH (rozpis sazeb, „DPH celkem") | ano | **skrytá** |
| Banner „Není plátce DPH" | — | ano |

Neplátce dle ZDPH nemá nárok DPH účtovat ani vykazovat. Aplikace tomu odpovídá:
faktura je čistě jednosloupcová (Cena/j → Celkem) a obsahuje povinnou poznámku.
Pokud se z neplátce staneš plátcem (překročíš obrat 2 mil. Kč za 12 měsíců,
nebo se zaregistruješ dobrovolně), v dodavateli příznak přepneš a další faktury
už budou s DPH.

> 💡 Mezní situace: faktury vystavené jako neplátce v období, kdy už jsi měl
> být plátcem (zpětná registrace), je třeba opravit dodatečným daňovým
> dokladem. To MyInvoice neumí automaticky — řeš s účetní.

### 28.1.1 Identifikovaná osoba (§ 6g–6l ZDPH)

Třetí daňový status mezi plátcem a neplátcem — typicky freelancer, který
fakturuje služby do EU (a/nebo nakupuje zahraniční služby typu reklamy či
SaaS), ale v tuzemsku plátcem není. V `Nastavení → Dodavatel` nech **Plátce
DPH vypnutý** a zaškrtni **Identifikovaná osoba**.

Co se tím změní (vše ostatní zůstává jako u neplátce):

| Oblast | Chování IO |
|---|---|
| Tuzemské faktury | beze změny — bez DPH, banner „Není plátce DPH" |
| Faktura **EU** klientovi s DIČ | po výběru klienta se automaticky zapne **reverse charge** a předvyplní klasifikace **22** (EU služby → souhrnné hlášení); PDF je daňový doklad s DIČ a klauzulí „daň odvede zákazník (čl. 196 směrnice 2006/112/ES)". Sazba DPH se na dokladu **neuvádí** (samovyměří odběratel sazbou své země) — částky jsou základ daně, sloupec se proto jmenuje „Celkem bez DPH". Totéž platí v šabloně pravidelné fakturace. |
| Faktura klientovi mimo EU | bez RC — plnění je mimo předmět české DPH, žádná klauzule, žádné SHV |
| Souhrnné hlášení (SHV) | podává se za měsíce s EU službami (kód 3) — `Daně → Souhrnné hlášení` |
| Přijaté zahraniční doklady (klasifikace 23/24/25) | samovyměření DPH **bez nároku na odpočet** — daň se reálně platí |
| DPH přiznání | typ **identifikovaná osoba** (`typ_platce='I'`), jen řádky samovyměření (ř. 3–6, 12–13), **vždy měsíčně** a jen za měsíce, kdy povinnost vznikla |
| Kontrolní hlášení | **nepodává se nikdy** — stránka KH zobrazí upozornění |

> ⚠️ Samovyměřená daň bez nároku na odpočet je u IO skutečný výdaj. Lhůta
> podání přiznání i SHV je do 25. dne následujícího měsíce; faktura za EU
> služby se vystavuje nejpozději do 15 dnů od konce měsíce plnění (§ 28).

## 28.2 Sazby DPH (číselník `CZ`)

Standardní seed obsahuje čtyři sazby pro Česko:

| Kód | Sazba | Popis | Kdy použít |
|---|---|---|---|
| `CZ-21` | 21 % | Základní | Default — většina zboží i služeb |
| `CZ-12` | 12 % | Snížená | Potraviny, knihy, ubytování, vodné/stočné, léčivé přípravky… (úplný seznam je v příloze ZDPH) |
| `CZ-0` | 0 % | Osvobozeno | Plnění osvobozená dle §51 ZDPH (např. finanční služby, vzdělávání). Také fallback pro neplátce. |
| `CZ-RC` | 0 % | Reverse charge | Přenesená daňová povinnost — sazba 0 %, daň odvádí příjemce |

Sazby spravuješ v `Nastavení → Číselníky → DPH sazby`. Můžeš přidávat další
(např. `SK-23` pro slovenský OSS — viz [§ 28.4](#284-zahranicni-fakturace-limitace-a-oss)), upravovat label nebo
zneplatnit zastaralé pomocí `valid_to`. Default sazba (`is_default`) se
předvyplňuje u nově přidané položky faktury.

> ⚠️ Sazby se přiřazují **per položku**, ne per celá faktura. Smíšené sazby
> v jedné faktuře aplikace zvládá — sumace je rozepsaná po sazbách.

## 28.3 Reverse charge (přenesená daňová povinnost)

Reverse charge (RC) přesouvá povinnost odvést DPH na **příjemce** faktury.
Vystavitel účtuje 0 % a doplní zákonnou poznámku. V MyInvoice se RC řeší
checkboxem **Reverse charge** v editoru faktury.

### Kdy RC vystavit

- **Tuzemský RC (§ 92a–§ 92g ZDPH):** stavební a montážní práce mezi plátci v ČR,
  zlato, šrot, mobilní telefony, integrované obvody, plyn/elektřina pro
  obchodníka… (přesný výčet § 92a-g). Oba subjekty musí být plátci DPH v ČR.
- **EU B2B s reverse charge:** dodavatel je plátce DPH v ČR, klient je plátce
  DPH v jiném členském státě (má **platné VAT ID** ověřitelné přes VIES) a
  jde o B2B plnění s místem plnění v zemi příjemce dle § 9 odst. 1 ZDPH.

V obou případech aplikace nastaví všechny položky na sazbu `CZ-RC` (0 %),
sumace neukáže DPH řádky a do PDF přidá zákonnou poznámku — pro tuzemského
klienta „Daň odvede zákazník (přenesená daňová povinnost dle § 92a zákona
o DPH)", pro zahraničního „…dle čl. 196 směrnice 2006/112/ES".

### Jak RC zapnout

1. **Profil klienta:** v `Klienti → Editace` zaškrtni `Reverse charge`. Tím
   povolíš RC checkbox v editoru faktur pro tohoto klienta.
2. **VIES ověření DIČ:** v editoru faktury po výběru klienta se DIČ ověří
   přes VIES (cache 24 h). Bez platného DIČ partner nemá nárok na RC.
3. **Editor faktury:** RC checkbox je viditelný jen pokud má klient RC
   povolenou. Po zaškrtnutí se všechny položky přepnou na 0 % RC sazbu.

> 💡 RC checkbox je v editoru schovaný i tehdy, když je dodavatel **neplátce
> DPH** — neplátce RC vystavit nemůže (nemá DPH co přenášet). Výjimkou je
> **identifikovaná osoba** (§ 28.1.1) — té se RC u zahraničního klienta s DIČ
> zapne automaticky.

## 28.4 Zahraniční fakturace — limitace a OSS

Tady aplikace končí svou plnou automatiku. MyInvoice je primárně pro **B2B
fakturaci českým plátcem DPH**. Ostatní scénáře dokáže vystavit, ale daňový
režim si určí uživatel sám (volbou sazby).

### Co aplikace umí

- **CZ B2B:** plně podporováno (21 % / 12 % / RC dle situace).
- **EU B2B s platným VAT ID:** RC checkbox + VIES ověření.
- **Mimo EU (Švýcarsko, USA, UK, …):** typicky bez DPH (export služeb / zboží);
  v editoru zvol sazbu `CZ-0` (Osvobozeno). Detaily a režim daně podle země
  příjemce konzultuj s účetní.

### Co aplikace **neumí** automaticky

#### B2C v EU — OSS (One Stop Shop)

Pokud fakturuješ **nepodnikajícímu zákazníkovi v jiném členském státě EU**
(B2C), uplatňují se zvláštní pravidla:

- **Standardní služby B2C** (např. konzultace, IT práce hodinovým paušálem):
  místo plnění je v ČR dle § 9 odst. 2 ZDPH → **21 % CZ DPH** je správně.
  Žádný OSS netřeba.
- **TBE služby** (telekomunikační, broadcast, elektronicky poskytované) +
  **distance sale of goods**: místo plnění je v zemi zákazníka, jakmile
  překročíš celounijní práh **10 000 €/rok** přes všechny B2C transakce do EU.
  Pak musíš použít **DPH sazbu země zákazníka** a hlásit ji přes systém OSS
  (One Stop Shop) na finančním úřadě.

#### Příklad: SK neplátce DPH, distance sale zboží nad 10 000 € prahem

Slovensko má od 2026 sazbu **23 %** (základní). Pokud fakturuješ slovenskému
neplátci nad OSS prahem, faktura má mít:

- DIČ vystavitele s prefixem `CZ`
- DIČ příjemce **prázdné** (B2C) nebo IČO bez VAT ID
- Sazba DPH: **23 %** (sazba SK)
- Měna: typicky EUR
- DPH se odvádí přes OSS, ne klasické přiznání

**Postup v MyInvoice:**

1. V `Nastavení → Číselníky → DPH sazby` přidej novou sazbu:
   - Kód: `SK-23`, Sazba: `23.00`, Země: `SK`, Label CS: „Standardní 23 % (SK)"
2. V editoru faktury vyber tuto sazbu na položkách ručně a u daného řádku
   zaškrtni **OSS**. Vyplň zemi spotřeby (např. `SK`), typ sazby a typ plnění.
   Volbu **Oprava období** ponech jako **Běžné plnění**.
3. PDF doklad bude číselně správný (23 % SK DPH), klient ho dostane.
4. V **Nastavení → Daňové nastavení** zapni **OSS režim** a nastav zemi
   identifikace (typicky `CZ`) a měnu podání (`EUR`).
5. OSS přehled najdeš v **Daně → OSS přiznání**. Zobrazuje kvartální souhrn
   ručně označených OSS řádků podle země spotřeby, sazby a měny podání.
   Řádky označené jako OSS se nezahrnují do českého přiznání k DPH,
   kontrolního hlášení ani Knihy DPH.
6. Kliknutím na **Stáhnout XML** vygeneruješ EPO XML **OSSEI1** pro OSS EU
   režim a záznam se uloží do **Archivu podání**.

Částky v jiné měně než je měna OSS podání se automaticky přepočítají k DUZP
pomocí kurzovního lístku ČNB. Pokud kurz nelze získat, XML se nevytvoří a přehled
upozorní, který doklad je potřeba opravit.

Opravu přiznání po uplynutí termínu vykážeš v aktuálním čtvrtletí rozdílem:

1. Na opravném řádku zapni **OSS** a nastav zemi spotřeby.
2. V poli **Oprava období** vyber původní čtvrtletí. Řádek se nezahrne mezi
   běžná plnění, ale do XML věty `VetaO`.
3. Záporný dobropis sníží původní DPH, kladný opravný řádek ji zvýší.

Dobropis vytvořený z OSS faktury z předchozího čtvrtletí převezme OSS údaje,
obrátí částky a původní období nastaví automaticky. Dobropis ve stejném
čtvrtletí zůstává běžným záporným plněním, protože přiznání lze před termínem
podat znovu celé.

> ⚠️ OSS XML je pomůcka pro podání v EPO. Uzávěrka období a automatické
> hlídání prahu 10 000 EUR nejsou součástí exportu.
> Před podáním vždy ověř sazby, režim B2C a přepočet s účetní.

#### Reverse charge mimo EU

Pro export služeb mimo EU (např. faktura americkému klientovi) se v ČR často
uplatňuje 0 % (mimo předmět DPH dle § 9 odst. 1 ZDPH). To **není reverse charge**
v právním slova smyslu — checkbox „Reverse charge" v editoru je určený pro
EU režim § 92a a generuje českou zákonnou poznámku, která pro export mimo EU
není přesná. **Pro export mimo EU použij sazbu `CZ-0` (Osvobozeno)** a do
poznámky pod položkami doplň anglický text typu „Outside the scope of
EU VAT — § 9(1) of Czech VAT Act".

#### Vícero registrací k DPH

Pokud jsi registrován k DPH ve více zemích (typicky e-shop s lokálními sklady),
potřebuješ **více sazeb a více DIČ**. MyInvoice má jeden dodavatelský profil
s jedním DIČ. Workaround: založ druhého dodavatele (`Nastavení → Dodavatelé →
Přidat`) a přepínej mezi nimi pomocí přepínače v hlavičce. Není to plnohodnotná
multi-jurisdikční podpora — DPH přiznání pro každou zemi řeš s místní účetní.

## 28.5 Co MyInvoice (ne)dělá

Aby bylo úplně jasno, kde je hranice:

### MyInvoice **dělá**

- Vystavení dokladu (faktura, zálohová, dobropis, storno)
- Evidence faktur, klientů, zakázek, plateb
- Generování PDF s QR platbou
- Odesílání faktur e-mailem
- Upomínání po splatnosti
- Bankovní importy (FIO, KB, ČSOB) a párování plateb podle VS
- ARES + VIES lookup (autocomplete IČO/DIČ)
- Export pro účetní: **Pohoda XML, Stereo XML, ISDOC, PDF ZIP**
- XML pro EPO portál MFČR: **DPH přiznání (DPHDP3), kontrolní hlášení,
  souhrnné hlášení a OSS přiznání (OSSEI1)** — jako pomůcka k ověření s účetní

### MyInvoice **nedělá**

- IOSS přiznání
- Kompletní daň z příjmů (umí jen orientační kostru — viz
  [32. Daň z příjmů](32_Dan_z_prijmu.md)), sociální/zdravotní pojištění
- Účetní deník nebo hlavní knihu
- Kalkulaci marží, skladové hospodářství
- Mzdy, fakturace s návazností na pracovní smlouvy
- Insolvenční registr, registr ekonomických subjektů

Standardní tok je: **MyInvoice vystaví doklady → vygeneruje výkazy DPH →
uživatel/účetní jednou měsíčně exportuje (Pohoda XML / Stereo XML / ISDOC) → účetní
doklady zaúčtuje a ověřené výkazy podá**. Aplikace primárně **eviduje,
podává a generuje výkazy** z dokladů, neúčtuje je.

## 28.6 Když si nejsi jistý

V pochybnostech platí jednoduchá poučka: **vyber konzervativnější variantu
a zeptej se účetní**.

- Nejsi si jistý, jestli klient má nárok na RC? → **Nepoužij RC**, dej 21 %.
  Klient si DPH odpočte, ty odvedeš. V nejhorším řešíš opravným dokladem.
- Nevíš, jestli máš účtovat 21 % CZ nebo 23 % SK? → **Použij 21 % CZ**, není
  to z hlavy chyba; pokud jsi měl být v OSS, doplníš to v daňovém přiznání.
- Klient se ozve, že DPH je špatná? → V editoru opravíš a vystavíš
  **opravný daňový doklad** (dobropis k původní + nová faktura). Aplikace
  to umí v 2 klikách.

> 💡 **Doporučení**: jednou ročně (typicky leden) projdi s účetní seznam
> svých klientů, sazeb a typů plnění. Pravidla DPH se mění (sazby, OSS prahy,
> elektronické fakturace). Hodinová konzultace ti zachrání spoustu
> opravných dokladů.

---

→ Pokračuj na [13. Klienti](13_Klienti.md), nebo se vrať na [INDEX](INDEX.md).
