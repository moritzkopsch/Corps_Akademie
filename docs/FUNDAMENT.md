# Fundament und Identitätsmodul

Implementierungsstand: 24. Oktober 2026. Dies ist das erste ausführbare Produktmodul. Es implementiert die technische Basis, sichere Kontozugänge und ihre Wiederherstellung. Die übrigen Geschäftsbereiche der Gesamtspezifikation sind weiterhin geplante Module.

## Tatsächlich implementiert

- Composer-Projekt mit festgeschriebenen Abhängigkeiten und PHP-Kompatibilitätsgrenzen.
- Validierte Umgebungskonfiguration, generierter 256-Bit-Anwendungsschlüssel und private Speicherpfade.
- Zentrale PDO-Verbindung mit Foreign Keys, WAL, Busy Timeout und synchronous=FULL.
- Transaktionale, prüfsummengesicherte Migrationen; Sicherung vor weiteren Migrationen auf bestehenden Daten.
- CLI-Installer, Diagnose, lokale konsistente Datenbanksicherung und technische Sitzungsbereinigung.
- Erstes Superadministratorkonto über einen zeitlich begrenzten Einmallink; kein Defaultkonto und kein Defaultpasswort.
- Anmeldung mit Argon2id, gleichartigen Fehlermeldungen für falsche Zugangsdaten und konsistentem Rate-Limit.
- E-Mail-Bestätigung, Passwortreset, Tokenablauf und Schutz gegen Wiederverwendung.
- Datenbankgestützte Sitzungen mit gehashten Kennungen, verschlüsselter Nutzlast, Rotation, Ablauf und Widerruf.
- Rollen und konkrete Rechte für Superadministrator, Administrator und Referent.
- Verbindliche TOTP-Anmeldung für administrative Zugänge, lokal erzeugter QR-Code und einmalige Wiederherstellungscodes.
- Geschützte Benutzerübersicht für berechtigte Administratoren und persönlicher Referentenzugang ohne Zugriff auf fremde Konten.
- Strukturierte Sicherheits- und Administratorereignisse ohne Passwörter, Tokens oder vollständige Nachrichten.
- Transaktionale Outbox für Identitätsmails, begrenzter CLI-Dispatcher, Lease-Prüfung und expliziter Zustand für unklare Versandfälle.
- Mobile Formulare, Tastaturfokus, semantische Beschriftungen, Escaping und Sicherheitsheader.

## Schichten und Zuständigkeiten

| Verzeichnis | Aufgabe |
|---|---|
| app/Domain/Identity | Passwort-, Berechtigungs-, Token-, Rate-Limit- und MFA-Regeln |
| app/Application | Fachliche Abläufe für Ersteinrichtung, Verifikation und Passwortwiederherstellung |
| app/Infrastructure | Konfiguration, SQLite-Verbindung, IdentityRepository, Migrationen, Verschlüsselung, Audit und SMTP-Outbox |
| app/Http | HTTP-Abläufe, Sitzungs-Cookies, CSRF und Weiterleitungen |
| resources/views | Twig-Templates mit automatischem HTML-Escaping |
| public | Einziger Webroot: Frontcontroller und öffentliche Assets |
| database/migrations | Geordnete, prüfsummengesicherte SQL-Migrationen |
| bin/console | Lokale/serverseitige Betriebsbefehle; nicht öffentlich erreichbar |
| tests | Isolierte Integrationstests einschließlich echter konkurrierender PHP-Prozesse |

SQL der Identitätsdaten liegt im IdentityRepository. Fachliche Regeln und HTTP-Controller rufen dessen benannte Methoden auf. Zusammenhängende Änderungen nutzen dieselbe kurze SQLite-Schreibtransaktion. Mailversand findet außerhalb der Schreibtransaktion statt.

## Installation und Ersteinrichtung

1. Produktions- beziehungsweise Entwicklungsabhängigkeiten aus composer.lock installieren.
2. Konfigurationsvorlage prüfen. Lokal kann `php bin/console install` die `.env` sicher erzeugen. Eine bereits vorhandene Konfiguration wird nicht überschrieben.
3. Migrationen und Diagnose ausführen.
4. Webserver ausschließlich auf `public/` richten.
5. Für die lokale Entwicklung Mailpit, Anwendung und lokalen Mailworker starten, siehe README.
6. Einmallink mit `php bin/console setup:link` erzeugen.
7. Ersten Benutzer im eigenen Browser einrichten, E-Mail bestätigen und MFA aktivieren.

Der Bootstrap-Link ist eine Zugangsbefugnis zur Ersteinrichtung. Er gehört nicht in Issues, Screenshots, öffentliche Logs oder Git. Er wird nur als Hash in der Datenbank gespeichert. Das Token im URL-Fragment wird nicht an den Webserver übertragen. JavaScript übernimmt es in das Formular und entfernt das Fragment aus der aktuellen Browseradresse. Erst der ausdrücklich ausgelöste POST verbraucht den Token. Ein erneuter Aufruf des CLI-Befehls macht frühere Bootstrap-Links ungültig.

