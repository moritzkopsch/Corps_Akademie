# Namecheap/cPanel: Servereinrichtung

Die eingecheckte `.cpanel.yml` ruft den Deploymenthelfer für `/home/pushgobt/repositories/Corps_Akademie` auf. Der private Installationsordner ist `/home/pushgobt/corps-akademie.r4av.com`. Die Website wird erst beim Deployment aktiviert, nicht schon beim Abrufen eines Git-Commits.

## Erster Start

1. In cPanel **Update from Remote** anklicken.
2. Im SSH-Terminal des Servers:

```bash
cd /home/pushgobt/repositories/Corps_Akademie
bash scripts/deploy-cpanel.sh --init --base /home/pushgobt/corps-akademie.r4av.com
```

3. Die privaten Dateien bearbeiten:

- `/home/pushgobt/corps-akademie.r4av.com/shared/.env`: echte HTTPS-Domain, SMTP-Zugang und Absender eintragen. Den erzeugten APP_KEY aufbewahren; keine Zugangsdaten nach Git übernehmen.
- `/home/pushgobt/corps-akademie.r4av.com/shared/deploy.json`: tatsächlichen Webroot und den lokalen Composer-Pfad prüfen. Voreingestellt sind `$HOME/public_html` und `$HOME/bin/composer`.

4. In cPanel **Deploy HEAD Commit** anklicken.

Der Helfer sucht ein freigegebenes CLI-PHP. Bei Bedarf einen bestätigten PHP-Pfad über `CORPS_DEPLOY_PHP` vorgeben. PHP 8.2 ist für diesen Projektstand nicht ausreichend. Erforderlich sind PHP 8.4 ab 8.4.27 oder PHP 8.5 ab 8.5.12, PDO-SQLite ab 3.51.3 (3.52.0 ausgeschlossen), curl, intl, mbstring, sodium, xmlwriter, phar und Argon2id. CLI und Web-PHP werden getrennt geprüft.

## Verhalten

- `--init` legt neue private Vorlagen an und überschreibt keine vorhandenen Schlüssel oder Konfigurationen. Vorhandene Checkout-Konfiguration/Daten verhindern eine versehentliche Neuinitialisierung.
- `--check` prüft die Voraussetzungen ohne Veröffentlichung. Ohne Composer-Abhängigkeiten im Checkout erfolgt die vollständige Konfigurationsprüfung erst im gebauten Release.
- Releases entstehen unter `releases/`; Composer installiert dort die Pakete aus composer.lock ohne Entwicklungsabhängigkeiten und ohne Aushebeln der Plattformanforderungen.
- Die Datenbank und Konfiguration bleiben unter `shared/`. Es wird kein MySQL benötigt.
- Eine kurzlebige HTTPS-Probe prüft die tatsächliche Web-PHP-/SQLite-Version und TLS-Erkennung. Sie enthält keine Anwendungsschlüssel oder phpinfo-Ausgabe, folgt keinen Weiterleitungen und wird danach entfernt.
- Nur freigegebene statische Assets und ein kleiner Einstiegspunkt werden öffentlich. `.htaccess` erhält einen eigenen markierten Regelblock; cPanel-PHP-Handler bleiben erhalten. Fremde Routingregeln werden nicht automatisch entfernt.
- Fremde Websites, private Altdateien und weitere PHP-Endpunkte im Webroot führen zunächst zum Abbruch. Eine vorhandene Website vollständig sichern und aus dem Zielordner herausnehmen oder einen leeren Vorschau-Webroot verwenden. `replace_existing_site=true` ist eine bewusste Einmalfreigabe für den verbleibenden Einstiegspunkt, keine automatische Bereinigung alter Website-Dateien.
- Vor der Datenbankmigration warten Deployment und HTTP-/Cron-Wrapper über eine gemeinsame Dateisperre aufeinander. Bei Fehlern nach Aktivierung des Wartungsmodus bleibt dieser bestehen. Es erfolgt kein automatischer Code-Rollback gegen eine möglicherweise bereits aktualisierte Datenbank.
- Die Sicherung des öffentlichen Bereichs umfasst die ersetzten Einstiegsdateien und Assets, kein vollständiges Backup einer fremden Altinstallation. Vor neuen DB-Migrationen erstellt der vorhandene Migrator eine konsistente SQLite-Sicherung. Externe Sicherung und Wiederherstellungsabnahme bleiben erforderlich.

