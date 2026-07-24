# MyInvoice.cz — Architektura a struktura repa

## 1. Repo layout

```
myinvoice.cz/
├── cfg.php                       # konfigurace (commit)
├── cfg.local.php                 # per-env override (gitignore)
├── styles/                       # statické assety servírované webem
│   ├── logo.svg                  # MyInvoice mark (64x64 viewBox)
│   ├── logo-wordmark.svg         # MyInvoice + text (240x64)
│   ├── invoice.css               # styly pro PDF i HTML preview faktury
│   └── fonts/                    # Inter, Geist Mono (woff2)
│
├── api/                          # PHP backend (Slim 4)
│   ├── public/
│   │   ├── index.php             # FrontController, jediný entry point
│   │   ├── web.config            # IIS rewrite + headers
│   │   └── .htaccess             # Apache rewrite + headers
│   ├── src/
│   │   ├── Action/               # ADR pattern: jeden invokable per endpoint
│   │   │   ├── Auth/{LoginAction, LogoutAction, ChangePasswordAction, ForgotAction, ResetAction}.php
│   │   │   ├── Client/{ListAction, GetAction, CreateAction, UpdateAction, ArchiveAction}.php
│   │   │   ├── Project/...
│   │   │   ├── Invoice/{ListAction, GetAction, CreateAction, UpdateAction, IssueAction, CloneAction,
│   │   │   │           DeleteAction, PdfAction, SendEmailAction, MarkPaidAction, CancelAction}.php
│   │   │   ├── WorkReport/...
│   │   │   ├── Supplier/...
│   │   │   ├── AresVies/{AresAction, ViesAction}.php
│   │   │   └── Codebook/{VatRatesAction, CountriesAction, CurrenciesAction}.php
│   │   ├── Domain/               # entity + value objects (anemic je OK pro tento rozsah)
│   │   │   ├── Invoice.php
│   │   │   ├── InvoiceItem.php
│   │   │   └── ...
│   │   ├── Repository/           # PDO repos, jeden per agregát
│   │   │   ├── InvoiceRepository.php
│   │   │   ├── ClientRepository.php
│   │   │   └── ...
│   │   ├── Service/              # business logika
│   │   │   ├── Auth/{Authenticator, BruteForceGuard, PasswordHasher, SessionManager}.php
│   │   │   ├── Invoice/{InvoiceCalculator, InvoiceCloner, VarsymbolGenerator}.php
│   │   │   ├── Pdf/{InvoicePdfRenderer}.php
│   │   │   ├── Qr/{QrPaymentGenerator}.php   # adaptace z Payment::qrCode()
│   │   │   ├── Mail/{Mailer, TemplateRenderer}.php
│   │   │   ├── Ares/{AresClient, ViesClient}.php
│   │   │   └── ActivityLogger.php
│   │   ├── Middleware/
│   │   │   ├── IpAllowlistMiddleware.php    # první v pipeline (před auth)
│   │   │   ├── FirstRunLockMiddleware.php   # 423 pokud users je prázdná
│   │   │   ├── AuthMiddleware.php
│   │   │   ├── CsrfMiddleware.php
│   │   │   ├── CorsMiddleware.php           # jen pro dev (Vite dev server)
│   │   │   ├── RateLimitMiddleware.php
│   │   │   ├── JsonBodyMiddleware.php
│   │   │   └── ErrorHandlerMiddleware.php
│   │   ├── Infrastructure/
│   │   │   ├── Database/{Connection.php, MigrationRunner.php}
│   │   │   ├── Cache/{RedisCache.php, MariaMemoryCache.php, CacheFactory.php}
│   │   │   └── Config/Config.php
│   │   └── Http/
│   │       └── ResponseFactory.php
│   ├── templates/                # Twig
│   │   ├── invoice/
│   │   │   ├── invoice.twig
│   │   │   ├── work_report.twig
│   │   │   └── pdf.css
│   │   └── email/
│   │       ├── invoice_send.cs.twig
│   │       ├── invoice_send.en.twig
│   │       ├── password_reset.cs.twig
│   │       └── ...
│   ├── tests/
│   │   ├── Unit/...
│   │   └── Integration/...
│   ├── bin/
│   │   ├── migrate.php
│   │   ├── seed.php
│   │   └── cron-cleanup.php
│   ├── composer.json
│   └── phpunit.xml
│
├── web/                          # Vue 3 frontend
│   ├── public/
│   │   └── favicon.svg
│   ├── src/
│   │   ├── main.ts
│   │   ├── App.vue
│   │   ├── router/index.ts
│   │   ├── stores/{auth, clients, projects, invoices, supplier, codebook}.ts
│   │   ├── api/{client, axios.ts}.ts        # axios instance + endpoint wrappery
│   │   ├── pages/
│   │   │   ├── Login.vue, ForgotPassword.vue, ResetPassword.vue
│   │   │   ├── Dashboard.vue
│   │   │   ├── clients/{ClientList, ClientDetail, ClientForm}.vue
│   │   │   ├── projects/...
│   │   │   ├── invoices/{InvoiceList, InvoiceEditor, InvoicePreview}.vue
│   │   │   ├── supplier/SupplierSettings.vue
│   │   │   └── settings/{Users, EmailTemplates, ActivityLog}.vue
│   │   ├── components/
│   │   │   ├── ui/{Button, Input, Select, Modal, Toast, Table}.vue
│   │   │   ├── invoice/{ItemRow, WorkReportEditor, TotalsBox}.vue
│   │   │   └── layout/{AppShell, Sidebar, TopBar}.vue
│   │   ├── composables/{useAresLookup, useViesLookup, useDebouncedRef, useToast}.ts
│   │   ├── i18n/{cs.json, en.json, index.ts}
│   │   ├── styles/{main.css, tailwind.css}
│   │   └── assets/
│   │       └── logo.svg
│   ├── index.html
│   ├── vite.config.ts
│   ├── tsconfig.json
│   ├── package.json
│   └── tailwind.config.ts
│
├── db/
│   ├── migrations/
│   │   ├── 0001_init.sql
│   │   ├── 0002_seed_codebooks.sql
│   │   └── ...
│   └── seeds/
│       └── dev_demo.sql
│
├── log/                          # gitignore, rotující logy (Monolog)
│   └── app-YYYY-MM-DD.log
│
├── storage/                      # gitignore, runtime data
│   ├── invoices/                 # cached PDF: YYYY-MM/Faktura-YY-MM-NNN.pdf
│   ├── sessions/                 # fallback pokud bez Redis
│   ├── uploads/                  # supplier logo, signature
│   ├── cache/                    # Twig compile cache
│   └── backup/                   # mariadb-dump archivy
│
├── source/                       # tato dokumentace (markdown specifikace)
├── .gitignore
├── README.md
├── CLAUDE.md
└── docker-compose.dev.yml        # volitelně pro lokální dev (mariadb + redis)
```