Für E-Mail-Bestätigung und Passwortreset gilt dasselbe Fragment-/POST-Verfahren. Linkscanner-GETs verändern weder Konto noch Passwort. Die Verifikation eines Kontos bewirkt keine Newslettereinwilligung und aktiviert keine Seminaranfrage.

Das erste Konto und CLI-Konten beginnen mit unbestätigter Adresse. Der Mailworker verschickt die Bestätigung. Ohne bestätigte Adresse gibt es keinen Portalzugriff. Administrative Konten müssen zusätzlich einen zweiten Faktor einrichten beziehungsweise bei der Anmeldung bestätigen.

## Konfiguration

| Variable | Bedeutung |
|---|---|
| APP_ENV | local, test, staging oder production |
| APP_URL | Feste Basis-URL ohne Unterverzeichnis; Produktion benötigt HTTPS |
| APP_KEY | Base64-kodierter 256-Bit-Schlüssel für verschlüsselte Nutzlasten und HMACs |
| APP_KEY_ID | Kennung des zugehörigen Schlüssels, aktuell v1 |
| APP_DB_PATH | SQLite-Datei außerhalb des Webroots; relativ zum Projekt oder absolut |
| APP_STORAGE_PATH | Private Logs und lokale Sicherungen außerhalb des Webroots |
| SESSION_IDLE_SECONDS | Serverseitige Inaktivitätsfrist, lokal voreingestellt 1800 Sekunden |
| SESSION_ABSOLUTE_SECONDS | Maximale Sitzungsdauer, lokal voreingestellt 43200 Sekunden |
| MAILER_DSN | Lokal smtp://127.0.0.1:1025; Produktion nur SMTP mit erzwungenem TLS |
| MAIL_FROM | Freigegebener Absender; lokal system@example.test |

SMTP-TLS darf in der Produktion nicht durch verify_peer=false oder deaktiviertes Auto-TLS ausgehebelt werden. APP_URL wird nicht aus einem ankommenden Host-Header erzeugt. Fremde Hosts werden abgewiesen; Vertrauenskonfiguration für vorgeschaltete Proxys ist derzeit nicht implementiert. Die HTTPS-Prüfung erwartet eine direkt korrekt erkannte TLS-Verbindung. Eine abweichende Kundeninstallation benötigt vor Freigabe eine passende, ausdrücklich begrenzte Proxykonfiguration.

APP_KEY nicht ohne geplantes Verfahren ersetzen: Verschlüsselte MFA-Geheimnisse und Sitzungsdaten benötigen den ursprünglichen Schlüssel. Der aktuelle Stand kann einen aktiven Schlüssel lesen; eine Rotation mit mehreren gleichzeitig gültigen Schlüsseln folgt vor einem entsprechenden Betriebsbedarf. Schlüssel und notwendige Konfiguration werden gesondert geschützt gesichert, niemals öffentlich bereitgestellt.

## Sitzungen und MFA

Das Cookie ist HttpOnly, SameSite=Lax und in HTTPS-Umgebungen Secure. Sitzungskennungen werden bei Kennwortanmeldung und bei MFA-Abschluss erneuert. Ein Passwortreset oder eine Kontosperre widerruft bestehende Sitzungen. Vor einer Rechteerhöhung muss die zugrunde liegende Sitzung noch aktiv sein; eine zwischenzeitlich widerrufene Sitzung lässt sich nicht nachträglich hochstufen.

Rechte werden je Anfrage aus dem aktuellen Datenbestand geprüft. Auch eine zusätzliche oder eigene Rolle mit administrativer Berechtigung erfordert MFA. Ein fremder Benutzerbezeichner in URL-Parametern kann den eigenen Portalbezug nicht verändern.

TOTP hat sechs Ziffern und eine Periode von 30 Sekunden; die Prüfung toleriert ein Zeitfenster davor und danach. Bereits verwendete Zeitschritte werden atomar abgewiesen. Wiederherstellungscodes enthalten jeweils 128 Zufallsbits, liegen nur gehasht in der Datenbank und werden atomar einmal verbraucht. Bei der Einrichtung erscheint die Klartextliste einmalig im eigenen geschützten Bereich.

Das Modul bietet noch keine administrative Zurücksetzung oder Deaktivierung von MFA im Browser. Für einen verlorenen zweiten Faktor stehen die persönlichen Wiederherstellungscodes zur Verfügung. Ein weitergehender Betreiber-Wiederherstellungsprozess muss vor echtem Produktivbetrieb definiert und implementiert werden; es gibt keinen ungeschützten MFA-Bypass.

## Versand und Betrieb