## Administration und Mailworker

Nach erfolgreicher Bereitstellung mit dem bestätigten PHP-Interpreter den stabilen Wrapper verwenden:

```bash
/ABSOLUTER/PFAD/ZUM/php /home/pushgobt/corps-akademie.r4av.com/current/scripts/cpanel-console.php --base=/home/pushgobt/corps-akademie.r4av.com setup:link
```

Für Konto-/Anfrage-/Newsletter-Bestätigungsmails einen Cronjob mit demselben Wrapper und `mail:work --limit=4` einrichten. Auf Shared Hosting mindestens fünf Minuten Abstand einhalten und die zum Tarif gehörige Versandquote berücksichtigen; kleinere Stapel gegebenenfalls weiter begrenzen. Keine parallelen direkten `bin/console mail:work`-Läufe verwenden, da diese die Deployment-Sperre umgehen würden. Der lokale `mail:watch` ist nicht für den Server vorgesehen.

Seminarinhalte und Benutzer stehen in der Datenbank und werden nicht durch Git übertragen. Die produktive Datenübernahme ist ein eigener Schritt. Für die erste Präsentation ohne Mail ist zusätzlich `content:seed --presentation` ausdrücklich erlaubt; bestehende Inhalte bleiben erhalten. Laufende Requests müssen über den neuen Einstiegspunkt und CLI-Jobs über den Wrapper laufen, damit die gemeinsame Sperre greift.

Lokale Deploymenttests verwenden echte Git-Archive und SQLite-Migrationen, ersetzen aber Composer-Download und HTTPS-Transport. Diese lokalen Tests ersetzen keine Serverabnahme. Am 26.10.2026 wurde die Anwendung zusätzlich über das authentifizierte cPanel-Terminal tatsächlich auf Namecheap bereitgestellt: Composer-Installation, HTTPS-/Web-PHP-Prüfung, Migrationen und Datenbankintegritätsprüfung waren erfolgreich. Startseite, Referenten und der deaktivierte Anfragehinweis wurden im Live-Browser geprüft. Private Projektpfade lieferten HTTP 404. Der SSH-Zugang wurde dabei nicht verändert.

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
- Den Mailworker als cPanel-Cronjob einrichten und echte Zustellung, Bestätigungslinks und Abmeldung prüfen. Der Mailworker verarbeitet Bestätigungsmails; dies ersetzt keine vollständige Newsletter-Kampagnenverwaltung.
- Mit dem Wrapper `setup:link` ausführen, den ersten Administrator über den erzeugten Link einrichten und die erforderliche Mehrfaktor-Anmeldung abschließen.
- Anfrage- und Newsletter-Abläufe sowie Admin-Bearbeitung und Seminar-Sichtbarkeit auf dem Server abnehmen.
- Seminarangaben, Referentenprofile und Bildfreigaben sowie Impressum und Datenschutz verbindlich freigeben. Die Website ist derzeit eine Präsentation und noch keine vollständig abgenommene Kundeninstallation.
- Externe Sicherung und Wiederherstellung von Datenbank, privater Konfiguration und APP_KEY einrichten und prüfen.

Beispiel für den Mailworker auf diesem Server (erst nach der Mailkonfiguration):

```bash
php /home/pushgobt/corps-akademie.r4av.com/current/scripts/cpanel-console.php --base=/home/pushgobt/corps-akademie.r4av.com mail:work --limit=4
```

Der verwendete CLI-PHP-Interpreter muss die oben genannten Anforderungen erfüllen. Beim ersten Deployment funktionierte das Standardkommando `php` mit PHP 8.5.12 und den aktivierten Erweiterungen. Bei Cronjobs den bestätigten absoluten Interpreterpfad verwenden und dessen Erweiterungen ebenfalls prüfen.
