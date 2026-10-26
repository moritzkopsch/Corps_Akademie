# Namecheap/cPanel: Servereinrichtung

Die eingecheckte `.cpanel.yml` ruft den Deploymenthelfer für `/home/pushgobt/repositories/Corps_Akademie` auf. Der private Installationsordner ist `/home/pushgobt/apps/corpsakademie`. Die Website wird erst beim Deployment aktiviert, nicht schon beim Abrufen eines Git-Commits.

## Erster Start

1. In cPanel **Update from Remote** anklicken.
2. Im SSH-Terminal des Servers:

```bash
cd /home/pushgobt/repositories/Corps_Akademie
bash scripts/deploy-cpanel.sh --init --base /home/pushgobt/apps/corpsakademie
```

3. Zuvor in cPanel unter **PostgreSQL-Datenbanken** die Datenbank und den Datenbankbenutzer anlegen und den Benutzer der Datenbank zuweisen. Die Tabellen entstehen später durch die Migrationen; manuelle SQL-Schritte entfallen.

4. Die privaten Dateien bearbeiten:

- `/home/pushgobt/apps/corpsakademie/shared/.env`: echte HTTPS-Domain, PostgreSQL-Zugang (`APP_DB_HOST`, `APP_DB_PORT`, `APP_DB_NAME`, `APP_DB_USER`, `APP_DB_PASSWORD`), SMTP-Zugang und Absender eintragen. Den erzeugten APP_KEY aufbewahren; keine Zugangsdaten nach Git übernehmen.
- `/home/pushgobt/apps/corpsakademie/shared/deploy.json`: tatsächlichen Webroot und den lokalen Composer-Pfad prüfen. Voreingestellt sind `$HOME/public_html` und `$HOME/bin/composer`. Für die Subdomain `corps-akademie.r4av.com` ist der Webroot üblicherweise `/home/pushgobt/corps-akademie.r4av.com` und nicht `$HOME/public_html`; er muss vom privaten Basisordner getrennt bleiben.

5. In cPanel **Deploy HEAD Commit** anklicken.

Der Helfer sucht ein freigegebenes CLI-PHP. Bei Bedarf einen bestätigten PHP-Pfad über `CORPS_DEPLOY_PHP` vorgeben. Erforderlich sind PHP 8.2 ab 8.2.35, PHP 8.3 ab 8.3.36, PHP 8.4 ab 8.4.27 oder PHP 8.5 ab 8.5.12 (jeweils mit den Korrekturen für CVE-2026-109618/CVE-2026-109619), die Erweiterungen `pdo_pgsql` und `pgsql`, curl, intl, mbstring, sodium, xmlwriter, phar und Argon2id. Der PostgreSQL-Server muss mindestens Version 10 bieten (Identity-Spalten, `FOR UPDATE SKIP LOCKED`); die Anwendung weist ältere Server beim Verbindungsaufbau ab. CLI und Web-PHP werden getrennt geprüft.

## Verhalten

