# cPanel/Shared-Hosting: Generallösung für Deployment

Diese Anleitung beschreibt die Lösung unabhängig vom konkreten Konto. Die kontospezifischen Werte
(`pushgobt`, `corps-akademie.r4av.com`, `www-corpsakademie`) stehen in
[Namecheap/cPanel: Servereinrichtung](NAMECHEAP.md); hier werden sie als Platzhalter geführt:

| Platzhalter | Bedeutung | Beispiel |
| --- | --- | --- |
| `$HOME` | Home des cPanel-Kontos | `/home/pushgobt` |
| `$BASE` | private Basis (nicht öffentlich) | `$HOME/corps-akademie.r4av.com` |
| `$CHECKOUT` | Git-Checkout | `$HOME/repositories/Corps_Akademie` |
| `$WEBROOT` | öffentlicher Ordner = Document Root | `$HOME/www-corpsakademie` |

## 1. Grundidee

Drei getrennte Bereiche, verbunden über einen umschaltbaren Symlink:

```
$CHECKOUT   →  reiner Git-Checkout, niemals Document Root, enthält keine Daten
$BASE       →  releases/<commit>-<rand>/, shared/, current -> releases/..., current-next-*
$WEBROOT    →  nur index.php, .htaccess und assets/
```

- **Atomar:** Jedes Deployment baut ein vollständiges neues Release und schaltet erst am Ende
  `current` per Symlink um (`rename` ist atomar). Es gibt kein Halb-Update im laufenden Betrieb.
- **Unveränderlich:** Ein Release wird nie nachträglich bearbeitet. Ein Rollback ist ein Symlink-
  Wechsel auf ein älteres Release.
- **Getrennt:** `.env`, `storage/`, Logs und Sicherungen liegen ausschließlich unter `$BASE/shared`
  und sind über den Webroot nicht erreichbar. Jedes Release bekommt `shared/.env` als Symlink.
- **Serialisiert:** `shared/runtime.lock` wird von HTTP-Einstiegspunkt, CLI-Wrapper und Deployment
  gemeinsam über `flock` gehalten. Während eines Deployments bedient die Website 503 statt Fehler.
- **Selbstprüfend:** Vor der Aktivierung werden CLI-PHP, Web-PHP, HTTPS, Datenbanktreiber und
  Plattformanforderungen geprüft. Bei Fehlern bleibt der Wartungsmodus aktiv – bewusst **kein**
  automatischer Code-Rollback, weil die Datenbank schon migriert sein kann.

## 2. Verzeichnislayout

```
$CHECKOUT/                      # Git, sauber (kein dirty worktree)
  .cpanel.yml
  scripts/deploy-cpanel.sh
  scripts/cpanel-console.php
  deployment/cpanel/{run,runtime,deployment,entry}.php
  public/{index.php,.htaccess,assets/}
  bin/console

$BASE/
  shared/
    .env                        # 0600, APP_KEY, DB-Zugang, SMTP
    deploy.json                 # webroot, composer, replace_existing_site
    runtime.lock                # gemeinsame Sperre
    maintenance.flag            # existiert nur während eines Deployments
    last-deployment.json        # aktiver Commit
    storage/{logs,backups}      # persistent, überlebt jedes Release
    deployment-backups/         # getarnte Sicherung des alten Webroots
  releases/<commit12>-<rand>/   # vollständige Releases
  current -> releases/...       # aktives Release

$WEBROOT/                       # Document Root
  index.php                     # generierter Einstiegspunkt
  .htaccess                     # markierter Regelblock
  assets/                       # nur css/js/svg/png/jpg/jpeg/webp/ico/woff/woff2
```

## 3. Bausteine

### 3.1 `.cpanel.yml`

Ein einziger Task. Er läuft beim Klick auf **Deploy HEAD Commit** aus dem Checkout heraus:

```yaml
---
deployment:
  tasks:
    - /bin/bash $CHECKOUT/scripts/deploy-cpanel.sh --base=$BASE
```

`getopt` liest lange Optionen **nur** in der Form `--base=/pfad`. `--base /pfad` (mit Leerzeichen)
wird stillschweigend ignoriert und der Standardpfad (`$HOME/apps/corpsakademie`) verwendet.

### 3.2 `scripts/deploy-cpanel.sh`