## 2. Backend — request flow

```
HTTP request
   │
   ▼
[IIS / Apache]  → URL rewrite → /api/index.php
   │
   ▼
Slim App
   ├── ErrorHandlerMiddleware (catch-all → JSON error)
   ├── IpAllowlistMiddleware (403 mimo povolené IP rozsahy, pokud aktivní)
   ├── FirstRunLockMiddleware (423 pokud users je prázdná, kromě /setup-status, /setup, /health)
   ├── CorsMiddleware (jen dev origin)
   ├── JsonBodyMiddleware (parse body)
   ├── RateLimitMiddleware (per IP, např. 60 req/min)
   ├── CsrfMiddleware (vyžaduje X-CSRF-Token pro POST/PUT/DELETE)
   ├── AuthMiddleware (kromě whitelistovaných routes)
   ▼
Action::__invoke(Request, Response)
   │
   ├── Validation (Respect/Validation)
   ├── Service call (business logic)
   ├── Repository (PDO prepared statement)
   ▼
JSON response
```

**ADR pattern**: jeden Action class = jeden endpoint. Bez „fat controllers". Všechna business logika v Service vrstvě, IO v Repository.

## 3. Frontend — architektura

- **Vue 3** s `<script setup>` + TypeScript
- **Pinia** stores: `auth`, `supplier`, `clients`, `projects`, `invoices`, `codebook` (vat rates, countries, currencies)
- **Vue Router**: lazy-loaded chunks per page, guard `requiresAuth` přes `useAuthStore`
- **Axios** instance:
  - `baseURL: '/api'` v produkci, `'http://localhost:8080/api'` v devu (Vite proxy)
  - interceptor: přidá `X-CSRF-Token` z auth store
  - 401 → redirect na `/login`
