# 1. Úvod — co MyInvoice.cz umí

MyInvoice.cz je **český fakturační systém pro freelancery, OSVČ a malé firmy**.
Běží na vlastním serveru (nebo v Dockeru) — žádné měsíční poplatky, žádný
externí cloud, tvoje data jsou jen u tebe. Aplikace je open-source (MIT
licence), publikovaná na [GitHubu](https://github.com/radekhulan/myinvoice)
a distribuovaná také jako multi-arch Docker image na
[GHCR](https://github.com/radekhulan/myinvoice/pkgs/container/myinvoice).

Stack je záměrně **konzervativní**: PHP 8.5 + Vue 3 + MariaDB. Nasazení zvládneš
na sdíleném hostingu, na vlastním VPS i v kontejneru. Veškerá konfigurace je
v jednom `cfg.php` souboru a databázové schéma se aktualizuje skriptem
`migrate.php` — žádné další služby, žádný cizí backend, žádná telemetrie.

## 1.1 Vystavování dokladů

Aplikace pokrývá celý český cyklus daňových dokladů — od proforma faktury, přes
ostrou fakturu, po dobropis. Každý doklad má **immutable PDF**: jakmile fakturu
vystavíš, PDF se vygeneruje a od té chvíle se nemění, i kdybys později měnil
adresu, banku nebo logo v Nastavení.

- **Faktura — daňový doklad** (pro plátce DPH) i **faktura** (pro neplátce)
- **Zálohová faktura (proforma)** s možností konverze na ostrou
- **Opravný daňový doklad (dobropis)** s vazbou na původní fakturu
- **Interní storno** (úplné zrušení vystavené faktury)
- **Klonování faktury** s automatickým inkrementem měsíce v popisech
  (`3/2026 → 4/2026`) — typický workflow pro pravidelnou měsíční fakturaci
- **Hromadné akce** nad vybranými fakturami — vystavit znovu (N), odeslat
  klientovi (N), upomínka (N), označit jako zaplacené (N)
- **Číselné řady** s nastavitelným formátem variabilního symbolu (`YYMM###`,
  `YY####`, vlastní šablony)
- **Multi-currency** — CZK, EUR, USD a další; per dodavatel může být
  více bankovních účtů v různých měnách
- **Activity log** u každé faktury — kdo a kdy ji vytvořil, vystavil, odeslal
  klientovi, dostal zaplacenou

## 1.2 Daňový průvodce — plátce, neplátce, RC, OSS

MyInvoice umí **fakturaci podle českého ZDPH** — přepíná chování formuláře
podle toho, jestli jsi plátce nebo neplátce, a podporuje speciální režimy:

- **Plátce / neplátce DPH** — globální přepínač u dodavatele; ovlivňuje
  záhlaví dokladu, sloupce v tabulce, sumace i povinné poznámky
- **Sazby DPH** v číselníku (`CZ-21`, `CZ-12`, `CZ-0`, `CZ-RC`) —
  přiřazují se per položku, smíšené sazby v jedné faktuře
- **Reverse charge (přenesená daňová povinnost)** — tuzemský RC dle § 92a–g
  i EU B2B s VAT ID; aplikace automaticky doplní zákonnou poznámku
- **OSS (One-Stop-Shop)** pro prodej do jiných členských států EU
  s lokálními sazbami (např. `SK-23`)
- **VIES ověření** EU VAT ID — kontrola platnosti DIČ klienta v reálném čase
- **Auto-výpočet DPH** s rozpadem po sazbách v sumační tabulce

Detaily jsou v kapitole [28. Fakturujeme](28_Fakturujeme.md). Pozor:
**správnost faktury je vždy na uživateli** — aplikace generuje doklady,
ale není daňový poradce.

## 1.3 Klienti a zakázky

- **Klienti** s lookupem v **ARES** (zadáš IČO, doplní se název, adresa, DIČ,
  právní forma) a **VIES** (ověření EU VAT ID)
- **Zakázky** 1:N pod klientem — typicky jeden zákazník má víc projektů
  fakturovaných nezávisle
- **Fakturační e-maily** na úrovni zakázky (jiný kontakt na účetní oddělení
  než na project manažera)
- **Kontaktní šablony** — předvyplněné dodací podmínky, splatnost, sazba,
  popisky položek per zakázka
- **Schvalování výkazu zákazníkem** — volitelné per zakázka. Před vystavením
  faktury pošleš zákazníkovi e-mail s odkazem na veřejnou stránku (chráněno
  jednorázovým tokenem + CAPTCHA). Po schválení se faktura **automaticky
  vystaví a odešle**.

## 1.4 Výkaz víceprací (timesheet)

U faktur za hodinovou práci (konzultace, vývoj, design) je často potřeba
přílohu s rozpisem hodin:

- **Druhá strana PDF** s tabulkou (datum, popis, hodiny, sazba, suma)
- **Suma se přenese** do položky faktury — neevidovat dvakrát
- **Schvalování zákazníkem** před vystavením (viz výše)
- **Archivace odeslaného výkazu** — snapshot v okamžiku odeslání

## 1.5 PDF, QR platba, e-mail

- **PDF s QR platbou** — **SPAYD** pro CZK (nascannuje libovolná česká
  bankovní aplikace), **SEPA EPC** pro EUR (evropský standard)
- **Vzhled PDF** — logo dodavatele, hlavička, footer, barevné schéma; CSS
  šablona v `mPDF` (lze upravit)
- **E-mail s PDF přílohou** přes vlastní **SMTP** (Postfix, SendGrid,
  Mailgun, Amazon SES, Gmail SMTP — cokoli s autentizací)
- **DKIM podpis** odchozích e-mailů — vyšší doručitelnost, méně spam-složek
- **Šablony e-mailů** v Nastavení — předmět + tělo s placeholdery
  (`{varsymbol}`, `{amount}`, `{due_date}`); vícejazyčné
- **Test odeslání** — pošle vzorový e-mail jen na tvůj e-mail (ne klientovi),
  pro vyzkoušení šablony i SMTP konfigurace

## 1.6 Banka — import výpisů a párování plateb

Místo ručního označování faktur jako zaplacených naimportuj GPC výpis
a aplikace platby spáruje sama:

- **Import GPC výpisů** (ABO formát) — KB, FIO, ČSOB, Raiffeisen, ČS,
  mBank a další
- **Hash kontrola** (SHA-256) — duplicitní upload výpisu se odmítne
- **Validace bankovního účtu** v hlavičce výpisu proti účtům dodavatele
- **Auto-matching** — kreditní transakce se VS se spárují s fakturou
  podle variabilního symbolu **a** sumy (tolerance ± 0,01 Kč)
- **Manuální párování** nedotažených transakcí (chybný VS, částečná platba)
- **Multi-currency banky** — víc účtů per dodavatel (CZK + EUR + USD)

## 1.7 Upomínky po splatnosti

- **Manuální tlačítko** „Poslat upomínku" v detailu faktury
- **Hromadná akce** „Upomenout vybrané" v seznamu
- **Cron** — denní automatické upomínky podle pravidel (X dní po splatnosti)
- **Cooldown** — žádná druhá upomínka dřív než za 14 dní (anti-spam)
- **Šablony** — jiné znění pro 1., 2., 3. upomínku

## 1.8 Exporty pro účetní

Čtyři standardní formáty pro předání dokladů externí kanceláři nebo internímu
účetnímu oddělení:

- **PDF ZIP po měsících** — klasická archivace, název souboru
  `<varsymbol>-<typ>.pdf`
- **ISDOC 6.0.2** — český národní standard pro elektronickou výměnu faktur,
  podporují ho všechny větší české účetní programy
- **Pohoda XML** (Stormware data package) — přímý import do Pohody bez
  ručního opisu
- **Stereo XML** — DocumentPack XML pro import vydaných faktur do Stereo
- Filtrování exportu podle období, typu dokladu (faktury / zálohové /
  dobropisy) a stavu (vystavené / zaplacené / vše)

Aplikace umí i **import** — Pohoda XML (zpětně nahrát doklady vystavené
v Pohodě) a ISDOC.

## 1.9 Multi-supplier — víc firem z jedné instalace

Z jedné instalace MyInvoice můžeš fakturovat za **libovolný počet
dodavatelů** (firem / IČO) s plně izolovanými daty:

- Vlastní číselné řady, klienty, zakázky a faktury per dodavatel
- Vlastní logo, bankovní účty, SMTP, DKIM klíče
- Přepínač dodavatele v UI — uživatel vidí jen ty, ke kterým má přístup
- Typické nasazení: účetní kancelář (~50 firem), holding, freelancer
  s víc trading subjects

## 1.10 Bezpečnost

Bezpečnost stojí na pěti vrstvách (detail v [39. Bezpečnost](39_Bezpecnost.md)):

- **Hesla** — bcrypt cost 12 + pepper, min. 12 znaků, indikátor síly
- **2FA (TOTP)** — Google Authenticator, Authy, 1Password, Bitwarden…
- **IP allowlist** (IPv4 + IPv6 + CIDR) — volitelné, doporučené v produkci
- **Brute-force ochrana** + **CAPTCHA** na login a veřejné stránky
- **Role-based access** — admin / accountant / readonly
- **Activity log** všech mutací (kdo, kdy, co změnil)

## 1.11 Vlastní hosting, vlastní data

- **Žádné měsíční poplatky** — open-source, MIT licence
- **Žádný externí cloud** — data v tvojí MariaDB, PDF na tvém disku
- **Žádná telemetrie** — aplikace nikam neposílá data o tvém používání
- **Docker image** na GHCR (`ghcr.io/radekhulan/myinvoice`) — multi-arch
  (amd64 + arm64), publikovaný automaticky při tagování verze
- **Migrace** přes `php api/bin/migrate.php` — verzované, idempotentní
- **Backup** = `mysqldump` + `tar` adresáře s PDF; obnovení obrácený postup

## 1.12 Pro koho

- **Freelancer** — pár faktur měsíčně, jednoduchá tvorba podle šablony,
  klonování s inkrementem měsíce, schvalování výkazu zákazníkem.
- **OSVČ / malá firma** — desítky faktur měsíčně, hromadné akce, automatické
  párování plateb z banky, upomínky cronem, exporty pro externí účetní.
- **Účetní kancelář** — multi-supplier (až ~50 firem z jedné instalace),
  exporty do Pohody a ISDOC, role-based access pro klienty.

## 1.13 Co MyInvoice **nedělá**

MyInvoice je primárně **fakturační**, ne plnohodnotný účetní systém. Nad
rámec fakturace umí z evidovaných dokladů vygenerovat XML pro EPO portál
MFČR — viz [29. Výkazy DPH](29_Vykazy_DPH.md) (DPHDP3 + kontrolní hlášení +
[souhrnné hlášení](31_Souhrnne_hlaseni.md)) a [32. Daň z příjmů](32_Dan_z_prijmu.md) (DPFO/DPPO,
zatím jen orientační kostra). Tyto výkazy jsou **pomůcka** — před podáním
je vždy ověř s účetní nebo daňovým poradcem.

Mimo scope naopak zůstává:

- Mzdová agenda a personalistika
- Daňová evidence, účetní deník, hlavní kniha, rozvaha, výsledovka
- Skladová evidence a výroba

Standardní workflow je: ve MyInvoice vystavíš a eviduješ doklady, vygeneruješ
výkazy DPH a jednou měsíčně exportuješ ISDOC nebo Pohoda XML a předáš účetní
(nebo nahraješ do účetního programu).

## 1.14 Co manuál neobsahuje

- Vývojářskou dokumentaci API → viz `source/04-api.md` v repu projektu
- Detaily databázového schématu → viz `source/02-database.md`
- Specifikace jednotlivých formátů (ISDOC, Pohoda XML) → odkaz v
  [15. Exporty](15_Exporty.md)

## 1.15 Instalace aplikace na plochu (PWA)

MyInvoice lze z podporovaného prohlížeče nainstalovat jako aplikaci. Otevři
menu prohlížeče a zvol **Nainstalovat aplikaci** nebo **Přidat na plochu**.
Na iPhonu a iPadu je volba **Přidat na plochu** v nabídce Sdílet v Safari.
Nainstalovaná aplikace se spouští ve vlastním okně a má ikonu MyInvoice.

Instalace vyžaduje zabezpečené HTTPS připojení (výjimkou je lokální
`localhost`). Při provozu jen přes nezabezpečenou LAN adresu se nabídka
instalace nemusí zobrazit.

Do mezipaměti se ukládají pouze statické soubory aplikace, jako jsou skripty,
styly, fonty a ikony. HTML stránky ani odpovědi API se neukládají. Pro práci
s fakturami a ostatními daty proto aplikace stále potřebuje spojení se serverem.