Identitätsmails werden innerhalb der fachlichen Transaktion als eindeutiger Auftrag gespeichert. Das darin erforderliche Linkgeheimnis ist verschlüsselt; nach Übergabe, Stornierung oder Sperre wird diese Nutzlast entfernt. Der Dispatcher prüft vor Übergabe Tokenstatus, Konto, Empfänger und aktuelle Lease erneut.

Status sind queued, sending, submitted, cancelled, blocked und ambiguous. Submitted bedeutet vom SMTP-Dienst angenommen, nicht nachgewiesen im Zielpostfach zugestellt. Bei unklarer Übertragung oder abgelaufener Versand-Lease erfolgt kein blinder Neuversand. Der Zustand bleibt nachvollziehbar. Ein Anwender kann einen neuen, gesondert begrenzten Link anfordern; dadurch wird der alte Token ungültig.

In Nichtproduktion sind als Versandweg ausschließlich localhost beziehungsweise 127.0.0.1 auf SMTP-Port 1025 erlaubt. Identitätsmails an andere Domains als @example.test werden bereits beim Einplanen gesperrt. Staging nutzt derzeit ebenfalls diesen Mail-Sink; produktnahe Zustelltests mit expliziter Empfängerfreigabe werden als eigener Konfigurationsschritt vorbereitet.

`mail:watch` ist ein lokaler Entwicklungsworker. Auf einer Kundeninstallation läuft später `mail:work` über einen zulässigen Cron- oder beaufsichtigten Prozess. Der vollständige allgemeine Job-Worker für Buchungen, Erinnerungen und Newsletter gehört zum nachfolgenden Automatisierungsmodul. Auch Bounce-Verarbeitung und produktive Versandquoten sind noch nicht Bestandteil dieses Fundamentmoduls.

## Sicherung und Diagnose

`php bin/console db:backup` erstellt mittels VACUUM INTO eine konsistente Datenbanksicherung, prüft Integrität und Fremdschlüssel und vermerkt den Stand. Die Datei ist lokal, außerhalb des Webroots und mit restriktiven Rechten gespeichert. Diese lokale Sicherung ist nicht vollständig dateiverschlüsselt und kein bereits fertiggestelltes externes Produktions-Backupkonzept. Verschlüsselte externe Sicherung, Medien-/Konfigurationsmanifest und vollständige Restore-Abnahme folgen im Betriebsmodul.

`doctor` prüft PHP-Patchstand, Integrität, Foreign Keys und Migrationsprüfsummen. `session:prune` bereinigt abgelaufene/widerrufene Sitzungen und alte technische Rate-Limit-Buckets mit 24 Stunden Nachlauf. Sonstige Aufbewahrungs- und Löschfristen werden nicht als bereits fachlich freigegeben behauptet. Sicherheitsereignisse tragen derzeit 90 Tage als vorgesehenen Nachhaltezeitpunkt; eine produktive Löschroutine und ihre Freigabe folgen vor dem Pilotbetrieb.

## Prüfungen und Abnahmegrenze

Lokaler Nachweis dieses Stands: 39 automatisierte Tests mit 175 Assertions erfolgreich; Composer-Manifeste gültig, Abhängigkeitsprüfung ohne bekannte Sicherheitsmeldungen und ohne aufgegebene Pakete. Die Live-Prüfung von HTTP-Zugriffsgrenzen und echtem SMTP-Versand bis Mailpit war ebenfalls erfolgreich. Das ist keine unabhängige Sicherheitszertifizierung.

Die automatisierte Suite umfasst frische und wiederholte Migration, Prüfsummenänderung, Backup, private Pfade, Verschlüsselungskontext, Loginfehler, SQL-/HTML-Eingaben, CSRF, fremde Rollen/Datensätze, E-Mail-Bestätigung, Sitzungswechsel und -ablauf, Secure-Cookies, Host-Header, MFA, Wiederherstellungscodes, Passwortreset und Outbox-Fehlerpfade. Echte getrennte PHP-Prozesse prüfen, dass derselbe Einmallink und derselbe TOTP-Schritt jeweils nur einmal gültig abgeschlossen werden können.

Zusätzlich prüft `scripts/check-live.php` bei laufender lokaler Umgebung den echten HTTP-Zugriffsschutz und die Kette aus fachlicher Outbox, Symfony Mailer, SMTP und Mailpit. Die Testdatenbank ist temporär und wird entfernt. Eine dabei im Mailpit sichtbare Testnachricht betrifft diese wegwerfbare Testdatenbank und keinen echten Benutzer der lokalen Anwendung.

Der bisherige Nachweis gilt für die geprüfte Mac-Umgebung. Linux-Testserver, echtes Kundenhosting, Produktiv-Maildienst, Lastziele, Geschäftsregeln und die übrigen Fachmodule bleiben offen. Vor der nächsten produktiven Stufe wird die Neuinstallation unter Linux wiederholt. Dieses Modul ist keine behauptete Fertigstellung der Gesamtplattform.