- **Form validace**: VeeValidate + Yup (lehké, dobře integrované)
- **Tailwind 4**: `@theme` block s custom paletou (emerald-600/zinc-900), JIT
- **i18n**: `vue-i18n@11`, JSON soubory, lazy-load per locale

### Build a deploy
- Vývoj: `pnpm dev` (Vite, port 5173, proxy na PHP `:8080`)
- Build: `pnpm build` → `web/dist/` → kopíruje se do `api/public/` při deploy (single origin)
- PHP backend slouží i statiku v produkci (přes `index.html` fallback v `web.config`/`.htaccess`)

### PWA — service worker

`web/public/service-worker.js` se servíruje z rootu (`/service-worker.js`), aby měl scope `/`.
Rewrite pravidla jsou v `.htaccess`, `web.config` i `docker/nginx.conf`; sám SW a
`manifest.webmanifest` jdou vždy s `no-store`, aby se aktualizace nezablokovala.

Strategie podle cesty:

| Cesta | Strategie | Proč |
| --- | --- | --- |
| `/api`, `/api/*` | žádná cache (`cache: 'no-store'`) | fakturační data nesmí zastarat |
| HTML navigace | SW nezachytává | `index.html` drží mapu na hash-chunky |
| `/assets/`, `/pwa/` | cache-first | Vite hashuje názvy, ikony jsou stabilní |
| `/styles/` | stale-while-revalidate | nehashovaná statika (`invoice.css`, `logo.svg`) — cache-first by ji držel až do bumpu `STATIC_CACHE` |

Regresní testy: `pnpm test:pwa` (`web/tests/*.test.mjs`, běží i v CI).

**Kill-switch.** Zaregistrovaný service worker žije v prohlížečích dál i po odstranění
z kódu. Cesta zpět je `web/public/service-worker.kill.js` — odregistruje SW, smaže jeho
cache a obnoví otevřená okna. Nasazení po buildu, před publikací:

```
cp web/public/service-worker.kill.js web/dist/service-worker.js
```

Registrace v `web/src/main.ts` se přitom nechává být (nainstaluje kill-switch, který se
hned zase odregistruje). Odstranit ji jde, až budou klienti prokazatelně čistí.

## 4. IIS — `web.config`

