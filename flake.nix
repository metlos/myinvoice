{
  description = "MyInvoice self-hosted fakturace — dev shell + production package";

  inputs.nixpkgs.url = "github:nixos/nixpkgs?ref=nixos-unstable";

  outputs = { self, nixpkgs }:
    let
      supportedSystems = [ "x86_64-linux" "aarch64-linux" "x86_64-darwin" "aarch64-darwin" ];

      # Verze je jediný zdroj pravdy — soubor VERSION v rootu (jako release-bundle.sh).
      version = nixpkgs.lib.fileContents ./VERSION;

      phpExtensions = { enabled, all }:
        enabled ++ (with all; [
          pdo pdo_mysql mbstring openssl gd intl zip bcmath exif redis
        ]);

      # pkgs pro daný systém; crossSystem (kanonický GNU triple, ne krátký
      # "aarch64-linux" tvar — jinak spadneš z Hydra cache, viz vps.nix
      # .claude/cross-build-notes.md) přepne na skutečný cross build:
      # buildPlatform = system, hostPlatform = crossSystem.
      mkPkgs = system: crossSystem: import nixpkgs ({
        inherit system;
      } // (if crossSystem == null then { } else { crossSystem = { config = crossSystem; }; }));

      # Per-system helper: předá pkgs + PHP s potřebnými rozšířeními do každého výstupu.
      forAllSystems = f: nixpkgs.lib.genAttrs supportedSystems (system: f rec {
        inherit system;
        pkgs = nixpkgs.legacyPackages.${system};
        php = pkgs.php85.withExtensions phpExtensions;
      });

      # Sestaví packages.{web,vendor,myinvoice} pro danou instanci pkgs (nativní i cross).
      #
      # buildPkgs (= pkgs.pkgsBuildHost) je tu záměrně použit pro VŠECHNO, co se při
      # buildu skutečně SPOUŠTÍ jako program (php+composer pro `composer install`,
      # node+pnpm pro `pnpm install`/`pnpm build`) — u cross buildu (buildPlatform=
      # x86_64-linux, hostPlatform=aarch64-linux) by jinak `pkgs.php85`/`pkgs.nodejs_24`
      # byly aarch64 binárky, které build stroj nemůže spustit bez QEMU (viz Gotcha 2
      # v cross-build-notes.md — `pkgs.someHelper { ... }` volaný jako holá funkce se
      # nespliceruje samo, na rozdíl od položek v nativeBuildInputs). U nativního buildu
      # je pkgsBuildHost == pkgs, takže žádná změna chování.
      #
      # Výstup `vendor` (composer.lock obsahuje jen čisté PHP knihovny/skripty, žádné
      # zkompilované rozšíření) i `web` (statické JS/CSS/HTML pro prohlížeč) jsou svým
      # obsahem architekturně nezávislé — proto je naprosto v pořádku je sestavit čistě
      # buildPkgs nástroji, aniž by bylo potřeba cokoliv cross-kompilovat pro aarch64.
      mkOutputs = pkgs:
        let
          buildPkgs = pkgs.pkgsBuildHost;
          buildPhp = buildPkgs.php85.withExtensions phpExtensions;

          # 1) Frontend (Vue → web/dist) přes pnpm-lock.yaml (lockfileVersion 9 → pnpm 10).
          #    pnpm.fetchDeps je fixed-output derivation: hash závisí na pnpm-lock.yaml.
          #    NEUPRAVUJ ručně — obnovuje ho .github/workflows/update-nix-hashes.yml.
          web = pkgs.stdenvNoCC.mkDerivation (finalAttrs: {
            pname = "myinvoice-web";
            inherit version;
            src = ./web;

            pnpmDeps = buildPkgs.fetchPnpmDeps {
              inherit (finalAttrs) pname version src;
              pnpm = buildPkgs.pnpm_10;
              fetcherVersion = 3;
              hash = "sha256-MDqCnbtFv4XjkqUmTZDzVsVsrL+s0qM30kbE2hQssY0="; # @pnpm-deps-hash (auto-updated by CI)
            };

            nativeBuildInputs = [ buildPkgs.nodejs_24 buildPkgs.pnpm_10 buildPkgs.pnpmConfigHook ];

            buildPhase = ''
              runHook preBuild
              pnpm build
              runHook postBuild
            '';

            installPhase = ''
              runHook preInstall
              cp -r dist "$out"
              runHook postInstall
            '';
          });

          # 2) PHP backend deps (api/vendor) přes composer.lock.
          #    vendorHash je fixed-output derivation: závisí na composer.lock.
          #    NEUPRAVUJ ručně — obnovuje ho .github/workflows/update-nix-hashes.yml.
          #    Výstup je kompletní projekt v $out/share/php/<pname>; bereme z něj jen vendor/.
          #    buildPhp (viz výše) — composer install běží při buildu na build stroji.
          vendor = buildPhp.buildComposerProject (finalAttrs: {
            pname = "myinvoice-api";
            inherit version;
            src = ./api;
            vendorHash = "sha256-wfR06sAFfXJhs3N7pMBr0hp2f3bVtRZeFgxFcQtHoRU="; # @composer-vendor-hash (auto-updated by CI)
          });

          # 3) Kompletní nasaditelný strom aplikace (docroot = root, viz .htaccess).
          #    = tracked zdroje (self) + web/dist + api/vendor. Mirror cmd/release-bundle.sh,
          #    ale čistě v Nix store — tohle je to, na co míří nixosModule.
          myinvoice = pkgs.stdenvNoCC.mkDerivation {
            pname = "myinvoice";
            inherit version;
            src = self;

            dontConfigure = true;
            dontBuild = true;

            installPhase = ''
              runHook preInstall
              mkdir -p "$out"
              cp -r . "$out/"
              chmod -R u+w "$out"
              # web/dist a api/vendor jsou gitignored (nejsou v self) — vrstvíme je z FOD buildů.
              rm -rf "$out/web/dist" "$out/api/vendor"
              cp -r ${web} "$out/web/dist"
              cp -r ${vendor}/share/php/myinvoice-api/vendor "$out/api/vendor"
              # cfg.php je gitignored (jako cfg.local.php), takže v $out chybí — bez
              # něj Config::load() hodí RuntimeException (viz Dockerfile stub).
              echo '<?php return [];' > "$out/cfg.php"
              runHook postInstall
            '';

            # PHP/JS strom — žádné ELF binárky k patchování.
            dontFixup = true;
          };
        in {
          inherit web vendor myinvoice;
          default = myinvoice;
        };

      # aarch64-linux se nebuildí nativně (=> QEMU emulace celého PHP/Node buildu na
      # x86_64 build stroji), ale skutečným cross buildem — viz mkOutputs výše.
      aarch64CrossPkgs = mkPkgs "x86_64-linux" "aarch64-unknown-linux-gnu";

      nativePackages = forAllSystems ({ pkgs, ... }: mkOutputs pkgs);
    in {
      # Pozor: `//` je jen mělký merge (viz Gotcha 4 v cross-build-notes.md) — proto
      # slučujeme až o úroveň níž, aby zůstaly zachované x86_64-linux/x86_64-darwin/
      # aarch64-darwin nativní výstupy z `nativePackages` a přepsal se jen aarch64-linux.
      packages = nativePackages // {
        aarch64-linux = mkOutputs aarch64CrossPkgs;
      };

      devShells = forAllSystems ({ pkgs, php, ... }: {
        default = pkgs.mkShell {
          packages = [
            php
            php.packages.composer
            pkgs.nodejs_24
            pkgs.pnpm_10
            pkgs.mariadb       # mariadb client + server binaries
            pkgs.redis         # redis-cli + redis-server
          ];
          shellHook = ''
            echo "myinvoice dev shell — PHP $(php -r 'echo PHP_VERSION;'), Node $(node -v), pnpm $(pnpm -v)"
          '';
        };
      });

      nixosModules.myinvoice = { config, lib, pkgs, ... }:
        let
          cfg = config.services.myinvoice;

          phpExtensions = { enabled, all }:
            enabled ++ (with all; [
              pdo pdo_mysql mbstring openssl gd intl zip bcmath exif redis
            ]);

          # Non-secret env vars safe to embed in Nix store (unit Environment= lines).
          nonSecretEnv = [
            "MYINVOICE_DATA_DIR=${cfg.dataDir}"
            "MYINVOICE_APP_URL=${cfg.appUrl}"
            "MYINVOICE_TIMEZONE=${cfg.timezone}"
            "MYINVOICE_LOCALE=${cfg.locale}"
            "MYINVOICE_DB_HOST=${cfg.database.host}"
            "MYINVOICE_DB_PORT=${toString cfg.database.port}"
            "MYINVOICE_DB_NAME=${cfg.database.name}"
            "MYINVOICE_DB_USER=${cfg.database.user}"
            "MYINVOICE_REDIS_ENABLED=${if cfg.redis.enable then "1" else "0"}"
            "MYINVOICE_REDIS_HOST=${cfg.redis.host}"
            "MYINVOICE_REDIS_PORT=${toString cfg.redis.port}"
            "MYINVOICE_REDIS_DB=${toString cfg.redis.db}"
            "MYINVOICE_REDIS_PREFIX=${cfg.redis.prefix}"
            "MYINVOICE_SMTP_HOST=${cfg.smtp.host}"
            "MYINVOICE_SMTP_PORT=${toString cfg.smtp.port}"
            "MYINVOICE_SMTP_ENCRYPTION=${cfg.smtp.encryption}"
            "MYINVOICE_SMTP_AUTH=${if cfg.smtp.authEnabled then "1" else "0"}"
            "MYINVOICE_SMTP_USER=${cfg.smtp.user}"
            "MYINVOICE_SMTP_FROM_EMAIL=${cfg.smtp.fromEmail}"
            "MYINVOICE_SMTP_FROM_NAME=${cfg.smtp.fromName}"
          ] ++ lib.optional (cfg.database.socket != null)
              "MYINVOICE_DB_SOCKET=${cfg.database.socket}";

          # Script that reads secret files and writes /run/myinvoice/env (mode 0640 root:root).
          # Must run as root to read sops-nix secrets (mode 0400).
          # EnvironmentFile= is processed before ExecStartPre= by systemd, so this must be
          # a separate oneshot service (myinvoice-env-setup) that all consumers depend on.
          genEnv = pkgs.writeShellScript "myinvoice-gen-env" ''
            install -m 0640 -o root -g root ${lib.escapeShellArg cfg.secretsFile} /run/myinvoice/env
          '';

          # Cron jobs from CronCatalog.php — systemd OnCalendar translations.
          cronJobs = [
            { name = "cleanup";                     script = "cron-cleanup";                     calendar = "*-*-* 03:00:00"; }
            { name = "backup";                      script = "cron-backup";                      calendar = "*-*-* 02:00:00"; }
            { name = "backup-pdf";                  script = "cron-backup-pdf";                  calendar = "*-*-* 02:30:00"; }
            { name = "backup-documents";            script = "cron-backup-documents";            calendar = "*-*-* 02:35:00"; }
            { name = "bank-scan";                   script = "cron-bank-scan";                   calendar = "*:0/30"; }
            { name = "scan-purchase-inbox";         script = "cron-scan-purchase-inbox";         calendar = "*:0/10"; }
            { name = "send-reminders";              script = "cron-send-reminders";              calendar = "Mon,Tue,Wed,Thu,Fri *-*-* 09:00:00"; }
            { name = "send-approval-reminders";     script = "cron-send-approval-reminders";     calendar = "Mon,Tue,Wed,Thu,Fri *-*-* 09:15:00"; }
            { name = "generate-recurring-invoices"; script = "cron-generate-recurring-invoices"; calendar = "*-*-* 06:30:00"; }
            { name = "version-check";               script = "cron-version-check";               calendar = "*-*-* 06:00:00"; }
          ];

          mkCronService = job: lib.nameValuePair "myinvoice-cron-${job.name}" {
            description = "MyInvoice cron: ${job.script}";
            requires = [ "myinvoice-env-setup.service" ];
            after    = [ "network.target" "myinvoice-env-setup.service" ];
            serviceConfig = {
              Type             = "oneshot";
              User             = cfg.user;
              Group            = cfg.group;
              EnvironmentFile  = "/run/myinvoice/env";
              Environment      = nonSecretEnv;
              ExecStart        = "${cfg.phpPackage}/bin/php ${cfg.package}/api/bin/${job.script}.php";
            };
          };

          mkCronTimer = job: lib.nameValuePair "myinvoice-cron-${job.name}" {
            description = "MyInvoice cron timer: ${job.script}";
            wantedBy    = [ "timers.target" ];
            timerConfig = {
              OnCalendar = job.calendar;
              Persistent = true;
            };
          };

        in {
          options.services.myinvoice = {
            enable = lib.mkEnableOption "MyInvoice self-hosted invoicing application";

            package = lib.mkOption {
              type        = lib.types.package;
              default     = self.packages.${pkgs.system}.myinvoice;
              defaultText = lib.literalExpression "self.packages.\${pkgs.system}.myinvoice";
              description = "The MyInvoice package to deploy.";
            };

            phpPackage = lib.mkOption {
              type        = lib.types.package;
              default     = pkgs.php85.withExtensions phpExtensions;
              defaultText = lib.literalExpression "pkgs.php85.withExtensions [pdo pdo_mysql mbstring openssl gd intl zip bcmath exif redis]";
              description = "PHP package with required extensions.";
            };

            dataDir = lib.mkOption {
              type        = lib.types.str;
              default     = "/var/lib/myinvoice";
              description = "Directory for persistent runtime data (uploads, backups, sessions, …).";
            };

            user = lib.mkOption {
              type    = lib.types.str;
              default = "myinvoice";
            };

            group = lib.mkOption {
              type    = lib.types.str;
              default = "myinvoice";
            };

            appUrl = lib.mkOption {
              type        = lib.types.str;
              example     = "https://invoice.example.com";
              description = "Public URL of the application (MYINVOICE_APP_URL).";
            };

            timezone = lib.mkOption {
              type    = lib.types.str;
              default = "Europe/Prague";
            };

            locale = lib.mkOption {
              type    = lib.types.str;
              default = "cs";
            };

            secretsFile = lib.mkOption {
              type        = lib.types.str;
              description = ''
                Path to a file in KEY=VALUE format containing all application secrets.
                Required keys:
                  MYINVOICE_PEPPER       — random secret mixed into every password hash (bcrypt
                                           suffix) and used as the HKDF seed for at-rest
                                           encryption when MYINVOICE_SECRET_KEY is absent.
                                           Mandatory in production; changing it invalidates all
                                           stored password hashes. Generate with:
                                           openssl rand -base64 32
                  MYINVOICE_DB_PASS      — required only when database.createLocally = false
                                           (password-based remote DB).
                Optional keys:
                  MYINVOICE_SECRET_KEY   — 32-byte AES-256 key (base64-encoded) used to encrypt
                                           sensitive data at rest: TOTP secrets, API tokens, bank
                                           e-mail credentials, PDF signing passwords, and AI API
                                           keys. Without it the app falls back to deriving a key
                                           from MYINVOICE_PEPPER via HKDF (weaker; health check
                                           will warn). Generate with: openssl rand -base64 32
                  MYINVOICE_REDIS_AUTH   — password for Redis AUTH (required when the Redis server
                                           is configured with requirepass / protected-mode password).
                                           Leave unset for unauthenticated (local) Redis.
                  MYINVOICE_SMTP_PASS    — SMTP account password for outgoing e-mail.
              '';
            };

            database = {
              host = lib.mkOption {
                type    = lib.types.str;
                default = "127.0.0.1";
              };
              port = lib.mkOption {
                type    = lib.types.port;
                default = 3306;
              };
              name = lib.mkOption {
                type    = lib.types.str;
                default = "myinvoice";
              };
              user = lib.mkOption {
                type    = lib.types.str;
                default = "myinvoice";
              };
              socket = lib.mkOption {
                type        = lib.types.nullOr lib.types.str;
                default     = null;
                description = "Unix socket for DB connection; overrides host/port when set.";
              };
              createLocally = lib.mkOption {
                type        = lib.types.bool;
                default     = false;
                description = "Let the NixOS MySQL module provision the DB and user. Uses socket auth — no password needed.";
              };
            };

            redis = {
              enable = lib.mkOption {
                type    = lib.types.bool;
                default = false;
              };
              host = lib.mkOption {
                type    = lib.types.str;
                default = "127.0.0.1";
              };
              port = lib.mkOption {
                type    = lib.types.port;
                default = 6379;
              };
              db = lib.mkOption {
                type    = lib.types.int;
                default = 0;
              };
              prefix = lib.mkOption {
                type    = lib.types.str;
                default = "myinvoice:";
              };
              createLocally = lib.mkOption {
                type        = lib.types.bool;
                default     = false;
                description = "Let the NixOS Redis module provision a local Redis instance. Enables redis.enable automatically.";
              };
            };

            smtp = {
              host = lib.mkOption {
                type    = lib.types.str;
                default = "";
              };
              port = lib.mkOption {
                type    = lib.types.port;
                default = 587;
              };
              encryption = lib.mkOption {
                type    = lib.types.str;
                default = "tls";
              };
              authEnabled = lib.mkOption {
                type    = lib.types.bool;
                default = true;
              };
              user = lib.mkOption {
                type    = lib.types.str;
                default = "";
              };
              fromEmail = lib.mkOption {
                type    = lib.types.str;
                default = "";
              };
              fromName = lib.mkOption {
                type    = lib.types.str;
                default = "MyInvoice";
              };
            };

            nginx = {
              serverName = lib.mkOption {
                type        = lib.types.str;
                description = "Nginx virtual host name (e.g. invoice.example.com).";
              };
              forceSSL = lib.mkOption {
                type    = lib.types.bool;
                default = false;
              };
              enableACME = lib.mkOption {
                type    = lib.types.bool;
                default = false;
              };
            };

            cron = {
              enable = lib.mkOption {
                type    = lib.types.bool;
                default = true;
                description = "Enable systemd timers for scheduled background tasks.";
              };
            };
          };

          config = lib.mkMerge [
            (lib.mkIf cfg.enable {

              users.users.${cfg.user} = {
                isSystemUser = true;
                group        = cfg.group;
                home         = cfg.dataDir;
              };
              users.groups.${cfg.group} = {};

              systemd.tmpfiles.rules = [
                "d '${cfg.dataDir}'                0750 ${cfg.user} ${cfg.group} - -"
                "d '${cfg.dataDir}/log'            0750 ${cfg.user} ${cfg.group} - -"
                "d '${cfg.dataDir}/storage'        0750 ${cfg.user} ${cfg.group} - -"
                "d '${cfg.dataDir}/storage/invoices' 0750 ${cfg.user} ${cfg.group} - -"
                "d '${cfg.dataDir}/uploads'        0750 ${cfg.user} ${cfg.group} - -"
                "d '${cfg.dataDir}/backup'         0750 ${cfg.user} ${cfg.group} - -"
                "d '${cfg.dataDir}/sessions'       0750 ${cfg.user} ${cfg.group} - -"
                "d '${cfg.dataDir}/cache'          0750 ${cfg.user} ${cfg.group} - -"
                "d '${cfg.dataDir}/private'        0750 ${cfg.user} ${cfg.group} - -"
                "d '${cfg.dataDir}/private/dkim'   0750 ${cfg.user} ${cfg.group} - -"
                "d '/run/myinvoice'                0750 root root - -"
              ];

              # Oneshot service that reads secret files and writes /run/myinvoice/env (0640 root:root).
              # Runs as root (no User=) so it can read sops-nix secrets (mode 0400).
              # All services that need secrets declare Requires + After this unit.
              systemd.services.myinvoice-env-setup = {
                description    = "Generate MyInvoice secrets environment file";
                wantedBy       = [ "multi-user.target" ];
                before         = [ "phpfpm-myinvoice.service" "myinvoice-migrate.service" ];
                serviceConfig  = {
                  Type            = "oneshot";
                  RemainAfterExit = true;
                  ExecStart       = "${genEnv}";
                };
              };

              services.phpfpm.pools.myinvoice = {
                user       = cfg.user;
                group      = cfg.group;
                phpPackage = cfg.phpPackage;
                settings   = {
                  "listen.owner"        = "nginx";
                  "listen.group"        = "nginx";
                  "pm"                  = "dynamic";
                  "pm.max_children"     = 16;
                  "pm.start_servers"    = 2;
                  "pm.min_spare_servers" = 1;
                  "pm.max_spare_servers" = 4;
                  # Workers inherit secrets injected via EnvironmentFile on the systemd service.
                  "clear_env"           = "no";
                };
                phpEnv = lib.filterAttrs (n: v: v != "") ({
                  MYINVOICE_DATA_DIR       = cfg.dataDir;
                  MYINVOICE_APP_URL        = cfg.appUrl;
                  MYINVOICE_TIMEZONE       = cfg.timezone;
                  MYINVOICE_LOCALE         = cfg.locale;
                  MYINVOICE_DB_HOST        = cfg.database.host;
                  MYINVOICE_DB_PORT        = toString cfg.database.port;
                  MYINVOICE_DB_NAME        = cfg.database.name;
                  MYINVOICE_DB_USER        = cfg.database.user;
                  MYINVOICE_REDIS_ENABLED  = if cfg.redis.enable then "1" else "0";
                  MYINVOICE_REDIS_HOST     = cfg.redis.host;
                  MYINVOICE_REDIS_PORT     = toString cfg.redis.port;
                  MYINVOICE_REDIS_DB       = toString cfg.redis.db;
                  MYINVOICE_REDIS_PREFIX   = cfg.redis.prefix;
                  MYINVOICE_SMTP_HOST      = cfg.smtp.host;
                  MYINVOICE_SMTP_PORT      = toString cfg.smtp.port;
                  MYINVOICE_SMTP_ENCRYPTION = cfg.smtp.encryption;
                  MYINVOICE_SMTP_AUTH      = if cfg.smtp.authEnabled then "1" else "0";
                  MYINVOICE_SMTP_USER      = cfg.smtp.user;
                  MYINVOICE_SMTP_FROM_EMAIL = cfg.smtp.fromEmail;
                  MYINVOICE_SMTP_FROM_NAME  = cfg.smtp.fromName;
                } // lib.optionalAttrs (cfg.database.socket != null) {
                  MYINVOICE_DB_SOCKET = cfg.database.socket;
                });
              };

              # Inject secrets into the phpfpm master process; workers inherit via clear_env=no.
              # requires (not just after) myinvoice-migrate.service: a failed migration must
              # block startup instead of serving requests against a stale/empty schema.
              systemd.services.phpfpm-myinvoice = {
                requires = [ "myinvoice-env-setup.service" "myinvoice-migrate.service" ];
                after    = [ "myinvoice-env-setup.service" "myinvoice-migrate.service" ];
                serviceConfig.EnvironmentFile = "/run/myinvoice/env";
              };

              systemd.services.myinvoice-migrate = {
                description     = "MyInvoice DB migrations";
                requires        = [ "myinvoice-env-setup.service" ];
                after           = [ "network.target" "myinvoice-env-setup.service" ];
                serviceConfig   = {
                  Type            = "oneshot";
                  RemainAfterExit = true;
                  User            = cfg.user;
                  Group           = cfg.group;
                  EnvironmentFile = "/run/myinvoice/env";
                  Environment     = nonSecretEnv;
                  ExecStart       = "${cfg.phpPackage}/bin/php ${cfg.package}/api/bin/migrate.php";
                };
              };

              services.nginx.virtualHosts.${cfg.nginx.serverName} = {
                inherit (cfg.nginx) forceSSL enableACME;
                root = "${cfg.package}";
                locations = {
                  # Block access to sensitive directories and config files.
                  "~ ^/(private|db|log|source|storage|tools|node_modules)(/|$)".extraConfig =
                    "return 403;";
                  "~ ^/api/(src|vendor/bin|vendor/tests)(/|$)".extraConfig =
                    "return 403;";
                  "~ /(cfg\\.php|cfg\\.local\\.php|\\.env|composer\\.)".extraConfig =
                    "return 403;";
                  # Hashed assets — long cache.
                  "/assets/" = {
                    alias       = "${cfg.package}/web/dist/assets/";
                    extraConfig = ''expires 1y; add_header Cache-Control "public, immutable";'';
                  };
                  # Slim front controller — /api and /api/*.
                  "~ ^/api(/.*)?$".extraConfig = ''
                    fastcgi_pass unix:${config.services.phpfpm.pools.myinvoice.socket};
                    include ${config.services.nginx.package}/conf/fastcgi_params;
                    fastcgi_param SCRIPT_FILENAME ${cfg.package}/api/public/index.php;
                    fastcgi_param PATH_INFO       $1;
                  '';
                  # User manual.
                  "/manual".extraConfig = ''
                    fastcgi_pass unix:${config.services.phpfpm.pools.myinvoice.socket};
                    include ${config.services.nginx.package}/conf/fastcgi_params;
                    fastcgi_param SCRIPT_FILENAME ${cfg.package}/manual/index.php;
                  '';
                  # SPA fallback.
                  "/".tryFiles = "$uri /web/dist/index.html";
                };
              };

            })

            (lib.mkIf (cfg.enable && cfg.cron.enable) {
              systemd.services = lib.listToAttrs (map mkCronService cronJobs);
              systemd.timers   = lib.listToAttrs (map mkCronTimer   cronJobs);
            })

            (lib.mkIf (cfg.enable && cfg.database.createLocally) {
              services.myinvoice.database.socket = lib.mkDefault "/run/mysqld/mysqld.sock";

              services.mysql = {
                enable  = true;
                package = pkgs.mariadb;
                ensureDatabases = [ cfg.database.name ];
                ensureUsers = [{
                  name = cfg.database.user;
                  ensurePermissions."${cfg.database.name}.*" = "ALL PRIVILEGES";
                }];
              };

              systemd.services.myinvoice-migrate = {
                requires = [ "mysql.service" ];
                after    = [ "mysql.service" ];
              };

              systemd.services.phpfpm-myinvoice = {
                requires = [ "mysql.service" ];
                after    = [ "mysql.service" ];
              };
            })

            (lib.mkIf (cfg.enable && cfg.redis.createLocally) {
              services.myinvoice.redis.enable = lib.mkDefault true;

              services.redis.servers.myinvoice = {
                enable = true;
                bind   = "127.0.0.1";
                port   = cfg.redis.port;
              };

              systemd.services.phpfpm-myinvoice = {
                requires = [ "redis-myinvoice.service" ];
                after    = [ "redis-myinvoice.service" ];
              };
            })
          ];
        };
    };
}
