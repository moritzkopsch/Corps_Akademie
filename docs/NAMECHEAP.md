# Namecheap/cPanel: Servereinrichtung

Die eingecheckte `.cpanel.yml` ruft den Deploymenthelfer für `/home/pushgobt/repositories/Corps_Akademie` auf. Der private Installationsordner ist `/home/pushgobt/corps-akademie.r4av.com`. Die Website wird erst beim Deployment aktiviert, nicht schon beim Abrufen eines Git-Commits.

## Erster Start

1. In cPanel **Update from Remote** anklicken.
2. Im SSH-Terminal des Servers:

```bash
cd /home/pushgobt/repositories/Corps_Akademie
bash scripts/deploy-cpanel.sh --init --base /home/pushgobt/corps-akademie.r4av.com
```

3. Zuvor in cPanel unter **PostgreSQL-Datenbanken** die Datenbank und den Datenbankbenutzer anlegen und den Benutzer der Datenbank zuweisen. Die Tabellen entstehen später durch die Migrationen; manuelle SQL-Schritte entfallen.

4. Die privaten Dateien bearbeiten:

- `/home/pushgobt/corps-akademie.r4av.com/shared/.env`: echte HTTPS-Domain, PostgreSQL-Zugang (`APP_DB_HOST`, `APP_DB_PORT`, `APP_DB_NAME`, `APP_DB_USER`, `APP_DB_PASSWORD`), SMTP-Zugang und Absender eintragen. Den erzeugten APP_KEY aufbewahren; keine Zugangsdaten nach Git übernehmen.
- `/home/pushgobt/corps-akademie.r4av.com/shared/deploy.json`: tatsächlichen Webroot und den lokalen Composer-Pfad prüfen. Voreingestellt sind `$HOME/public_html` und `$HOME/bin/composer`. Der Webroot ist `/home/pushgobt/www-corpsakademie` und muss vom privaten Basisordner und vom Git-Checkout getrennt bleiben.

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
/usr/local/bin/php /home/pushgobt/corps-akademie.r4av.com/current/scripts/cpanel-console.php --base=/home/pushgobt/corps-akademie.r4av.com setup:link
```

Für Konto-/Anfrage-/Newsletter-Bestätigungsmails einen Cronjob mit demselben Wrapper und `mail:work --limit=4` einrichten. Auf Shared Hosting mindestens fünf Minuten Abstand einhalten und die zum Tarif gehörige Versandquote berücksichtigen; kleinere Stapel gegebenenfalls weiter begrenzen. Keine parallelen direkten `bin/console mail:work`-Läufe verwenden, da diese die Deployment-Sperre umgehen würden. Der lokale `mail:watch` ist nicht für den Server vorgesehen.

Seminarinhalte und Benutzer stehen in der Datenbank und werden nicht durch Git übertragen. Die produktive Datenübernahme ist ein eigener Schritt. Für die erste Präsentation ohne Mail ist zusätzlich `content:seed --presentation` ausdrücklich erlaubt; bestehende Inhalte bleiben erhalten. Laufende Requests müssen über den neuen Einstiegspunkt und CLI-Jobs über den Wrapper laufen, damit die gemeinsame Sperre greift.

Lokale Deploymenttests verwenden echte Git-Archive und PostgreSQL-Migrationen, ersetzen aber Composer-Download und HTTPS-Transport. Sie laufen gegen ein eigenes Testschema; Zugang über `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`, `TEST_DB_USER` und `TEST_DB_PASSWORD`; ohne diese Zugangsdaten werden sie übersprungen. Eine erfolgreiche Namecheap-Serverabnahme wird dadurch nicht behauptet. Am 26.10.2026 wurde die Anwendung zusätzlich über das authentifizierte cPanel-Terminal tatsächlich auf Namecheap bereitgestellt, damals noch mit SQLite: Composer-Installation, HTTPS-/Web-PHP-Prüfung, Migrationen und Datenbankintegritätsprüfung waren erfolgreich, Startseite und Referenten wurden im Live-Browser geprüft, private Projektpfade lieferten HTTP 404. Der SSH-Zugang wurde nicht verändert.

Offizielle Hinweise: https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/ und https://www.namecheap.com/support/knowledgebase/article.aspx/10113/2210/how-to-use-git-version-control-cpanel-plugin/


## Tatsächliche Domainzuordnung und Start ohne Mail

Der private Basisordner ist `/home/pushgobt/corps-akademie.r4av.com`. Die Domain zeigt ausschließlich auf den separaten öffentlichen Ordner `/home/pushgobt/www-corpsakademie`. In `shared/deploy.json` muss deshalb `webroot` genau `/home/pushgobt/www-corpsakademie` sein. Der private Basisordner und der Git-Checkout dürfen nicht als Document Root verwendet werden.

Für die ausdrücklich gewünschte erste Website-Freischaltung ohne Versand wird `APP_MAIL_ENABLED=false` in der privaten `shared/.env` gesetzt. `MAILER_DSN` und `MAIL_FROM` können dann leer bleiben. Anfrage- und Newsletterformulare sowie Kontoersteinrichtung sind in diesem Zustand deaktiviert; es werden keine Mails als versendet markiert. Die öffentliche Website zeigt einen Hinweis und bleibt für Suchmaschinen auf noindex.

Die bereits erarbeiteten Präsentationsinhalte können in diesem Zustand ausdrücklich mit `content:seed --presentation` ergänzt werden. Bestehende Einträge werden nicht überschrieben. Es werden keine Benutzerkonten oder Standardpasswörter erzeugt.

Nach Einrichtung eines echten SMTP-Zugangs und Absenders `APP_MAIL_ENABLED=true` setzen. Dann gelten die ursprünglichen SMTP-/TLS-Prüfungen wieder; die Versand- und Anmeldefunktionen werden freigeschaltet. Inhalte und Rechtstexte benötigen weiterhin eine redaktionelle Freigabe.

## Aktueller Stand und nächste Schritte (26.10.2026)

Die öffentliche Präsentation läuft unter https://corps-akademie.r4av.com/ mit 11 Seminaren und 3 Referentenprofilen. Mailversand, Anfrageformulare, Newsletter und Admin-Ersteinrichtung sind bis zur Mailkonfiguration deaktiviert. Es gibt kein automatisch angelegtes Admin-Konto und kein Standardpasswort.

### Künftige Code-Aktualisierungen

1. Lokal Änderungen committen und nach GitHub auf `main` pushen.
2. In cPanel unter **Git™ Version Control** das Repository `Corps_Akademie` verwalten.
3. **Update from Remote**, danach **Deploy HEAD Commit** anklicken.
4. Das Deploymentergebnis und die Live-Website prüfen. Bei Fehlern nicht mehrfach blind deployen; die konkrete Meldung auswerten.

Die private `.env`, Datenbank und Admin-Inhalte bleiben bei diesen Aktualisierungen erhalten. Änderungen an Seminarinhalten in der Datenbank werden nicht durch Git übertragen.

### Noch zu erledigen

- Ein echtes Absenderpostfach und SMTP-Zugang einrichten; Zugangsdaten ausschließlich in der privaten `shared/.env` hinterlegen.
- `MAILER_DSN`, `MAIL_FROM` und anschließend `APP_MAIL_ENABLED=true` setzen. Keine Zugangsdaten oder APP_KEY in Git speichern.
- Den Mailworker als cPanel-Cronjob einrichten und echte Zustellung, Bestätigungslinks und Abmeldung prüfen. Der Mailworker verarbeitet Bestätigungsmails; freigegebene Rundmails verarbeitet der separate Newsletter-Worker (siehe unten).
- Mit dem Wrapper `setup:link` ausführen, den ersten Administrator über den erzeugten Link einrichten und die erforderliche Mehrfaktor-Anmeldung abschließen.
- Anfrage- und Newsletter-Abläufe sowie Admin-Bearbeitung und Seminar-Sichtbarkeit auf dem Server abnehmen.
- Seminarangaben, Referentenprofile und Bildfreigaben sowie Impressum und Datenschutz verbindlich freigeben. Die Website ist derzeit eine Präsentation und noch keine vollständig abgenommene Kundeninstallation.
- Externe Sicherung und Wiederherstellung von Datenbank, privater Konfiguration und APP_KEY einrichten und prüfen.

Beispiel für den Mailworker auf diesem Server (erst nach der Mailkonfiguration):

```bash
php /home/pushgobt/corps-akademie.r4av.com/current/scripts/cpanel-console.php --base=/home/pushgobt/corps-akademie.r4av.com mail:work --limit=4
```

Der verwendete CLI-PHP-Interpreter muss die oben genannten Anforderungen erfüllen. Beim ersten Deployment funktionierte das Standardkommando `php` mit PHP 8.5.12 und den aktivierten Erweiterungen. Bei Cronjobs den bestätigten absoluten Interpreterpfad verwenden und dessen Erweiterungen ebenfalls prüfen.

## Mail und Rundmails: Einrichtung für corps-akademie.r4av.com

Die Postfächer `info@corps-akademie.r4av.com`, `system@corps-akademie.r4av.com` und `newsletter@corps-akademie.r4av.com` sind in cPanel vorhanden. Die früher genannten Adressen unter `verbindung.r4av.com` werden nicht verwendet.

- `system@` authentifiziert den Versand automatischer Bestätigungs- und Kontomails.
- `newsletter@` verwendet einen eigenen SMTP-Zugang für freigegebene Rundmails.
- Antworten und Benachrichtigungen über bestätigte Kontakt-/Seminaranfragen gehen an `info@`.

Nach Deployment interaktiv im cPanel-Terminal ausführen:

```bash
php /home/pushgobt/corps-akademie.r4av.com/current/scripts/configure-cpanel-mail.php
```

Der Betreiber gibt dort die bestehenden Postfachpasswörter für `system@` und `newsletter@` ein. Sie erscheinen nicht auf dem Bildschirm und gehören weder in den Chat noch in Shellargumente oder Git. Das Skript prüft beide SMTP-Anmeldungen über TLS auf `premium323.web-hosting.com:465`, ohne E-Mails zu versenden. Erst nach erfolgreicher Prüfung speichert es die private Konfiguration atomar und aktiviert den Mailversand. Die vorherige Konfiguration wird privat gesichert. APP_KEY und Datenbank bleiben erhalten. Bei einer Fehlermeldung werden keine SMTP-Dialoge oder Zugangsdaten ausgegeben.

SMTP-Anmeldung allein bestätigt noch keine Zustellung: Anschließend eine eigene Testadresse für Anmeldung, Bestätigungslink, Anfrage, Antwortadresse und Abmeldung verwenden und SPF/DKIM in **Email Deliverability** prüfen. Es wurden durch die Einrichtung keine Rundmails versendet.

### Newsletter-Verwaltung

Nach Admin-Anmeldung einschließlich MFA: `/admin/newsletter`.

1. Eine Rundmail als Entwurf anlegen und speichern.
2. Die gespeicherte Textvorschau kontrollieren. Abmelde- und Impressumslink werden pro Empfänger ergänzt.
3. Versand ausdrücklich freigeben. Empfänger sind ausschließlich die zu diesem Zeitpunkt bestätigten Abonnenten. Spätere Anmeldungen werden nicht nachträglich aufgenommen.
4. Der Worker prüft vor dem Versand erneut, ob die Anmeldung noch aktiv und unverändert ist. Gestoppte Kampagnen und Abmeldungen verhindern ausstehende Sendungen; bereits übertragene oder gerade übermittelte Nachrichten sind nicht rückrufbar.
5. Den Status kontrollieren. „An SMTP übergeben“ bedeutet keine garantierte Postfachzustellung. Unklare SMTP-Ausgänge werden nicht automatisch erneut versendet, um Doppelversand zu verhindern.

Einmal freigegebene Texte sind unveränderlich. Die Verwaltung ist auf Adminrollen beschränkt und verwendet die vorhandenen MFA-/CSRF-Prüfungen. Empfängeradressen werden einzeln adressiert, nicht als offener Gruppenverteiler. Newsletter werden als Textmails ohne Öffnungstracking versendet. Es gibt keinen automatischen Import fremder Adresslisten und keine automatische Rundmail ohne Freigabe.

### Cronjobs

Den auf dem Server bestätigten absoluten PHP-Pfad `/usr/local/bin/php` verwenden. Nicht den unvollständig konfigurierten Alt-PHP-Pfad hart verdrahten.

Alle fünf Minuten für Bestätigungsmails, zeitlich versetzt für Rundmails:

```text
*/5 * * * * /usr/local/bin/php /home/pushgobt/corps-akademie.r4av.com/current/scripts/cpanel-console.php --base=/home/pushgobt/corps-akademie.r4av.com mail:work --limit=3 --if-enabled
1-59/5 * * * * /usr/local/bin/php /home/pushgobt/corps-akademie.r4av.com/current/scripts/cpanel-console.php --base=/home/pushgobt/corps-akademie.r4av.com newsletter:work --limit=1 --if-enabled
```

`--if-enabled` beendet den Lauf bei abgeschaltetem Mailversand ohne Fehler. Der Newsletter-Worker begrenzt in Produktion zusätzlich datenbankgestützt auf höchstens einen Versandversuch je fünf Minuten, auch bei parallel gestarteten Workern. Zusammen mit diesen Cronjobs sind zunächst bis zu 36 Bestätigungsmails und 12 Rundmails pro Stunde vorgesehen; manuelle zusätzliche Bestätigungsworker und sonstiger Postfachversand können das Domainkontingent zusätzlich belasten.

Die tatsächliche Tarifquote muss vor einer Erhöhung geprüft werden. Namecheap nennt je nach Paket unterschiedliche Grenzen: https://www.namecheap.com/legal/hosting/aup/ . Zustellbarkeit/SPF/DKIM: https://docs.cpanel.net/cpanel/email/email-deliverability-in-cpanel/ . Große Verteiler benötigen eine gesonderte Kapazitätsplanung; die konservative Grundeinstellung ist für den Start gedacht.

### Bereitstellungsstand dieser Erweiterung

Am 26.10.2026 wurde die Newsletter-Verwaltung auf Namecheap veröffentlicht und Migration `003_newsletter_campaigns.sql` erfolgreich angewandt. Die zwei oben beschriebenen Cronjobs wurden nach ausdrücklicher Versandfreigabe eingerichtet; bestehende Crontab-Einträge wurden erhalten und zuvor privat gesichert. Die Website antwortete danach mit HTTP 200, `/admin/newsletter` leitete nicht angemeldete Besucher zur Anmeldung weiter. Die gezielten Tests für Newsletter, öffentliche Abläufe und Konfiguration bestanden (31 Tests, 273 Assertions).

Zum dokumentierten Übergabestand bleibt `APP_MAIL_ENABLED=false`: Die Postfächer bestehen, aber die SMTP-Passwörter müssen vom Betreiber noch über das interaktive Einrichtungsskript eingegeben werden. Eine echte Zustellprüfung und Admin-Ersteinrichtung stehen danach noch aus. Erst erfolgreiche SMTP-Prüfung und Aktivierung machen die Formulare und Versandläufe nutzbar.

### SMTP-Aktivierung am 26.10.2026

Der Betreiber hat die beiden bestehenden Postfachpasswörter über die geschützte Servereingabe eingetragen. TLS-Verbindung und SMTP-Anmeldung waren für `system@corps-akademie.r4av.com` und `newsletter@corps-akademie.r4av.com` erfolgreich. Das Einrichtungsskript hat die private Konfiguration aktiviert (`APP_MAIL_ENABLED=true`). Danach waren das öffentliche Newsletterformular und die Produktions-Konfigurations-/Datenbankprüfung erfolgreich.

Die DNS-Abfrage lieferte TXT-Einträge für die Absenderdomain und ihren cPanel-DKIM-Selektor. Das allein ist noch keine vollständige Prüfung externer Zustellbarkeit. Der Empfang echter Testmails und die Abnahme der Abläufe bleiben gesondert zu prüfen. Die zuvor dokumentierte ausstehende Passworteingabe ist damit erledigt. Ein Admin-Konto wird durch die SMTP-Einrichtung nicht automatisch angelegt.

### Zustellprüfung am 26.10.2026

Nach ausdrücklicher Freigabe wurden zwei technische Testmails an `info@corps-akademie.r4av.com` gesendet: eine über den System-SMTP-Zugang und eine über den Newsletter-SMTP-Zugang. Beide wurden vom SMTP-Server angenommen und anschließend anhand der eindeutigen Testkennung im Info-Postfach nachgewiesen. Es wurde dabei weder eine Newsletter-Anmeldung vorgenommen noch eine Rundmail an Abonnenten freigegeben.

Damit ist die Zustellung innerhalb derselben Hostingdomain nachgewiesen. Externe Zustellung, beispielsweise an Gmail oder GMX, sowie der vollständige Live-Ablauf mit Double-Opt-in und Abmeldung sind damit noch nicht abgenommen. Die geschützte Newsletter-Verwaltung ist unter `/admin/newsletter` erreichbar und setzt einen eingerichteten Admin-Zugang mit MFA voraus.