Klíčové pravidlo: SPA fallback (vše co není soubor a nezačíná `/api/` jde na `index.html`), API jde na `index.php`.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <rewrite>
      <rules>
        <rule name="API" stopProcessing="true">
          <match url="^api/(.*)$" />
          <action type="Rewrite" url="api/index.php" appendQueryString="true" />
        </rule>
        <rule name="SPA fallback" stopProcessing="true">
          <match url=".*" />
          <conditions logicalGrouping="MatchAll">
            <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
            <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
          </conditions>
          <action type="Rewrite" url="index.html" />
        </rule>
      </rules>
      <outboundRules>
        <rule name="Add HSTS">
          <match serverVariable="RESPONSE_Strict_Transport_Security" pattern=".*" />
          <conditions><add input="{HTTPS}" pattern="on" /></conditions>
          <action type="Rewrite" value="max-age=31536000; includeSubDomains" />
        </rule>
      </outboundRules>
    </rewrite>
    <httpProtocol>
      <customHeaders>
        <add name="X-Content-Type-Options" value="nosniff" />
        <add name="X-Frame-Options" value="DENY" />
        <add name="Referrer-Policy" value="same-origin" />
        <add name="Content-Security-Policy"
             value="default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'" />
      </customHeaders>
    </httpProtocol>
    <staticContent>
      <remove fileExtension=".woff2" />
      <mimeMap fileExtension=".woff2" mimeType="font/woff2" />
      <clientCache cacheControlMode="UseMaxAge" cacheControlMaxAge="365.00:00:00" />
    </staticContent>
    <handlers>
      <add name="PHP_FastCGI" path="*.php" verb="*"
           modules="FastCgiModule" scriptProcessor="C:\Program Files\PHP\v8.5\php-cgi.exe"
           resourceType="Either" />
    </handlers>
  </system.webServer>
</configuration>
```

## 5. Apache — `.htaccess`

```apache
RewriteEngine On

# HTTPS redirect
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]

# API → index.php
RewriteRule ^api/(.*)$ api/index.php [L,QSA]

# SPA fallback
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.html [L]

# Headers
<IfModule mod_headers.c>
  Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
  Header always set X-Content-Type-Options "nosniff"
  Header always set X-Frame-Options "DENY"
  Header always set Referrer-Policy "same-origin"
  Header always set Content-Security-Policy "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'"
</IfModule>

# Cache statiky
<FilesMatch "\.(woff2|woff|ttf|js|css|svg|png|jpg|webp)$">
  Header set Cache-Control "public, max-age=31536000, immutable"
</FilesMatch>