Findet einen freigegebenen PHP-Interpreter für **CLI** und übergibt an `run.php`:

- Kandidaten aus `/opt/alt/php85`, `/opt/alt/php84`, `/opt/cloudlinux/...`, `/opt/cpanel/ea-php85|84`,
  zuletzt `command -v php`.
- Jeder Kandidat wird getestet auf: SAPI `cli`, alle Erweiterungen (`curl`, `intl`, `mbstring`,
  `pdo_pgsql`, `pgsql`, `sodium`, `xmlwriter`, `phar`) und den Patchstand
  (8.2.35 / 8.3.36 / 8.4.27 / 8.5.12 – CVE-2026-109618/619).
- Notnagel: `CORPS_DEPLOY_PHP=/absoluter/pfad/php bash scripts/deploy-cpanel.sh …`
- Das Skript muss **LF**-Zeilenenden haben. CRLF bricht mit `invalid option name` ab.

### 3.3 `deployment/cpanel/`

| Datei | Zweck |
| --- | --- |
| `run.php` | CLI-Frontend: `--init`, `--check`, `--help`, Standard = deploy |
| `deployment.php` | gesamte Logik: Prüfungen, Build, Aktivierung |
| `runtime.php` | `inspect()`/`validate()` lokal und `probe()` als Web-Sonde |
| `entry.php` | Vorlage für den Webroot-`index.php`, Platzhalter `__CORPS_DEPLOYMENT_BASE__` |

`--init` erzeugt `shared/` mit `.env` (inkl. zufälligem `APP_KEY`), `deploy.json`, `runtime.lock`
und überschreibt **nichts** Vorhandenes. Liegt bereits eine `.env` im Checkout, bricht `--init` ab.

`--check` prüft ohne Veröffentlichung: CLI-PHP, Pfadtrennung (Webroot darf weder in `$BASE` noch in
`$CHECKOUT` liegen und umgekehrt), keine privaten Namen im Webroot, keine fremden `.php`-Endpunkte,
keine Symlinks auf `index.php`/`.htaccess`/`assets`, `deploy.json`-Pfade absolut, Composer vorhanden,
`shared/.env` ohne `CHANGE-ME` und ohne Gruppen-/Weltrechte, `app/`-Konfiguration gültig.

`deploy()` macht danach:

1. `git diff --quiet` und `git diff --cached --quiet` → **sauberer Worktree Pflicht**.
2. `git rev-parse HEAD` → Release-Name `releases/<commit12>-<rand>`.
3. `git archive` → `tar -xf` in das neue Release (kein `.git`, keine Daten).
4. `shared/.env` als Symlink ins Release.
5. `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`, dann
   `composer check-platform-reqs --no-dev`.
6. Konfiguration im Release prüfen (`APP_ENV=production`, `APP_STORAGE_PATH` = `$BASE/shared/storage`).
7. **Web-Sonde:** schreibt `corps-deploy-check-<rand>.php` in den Webroot, ruft
   `https://APP_URL/<sonde>` per cURL (ohne Redirects, mit Zertifikatsprüfung), validiert Nonce,
   PHP-Version, `pdo_pgsql`, HTTPS-Erkennung und Argon2id – und löscht die Datei wieder.
8. `flock` auf `runtime.lock` (max. 45 s warten) → exklusiv für die Aktivierung.
9. Webroot und Assets sichern (`shared/deployment-backups/public-*.tar`), dann `maintenance.flag`
   setzen, `index.php` und `.htaccess` atomar schreiben, Assets verteilen.
10. `php bin/console db:migrate`, dann `doctor` – läuft im **neuen** Release.
11. `current`-Symlink über `current-next-<rand>` + `rename` umschalten,
    `shared/last-deployment.json` schreiben, `maintenance.flag` entfernen.

Der Webroot-`index.php` ist absichtlich **kein** Symlink: Er enthält den absoluten, gequoteten
privaten Basispfad, prüft Sperre und Wartungsflag und lädt dann `current/public/index.php`.
Dadurch liefert ein laufendes Deployment 503 statt 500.

### 3.4 `scripts/cpanel-console.php`

Für Cron und manuelle Läufe: nimmt optional `--base=/pfad`, validiert `shared/`, hält denselben
`runtime.lock` (shared) und bricht bei aktivem `maintenance.flag` mit Exit-Code 75 ab. Danach lädt es
`current/bin/console`. So können CLI-Jobs ein Deployment nicht überholen.