- `--init` legt neue private Vorlagen an und überschreibt keine vorhandenen Schlüssel oder Konfigurationen. Vorhandene Checkout-Konfiguration/Daten verhindern eine versehentliche Neuinitialisierung.
- `--check` prüft die Voraussetzungen ohne Veröffentlichung. Ohne Composer-Abhängigkeiten im Checkout erfolgt die vollständige Konfigurationsprüfung erst im gebauten Release.
- Releases entstehen unter `releases/`; Composer installiert dort die Pakete aus composer.lock ohne Entwicklungsabhängigkeiten und ohne Aushebeln der Plattformanforderungen.
- Die Datenbank läuft auf dem PostgreSQL-Server des Hostingpakets und wird ausschließlich über `APP_DB_*` aus `shared/.env` angesprochen. Konfiguration, Protokolle und Sicherungen bleiben unter `shared/`. Es wird kein MySQL benötigt.
- Eine kurzlebige HTTPS-Probe prüft die tatsächliche Web-PHP-Version, das Vorhandensein des `pgsql`-Treibers und die TLS-Erkennung. Sie enthält keine Anwendungsschlüssel, keine Datenbankzugangsdaten und keine phpinfo-Ausgabe, folgt keinen Weiterleitungen und wird danach entfernt.
- Nur freigegebene statische Assets und ein kleiner Einstiegspunkt werden öffentlich. `.htaccess` erhält einen eigenen markierten Regelblock; cPanel-PHP-Handler bleiben erhalten. Fremde Routingregeln werden nicht automatisch entfernt.
- Fremde Websites, private Altdateien und weitere PHP-Endpunkte im Webroot führen zunächst zum Abbruch. Eine vorhandene Website vollständig sichern und aus dem Zielordner herausnehmen oder einen leeren Vorschau-Webroot verwenden. `replace_existing_site=true` ist eine bewusste Einmalfreigabe für den verbleibenden Einstiegspunkt, keine automatische Bereinigung alter Website-Dateien.
- Vor der Datenbankmigration warten Deployment und HTTP-/Cron-Wrapper über eine gemeinsame Dateisperre aufeinander. Bei Fehlern nach Aktivierung des Wartungsmodus bleibt dieser bestehen. Es erfolgt kein automatischer Code-Rollback gegen eine möglicherweise bereits aktualisierte Datenbank.
- Die Sicherung des öffentlichen Bereichs umfasst die ersetzten Einstiegsdateien und Assets, kein vollständiges Backup einer fremden Altinstallation. Vor neuen DB-Migrationen erstellt der Migrator eine konsistente Sicherung mit `pg_dump` (Klartext, Marker „PostgreSQL database dump complete“, Dateirechte 600) unter `shared/storage/backups/`. Für die Wiederherstellung die Sicherung mit `psql` in eine leere Zieldatenbank einspielen. Externe Sicherung und Wiederherstellungsabnahme bleiben erforderlich.

## Administration und Mailworker

Nach erfolgreicher Bereitstellung mit dem bestätigten PHP-Interpreter den stabilen Wrapper verwenden:

```bash
/ABSOLUTER/PFAD/ZUM/php /home/pushgobt/apps/corpsakademie/current/scripts/cpanel-console.php --base=/home/pushgobt/apps/corpsakademie setup:link
```

Für Konto-/Anfrage-/Newsletter-Bestätigungsmails einen Cronjob mit demselben Wrapper und `mail:work --limit=4` einrichten. Auf Shared Hosting mindestens fünf Minuten Abstand einhalten und die zum Tarif gehörige Versandquote berücksichtigen; kleinere Stapel gegebenenfalls weiter begrenzen. Keine parallelen direkten `bin/console mail:work`-Läufe verwenden, da diese die Deployment-Sperre umgehen würden. Der lokale `mail:watch` ist nicht für den Server vorgesehen.

Seminarinhalte und Benutzer stehen in der Datenbank und werden nicht durch Git übertragen. Die produktive Datenübernahme ist ein eigener Schritt; `content:seed` ist absichtlich nur lokal/in Tests erlaubt. Laufende Requests müssen über den neuen Einstiegspunkt und CLI-Jobs über den Wrapper laufen, damit die gemeinsame Sperre greift.

Lokale Deploymenttests verwenden echte Git-Archive und PostgreSQL-Migrationen, ersetzen aber Composer-Download und HTTPS-Transport. Sie laufen gegen ein eigenes Testschema; Zugang über `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`, `TEST_DB_USER` und `TEST_DB_PASSWORD`. Eine erfolgreiche Namecheap-Serverabnahme wird dadurch nicht behauptet. Der SSH-Zugang wurde nicht verändert; beim rein lesenden Verbindungsversuch fehlte ein verifizierter Server-Hostschlüssel, daher fand kein Serverzugriff statt.

Offizielle Hinweise: https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/ und https://www.namecheap.com/support/knowledgebase/article.aspx/10113/2210/how-to-use-git-version-control-cpanel-plugin/