# Bez listingu
Options -Indexes
```

## 6. Konfigurace (`cfg.php` v rootu repa)

Veškerá konfigurace žije v jediném PHP souboru **`cfg.php`** v rootu repa (vrací asociativní pole). Důvody pro PHP místo `.env`:

- Není třeba parser (`vlucas/phpdotenv` nepotřebujeme)
- Lze používat PHP konstrukty (např. `getenv()` jako fallback, ternární operátor pro per-env hodnoty)
- IIS i Apache umí jednoduše chránit `*.php` mimo `public/` složku — `cfg.php` v rootu (mimo `api/public/`) tedy není veřejně přístupný
- Per-environment override: `cfg.local.php` (gitignored) přepisuje `cfg.php` pomocí `array_replace_recursive`

### `cfg.php` (commitnuto do repa, default hodnoty)
```php
<?php
return [
    'app' => [
        'env'    => 'production',
        'debug'  => false,
        'url'    => 'https://myinvoice.cz',
        'pepper' => 'CHANGE-ME-32B-BASE64',     // změnit v cfg.local.php!
        'timezone' => 'Europe/Prague',
        'locale_default' => 'cs',
    ],
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'myinvoice',
        'user'    => 'root',
        'pass'    => '',                        // skutečné heslo nastavit v cfg.local.php (gitignored)
        'charset' => 'utf8mb4',
        'socket'  => null,
        // pro lokální dev: 'pass' => '...' v cfg.local.php
    ],
    'redis' => [
        'enabled' => true,                      // pokud false, použije se MariaDB MEMORY fallback
        'host'    => '127.0.0.1',
        'port'    => 6379,
        'auth'    => null,
        'db'      => 0,
        'prefix'  => 'myinvoice:',
    ],
    'session' => [
        'driver'        => 'auto',              // auto | redis | db
        'lifetime_days' => 30,
        'cookie_name'   => 'myinvoice_session',
        'cookie_secure' => true,
        'cookie_samesite' => 'Lax',
    ],
    'smtp' => [
        'host'       => '',
        'port'       => 465,
        'user'       => '',
        'pass'       => '',
        'from_email' => 'faktury@myinvoice.cz',
        'from_name'  => 'MyInvoice',
        'encryption' => 'ssl',                  // ssl | tls | ''
    ],
    'ares' => [
        'api'       => 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty',
        'cache_ttl' => 86400,
        'timeout'   => 5,
    ],
    'vies' => [
        'wsdl'      => 'http://ec.europa.eu/taxation_customs/vies/services/checkVatService.wsdl',
        'cache_ttl' => 86400,
        'timeout'   => 8,
    ],
    'logging' => [
        'level'    => 'info',                   // debug | info | notice | warning | error
        'path'     => __DIR__ . '/log/app.log',
        'max_files'=> 90,
    ],
    'storage' => [
        'invoices_dir' => __DIR__ . '/storage/invoices',
        'uploads_dir'  => __DIR__ . '/storage/uploads',
        'backup_dir'   => __DIR__ . '/storage/backup',
        'sessions_dir' => __DIR__ . '/storage/sessions',
    ],
    'qr' => [
        'czk_constant_symbol' => '0308',
    ],
    'rate_limits' => [
        'login_per_min_per_ip'    => 10,
        'forgot_per_hour_per_email' => 3,
        'mutation_per_min_per_user' => 60,
        'read_per_min_per_user'     => 300,
        'ares_per_min_per_user'     => 30,
    ],
    'brute_force' => [
        'captcha_after'   => 5,           // selhání / 5 min → captcha
        'lockout_15m_at'  => 10,          // selhání / 15 min → lockout 15 min
        'lockout_24h_at'  => 30,          // selhání / 60 min → lockout 24h + email
        'window_seconds'  => [300, 900, 3600],
    ],
    'ip_allowlist' => [
        'enabled' => false,               // default: vypnuto, dostupné odkudkoli
        'mode'    => 'block',             // 'block' | 'log_only'
        'allow'   => [
            // '127.0.0.1',
            // '192.168.1.0/24',
            // '2001:db8::/56',
            // '2a01:430:1c::/64',
        ],
        'apply_to' => 'all',              // 'all' | 'admin_only' | 'mutations_only'
        'trusted_proxies' => [
            // '10.0.0.0/8',
        ],
        'header' => 'X-Forwarded-For',
    ],
];
```

### `cfg.local.php` (gitignored, per-environment override)
```php
<?php
// Lokální dev override — NIKDY necommitovat
return [
    'app' => [
        'env'    => 'development',
        'debug'  => true,
        'url'    => 'http://localhost:8080',
        'pepper' => 'local-dev-pepper-base64...',
    ],
    'db' => [
        'name' => 'myinvoice_dev',
    ],
    'smtp' => [
        'host' => 'mailhog',
        'port' => 1025,
        'encryption' => '',
    ],
    'logging' => [ 'level' => 'debug' ],
];
```

### Loader (`api/src/Infrastructure/Config/Config.php`)
```php
public static function load(): array {
    $base   = require dirname(__DIR__, 4) . '/cfg.php';
    $local  = is_file(dirname(__DIR__, 4) . '/cfg.local.php')
            ? require dirname(__DIR__, 4) . '/cfg.local.php'
            : [];
    return array_replace_recursive($base, $local);
}