### 3.5 `bin/console`-Kommandos

| Kommando | Zweck |
| --- | --- |
| `install` | lokale Installation (`.env`, Migrationen) |
| `db:migrate` | geprüfte Migrationen anwenden (Sicherung vorher) |
| `doctor` | Konfiguration, Datenbanktreue, Fremdschlüssel, Migrations-Checksummen |
| `setup:link` | Einmallink (30 min) für das **erste** Konto – Rolle `superadmin` |
| `user:create` | weiteres Konto; verlangt interaktives TTY |
| `user:disable` | Konto deaktivieren, Sitzungen und Einmallinks widerrufen |
| `content:seed` | Präsentationsinhalte; auf dem Server nur mit `--presentation` bei `APP_MAIL_ENABLED=false` |
| `mail:work` | Stapel Identitätsmails (`--limit=` 1–100, `--if-enabled`) |
| `newsletter:work` | freigegebene Rundmails (`--limit=` 1–10, `--if-enabled`) |
| `db:backup` | konsistente Sicherung nach `shared/storage/backups` (`pg_dump`) |
| `session:prune` | abgelaufene Sitzungen und Rate-Limit-Buckets entfernen |

### 3.6 `shared/.env` (Pflichtwerte)

`APP_ENV=production`, `APP_URL=https://…` (HTTPS erzwungen), `APP_KEY` (32 Byte Base64, von `--init`),
`APP_KEY_ID`, `APP_DB_HOST`, `APP_DB_PORT`, `APP_DB_NAME`, `APP_DB_USER`, `APP_DB_PASSWORD`,
`APP_STORAGE_PATH=$BASE/shared/storage`, `SESSION_IDLE_SECONDS`, `SESSION_ABSOLUTE_SECONDS`,
`MAILER_DSN` (`smtps://…`), `MAIL_FROM`. Optional `NEWSLETTER_MAILER_DSN`, `NEWSLETTER_MAIL_FROM`,
`MAIL_REPLY_TO`, `MAIL_CONTACT_TO`. Siehe [.env.example](../.env.example).

`shared/deploy.json`: `webroot` (absolut, lokal vorhanden und beschreibbar), `composer`
(absoluter Pfad zur `composer.phar`), `replace_existing_site` (`true` nur als bewusste Einmalübernahme
eines bestehenden Webroots).

## 4. Installation auf einem neuen Host

1. **Datenbank anlegen** (cPanel → PostgreSQL-Datenbanken): Datenbank, Benutzer, Benutzer zuweisen.
   Tabellen entstehen durch die Migrationen, keine manuellen SQL-Schritte.
2. **Checkout anlegen** (cPanel → Git Version Control): Repository nach `$CHECKOUT` klonen. Die
   `.cpanel.yml` mit dem korrekten `--base=$BASE` muss enthalten sein.
3. **Private Vorlagen erzeugen:**
   ```bash
   cd $CHECKOUT
   bash scripts/deploy-cpanel.sh --init --base=$BASE
   ```
4. **Konfiguration ausfüllen:** `$BASE/shared/.env` (Domain, DB, SMTP, Absender) und
   `$BASE/shared/deploy.json` (Webroot, Composer-Pfad). `APP_KEY` aufbewahren, Rechte `chmod 600`.
   Der Webroot muss leer bzw. eine bewusste Übernahme sein.
5. **Trockenprüfung:**
   ```bash
   bash scripts/deploy-cpanel.sh --check --base=$BASE
   ```
6. **Veröffentlichen:** cPanel → **Deploy HEAD Commit** (oder denselben Befehl ohne `--check`).
7. **Erstkonto:** `setup:link` über den Wrapper, Link im Browser öffnen, Passwort ≥ 12 Zeichen,
   E-Mail bestätigen, danach TOTP-Mehrfaktor einrichten.
8. **Cronjobs** einrichten (siehe unten) und Zustellung sowie Admin-Abläufe abnehmen.

## 5. Git-Workflow

1. Lokal entwickeln, **committen**, nach GitHub pushen (`main`).
2. cPanel → **Git Version Control** → **Update from Remote**.
3. cPanel → **Deploy HEAD Commit**. Die `.cpanel.yml` ruft den Helfer auf.
4. Ergebnis prüfen: Ausgabe des Tasks, `doctor`, Live-Seite.

