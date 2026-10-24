# Lokale Entwicklungsumgebung

Eingerichtet am 24. Oktober 2026 auf dem vorhandenen Mac. Dieser Schritt richtet Entwicklungswerkzeuge und ein lokales SMTP-Postfach ein. Die Seminarplattform, ihr Installer und die fachlichen Module werden anschließend implementiert.

**Aktueller Nachtrag:** Das Anwendungsfundament mit Installer, Datenbankmigration, Anmeldung, E-Mail-Bestätigung, Rollenrechten, MFA und Passwortreset ist inzwischen implementiert. Startbefehle für Anwendung und Mailworker stehen in [README](../README.md), Details in [Fundament](FUNDAMENT.md). Die folgenden Abschnitte beschreiben die weiterhin verwendeten lokalen Werkzeuge.

## Installierter Stand

| Werkzeug | Geprüfte Version |
|---|---|
| PHP | 8.5.12 |
| SQLite über PHP | 3.54.1 |
| Composer | 2.10.3 |
| Mailpit | 1.32.0 |

PHP wurde mit Homebrew aktualisiert; Composer und Mailpit wurden mit Homebrew installiert. Es wurden keine globalen PHP- oder Mailpit-Autostartdienste aktiviert. Die vorhandene PHP-Konfiguration wurde vor dem Update in `.local/backups/` gesichert. Homebrew hat die PHP-Einstellungen `openssl.cafile` und `openssl.capath` aktualisiert. Die benötigten Erweiterungen und die HTTPS-Verbindung zu den Paketquellen werden nach dem Update geprüft.

Alle folgenden Befehle laufen im Projektordner. Pfade enthalten keine fest eingebauten Benutzernamen. Python 3 ist nur für den lokalen Mailpit-Starter nötig; daraus entsteht keine Python-Anforderung für das Kundenhosting.

## Entwicklungsumgebung prüfen

```bash
php scripts/check-local.php
bash scripts/composer diagnose
```

Die PHP-Prüfung kontrolliert Version, Erweiterungen, Argon2id, SQLite-Version, WAL, Fremdschlüssel, eine Schreibsperre zwischen zwei Prozessen, Rollback und konsistentes Backup. Die Testdatenbank wird im temporären Verzeichnis angelegt und wieder entfernt. Ein Fehler liefert einen von null verschiedenen Exitcode. Dies ist ein Umgebungstest, noch kein Test der späteren Buchungslogik.

Der Composer-Wrapper legt Konfiguration und Cache im ignorierten Ordner `.local/composer/` ab. Dort sind die öffentlichen Release- und Snapshot-Prüfschlüssel der offiziellen Composer-Seite hinterlegt. Für weitere Projekte bleibt die globale Composer-Konfiguration unabhängig. [Offizielle Prüfschlüssel](https://composer.github.io/pubkeys.html)

Wenn dieser lokale Ordner auf einem neuen Rechner noch nicht besteht, können die öffentlichen Schlüssel vor der ersten Diagnose so eingerichtet werden:

```bash
mkdir -p .local/composer
curl --fail --silent --show-error --proto '=https' --tlsv1.2 \
  https://composer.github.io/releases.pub --output .local/composer/keys.tags.pub
curl --fail --silent --show-error --proto '=https' --tlsv1.2 \
  https://composer.github.io/snapshots.pub --output .local/composer/keys.dev.pub
```

Die eingesetzten Werkzeuge müssen auf einem neuen Mac vorher installiert sein. Der hier dokumentierte Ausgangsstand ist PHP 8.5.12; spätere Updates werden mit derselben Umgebungsprüfung überprüft. Die vollständige reproduzierbare Produktinstallation ist ein späteres, eigenes Modul.

## Lokales Mailpostfach starten

In einem Terminal:

```bash
python3 scripts/mailpit.py
```

Danach ist das Postfach im Browser unter **http://127.0.0.1:8025** erreichbar. Der SMTP-Empfänger lauscht auf **127.0.0.1:1025**. Zum Stoppen im selben Terminal **Ctrl+C** drücken. Für einen späteren Neustart denselben Befehl erneut ausführen.

Der Starter ersetzt seinen eigenen Prozess durch Mailpit. Dadurch erreicht Ctrl+C den Server direkt. Ein bereits belegter Port führt zu einem sichtbaren Startfehler; ein anderer laufender Dienst wird nicht beendet.

### Lokale Einstellungen

- Weboberfläche und SMTP lauschen ausdrücklich nur auf 127.0.0.1.
- Erlaubte Empfänger enden auf `@example.test`, etwa `kunde@example.test` oder `referent@example.test`.
- Es wird kein SMTP-Passwort und kein TLS für diese ausschließlich lokale Verbindung benötigt.
- Externe SMTP-Relays, Weiterleitungen und Webhooks werden nicht konfiguriert. Geerbte `MP_`-Umgebungsvariablen werden beim Start entfernt, damit sie diese Einstellungen nicht unbemerkt verändern.
- Versionsabfragen und Reverse-DNS-Abfragen des Testservers sind deaktiviert.
- Nachrichten liegen unter `.local/mailpit/`. Nach Neustart bleiben sie erhalten; Mailpit bewahrt höchstens 1000 Nachrichten und höchstens sieben Tage auf.
- `.local/` einschließlich Nachrichten, Cache und Konfigurationssicherung ist durch `.gitignore` ausgeschlossen.

Diese Testkonfiguration ist keine Produktions-Mailkonfiguration. Die geplante Anwendung bekommt später eine eigene validierte Umgebungskonfiguration; sie verwendet weder diese Postfachdatenbank noch den Diagnose-Sendmail-Aufruf als Produktions-Mailarchitektur. [Mailpit-Konfiguration](https://mailpit.axllent.org/docs/configuration/runtime-options/)

## Mailversand aus PHP prüfen

Während Mailpit läuft, in einem zweiten Terminal:

```bash
php scripts/mail-smoke.php
```

Der Test ruft den mit Mailpit gelieferten Sendmail-Client aus PHP auf. Dieser übergibt eine Nachricht per SMTP an 127.0.0.1:1025. Anschließend liest das Skript die lokale Mailpit-API und prüft:

1. Das erwartete Corpsakademie-Projektpostfach ist erreichbar.
2. Die eindeutig gekennzeichnete Nachricht ist genau einmal vorhanden.
3. Absender und Empfänger stimmen.
4. Sowohl Textalternative als auch HTML enthalten die richtige Prüfkennung.
5. Eine andere Empfängerdomain wird mit einem permanenten SMTP-Fehler abgewiesen. Auch dieser Prüfversuch geht ausschließlich an den lokalen Server.

Die erfolgreich versendete Nachricht bleibt im Postfach sichtbar. Jeder erneute Aufruf erzeugt eine weitere, eindeutig gekennzeichnete Testmail. Der Test entfernt keine vorhandenen Nachrichten. Das Diagnoseskript ist nur für CLI-Aufrufe vorgesehen.

## Nächste Produktintegration

Die spätere Anwendung verwendet eine gepflegte PHP-Mailbibliothek und die transaktionale Outbox. Ihre Buchungs- und Newslettertests binden dieselbe lokale SMTP-Adresse ein. Verifikation, Erinnerungen, Angebotsanhänge, Double-Opt-in und Abmeldung sind anschließend als echte Anwendungsabläufe zu testen.

Die externe Zustellbarkeit, Domainauthentifizierung, Providerquoten und Rückläufer werden mit dem ausgewählten Produktiv-Maildienst auf dem Testserver geprüft. Die vorhandene lokale Einrichtung ermöglicht ab jetzt Entwicklung und kontrollierte Mailtests.