public function get(string $path, mixed $default = null): mixed {
    // dot notation: $cfg->get('db.host')
}
```

### `.gitignore`
```
/cfg.local.php
/storage/
/api/public/web/    # pokud build kopíruje sem dist
/web/node_modules/
/web/dist/
/api/vendor/
```

> Bezpečnost: `cfg.php` je **mimo** `api/public/` (web rootu), takže není přístupný přes HTTP ani když by někdo špatně nastavil server. Navíc `*.php` mimo public se obvykle nepoužívá k servírování.

## 7. Závislosti (composer.json — hlavní)

```json
{
  "require": {
    "php": "^8.5",
    "slim/slim": "^4.13",
    "slim/psr7": "^1.6",
    "php-di/php-di": "^7.0",
    "respect/validation": "^3.1",
    "monolog/monolog": "^3.7",
    "twig/twig": "^3.10",
    "mpdf/mpdf": "^8.2",
    "symfony/mailer": "^8.0",
    "symfony/mime": "^8.0",
    "symfony/uid": "^8.0",
    "guzzlehttp/guzzle": "^7.9",
    "rikudou/czqrpayment": "^5.3",
    "rikudou/iban": "^1.3",
    "smhg/sepa-qr-data": "^3.0",
    "chillerlan/php-qrcode": "^6.0",
    "predis/predis": "^3.4",
    "enshrined/svg-sanitize": "^0.22"
  },
  "require-dev": {
    "phpunit/phpunit": "^13.0",
    "phpstan/phpstan": "^2.0",
    "friendsofphp/php-cs-fixer": "^3.64"
  }
}
```

## 8. Frontend závislosti (package.json — hlavní)

```json
{
  "dependencies": {
    "vue": "^3.5.13",
    "vue-router": "^5.0.6",
    "pinia": "^3.0.4",
    "axios": "^1.16.0",
    "@vueuse/core": "^14.3.0",
    "vue-i18n": "^11.0.1",
    "chart.js": "^4.5.1",
    "vue-chartjs": "^5.3.3"
  },
  "devDependencies": {
    "vite": "^8.0.10",
    "@vitejs/plugin-vue": "^6.0.6",
    "typescript": "^5.7.3",
    "vue-tsc": "^2.2.0",
    "tailwindcss": "^4.0.0",
    "@tailwindcss/vite": "^4.2",
    "@types/node": "^22.10.5"
  }
}
```

## 9. Cron / scheduled úlohy

| Frekvence | Skript | Popis |
|---|---|---|
| 5 min | `bin/cron-cleanup.php login_attempts` | smazat `login_attempts` > 1h |
| 1 hod | `bin/cron-cleanup.php sessions` | smazat expirované DB sessions |
| 1 den (3:00) | `bin/cron-backup.php` | mariadb-dump → gzip do `storage/backup/` |
| 1 den (4:00) | `bin/cron-cleanup.php logs` | rotace logů (Monolog už dělá, jen smazat > 90 dní) |
| 1 měsíc (1. den) | `bin/cron-archive-invoices.php` | export issued+paid → ZIP |

Na Windows: Task Scheduler. Na Linux: crontab.

## 10. Testování

- **Unit**: `InvoiceCalculator`, `InvoiceCloner` (regex inkrement měsíce!), `VarsymbolGenerator`, `BruteForceGuard`, `QrPaymentGenerator`
- **Integration**: API endpointy proti testovací MariaDB (per-test transakce + rollback)
- **E2E**: Playwright (volitelně pro M5+) — login → vytvoř klienta → vytvoř fakturu → stáhni PDF
- **Cílové pokrytí**: 70%+ na Service vrstvě (kde žije logika), nižší u Action/Repository

## 11. Deployment

### Produkce (myinvoice.cz)
1. CI build (GitHub Actions): `pnpm build` + `composer install --no-dev`
2. Artifact = ZIP celého repa bez `node_modules`, `tests/`, `.git`
3. Deploy přes WebDeploy (IIS) nebo rsync (Apache)
4. Spustit migrace: `php bin/migrate.php`
5. Cache clear: `rm -rf storage/cache/twig/*`

### Dev (dev.myinvoice.cz)
- Stejně, ale `APP_ENV=development`, `APP_DEBUG=true`
- Auto-deploy na push do `develop` branch
- Sdílí instanci MariaDB s prod, ale jinou databázi (`myinvoice_dev`)

## 12. Branching

- `main` = produkce (deploy automatic na myinvoice.cz)
- `develop` = staging (deploy automatic na dev.myinvoice.cz)
- `feature/*` → PR do `develop` → po review merge
- Release: merge `develop` → `main`, tag `vX.Y.Z`