Wichtig:

- Das Deployment verweigert einen **dirty worktree** (`git diff`). Alles, was live werden soll, muss
  committed sein – uncommittete Notfallpatches am Release sind nicht reproduzierbar und gehen beim
  nächsten Deployment verloren.
- Deployt wird immer *HEAD des Checkouts*, identifiziert über `shared/last-deployment.json`.
  Ein bestimmter Stand ist damit reproduzierbar, sofern der Commit im Checkout existiert.
- Nicht versioniert: `.env`, `storage/`, `vendor/`, `.local/` (siehe `.gitignore`). Der `APP_KEY`
  wird mit Daten und Konfiguration gesichert, nie in Git.
- **Rollback:** `current` auf ein älteres Release zeigen lassen. Nach einer Datenbankmigration nur
  zusammen mit der passenden Datensicherung – es gibt keinen automatischen Code-Rollback.

## 6. Betrieb und Cron

Nur über den Wrapper, damit die gemeinsame Sperre greift:

```text
*/5 * * * * /usr/local/bin/php $BASE/current/scripts/cpanel-console.php --base=$BASE mail:work --limit=3 --if-enabled
1-59/5 * * * * /usr/local/bin/php $BASE/current/scripts/cpanel-console.php --base=$BASE newsletter:work --limit=1 --if-enabled
```

Den absoluten PHP-Pfad verwenden, dessen Erweiterungen geprüft sind. `--if-enabled` beendet bei
`APP_MAIL_ENABLED=false` ohne Fehler. Keine parallelen direkten `bin/console`-Läufe starten.

## 7. Fehlerdiagnose

| Symptom | Ursache / Prüfung |
| --- | --- |
| **503** statt Seite | Wartungsflag oder Sperre – normal während Deployment; bleibt bei Fehlern aktiv |
| **503** „Website ist noch nicht freigeschaltet“ | `current` fehlt/zeigt außerhalb `releases/` |
| **503** „E-Mail-Versand wird eingerichtet“ | `APP_MAIL_ENABLED=false`, betrifft `/einrichten`, `/passwort-vergessen`, `/email-bestaetigung` |
| **500** | App läuft, Exception. JSON-Zeile in `$BASE/shared/storage/logs/application.log` (nur `time`, `reference`, `error_type`) |
| Beide PHP-Versionen unterschiedlich | CLI und Web-PHP werden getrennt geprüft; `doctor` und die Web-Sonde vergleichen |
| `--base` wirkt nicht | Lange `getopt`-Optionen brauchen `--base=/pfad` |
| `invalid option name` in der Shell | CRLF-Zeilenenden in `.sh` |
| `SQLSTATE[42702] column reference … is ambiguous` | PostgreSQL: in `ON CONFLICT … DO UPDATE` Spalten qualifizieren (`excluded.spalte` oder `tabellenname.spalte`) |
| Deployment bricht mit „andere Website“ ab | `replace_existing_site=true` nur als bewusste Übernahme, vorher sichern |
| Deployment bricht mit „private Projektdateien“ ab | Im Webroot liegen `.env`, `app/`, `storage/` u. a. – herausnehmen |
| Kein SSH von außen erreichbar | cPanels „Terminal“ ist ein Web-Terminal; nur dort arbeiten |

## 8. Checkliste vor der Freigabe

- [ ] PHP-Patchstand und alle Erweiterungen in CLI **und** Web geprüft (`doctor`, Web-Sonde)
- [ ] `shared/` außerhalb des Webroots, `chmod 600` auf `.env`, kein `CHANGE-ME` mehr
- [ ] HTTPS-`APP_URL` zeigt auf den geprüften Webroot
- [ ] Migrationen angewandt, `doctor` ohne `[FEHLER]`
- [ ] SMTP-Anmeldung und echte Zustellung geprüft (SPF/DKIM)
- [ ] Admin-Konto angelegt und MFA eingerichtet, Wiederherstellungscodes gesichert
- [ ] Cronjobs aktiv, `--if-enabled` gesetzt
- [ ] Datenbank-, Konfigurations- und `APP_KEY`-Sicherung außerhalb des Servers
