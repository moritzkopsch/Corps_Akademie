# Corpsakademie 2.0

Individuelle Seminarplattform mit PHP und SQLite. Aktueller Implementierungsstand: sicheres Identitätsmodul, öffentliche Website, Seminar- und Referentenverwaltung, verifizierte Gastanfragen und Newsletter-Anmeldung mit Double-Opt-in. Referentenkalender, automatische Buchungskoordination, Angebote und Newsletter-Kampagnen folgen in weiteren Modulen.

## Lokal starten

Voraussetzungen: korrigiertes PHP 8.4 ab 8.4.27 oder PHP 8.5 ab 8.5.12, Composer und die in composer.json aufgeführten Erweiterungen. SQLite muss mindestens 3.51.3 enthalten; die zurückgezogene Version 3.52.0 wird abgewiesen. Geprüft ist lokal PHP 8.5.12 mit SQLite 3.54.1.

```bash
bash scripts/composer install
php bin/console install
php bin/console doctor

# Präsentationsinhalte ausschließlich lokal ergänzen:
php bin/console content:seed
```

Der Installer erzeugt eine private `.env` mit zufälligem Anwendungsschlüssel sowie die SQLite-Datenbank außerhalb des Webroots. Er legt kein Konto mit Standardzugangsdaten an. Bestehende Konfiguration und Daten werden bei wiederholter Installation nicht ersetzt.

Drei getrennte Terminalfenster im Projektordner:

```bash
# 1. Lokales SMTP-Postfach
python3 scripts/mailpit.py

# 2. Lokaler Mailworker
php bin/console mail:watch

# 3. Lokale Anwendung – ausschließlich public/ veröffentlichen
php -S 127.0.0.1:8080 -t public public/router.php
```

Anwendung: http://127.0.0.1:8080 · Testpostfach: http://127.0.0.1:8025. Jeder Prozess kann im zugehörigen Terminal mit Ctrl+C beendet und mit demselben Befehl neu gestartet werden.

## Öffentliche Website präsentieren

Startseite, Seminare, Referenten, Über uns, Terminplanung, FAQ und Kontakt sind ohne Anmeldung erreichbar. Der dreistufige Anfrageassistent speichert echte Anfragen und fordert eine E-Mail-Bestätigung an. Erst bestätigte Anfragen erscheinen unter `/admin/anfragen`. Die Präsentation verwendet Testadressen mit `@example.test`; Termine, Preise und rechtliche Inhalte benötigen vor Veröffentlichung eine Freigabe.

Ablauf, Quellen und genaue Produktgrenzen: [Öffentliche Website und Kundenpräsentation](docs/WEBSITE.md).

## Erstes Administratorkonto

```bash
php bin/console setup:link
```

Den ausgegebenen Einmallink im Browser öffnen. Er gilt 30 Minuten; ein neu angeforderter Link widerruft den vorherigen. Passwort ausschließlich im eigenen Browser eingeben. Lokal eine Adresse mit `@example.test` verwenden, die E-Mail im Testpostfach bestätigen und anschließend eine Authenticator-App verbinden. Die Wiederherstellungscodes sicher aufbewahren. Sobald das erste Konto angelegt ist, wird die Ersteinrichtung gesperrt.

## Prüfen

```bash
vendor/bin/phpunit
bash scripts/composer validate --strict
bash scripts/composer audit
php bin/console doctor
php scripts/check-local.php

# Optional, wenn Webserver und Mailpit laufen:
php scripts/check-live.php
```

Automatisierte Fachtests verwenden eigene temporäre SQLite-Dateien und einen Mail-Testadapter. Die optionale Live-Prüfung testet zusätzlich die HTTP-Grenzen und den echten Anwendungs-Mailer über SMTP mit einer separaten, anschließend entfernten Testdatenbank. Sie verändert keine Benutzerkonten der laufenden Anwendung.

## Weitere Konten und Betrieb

```bash
php bin/console user:create referent@example.test "Vorname Nachname" --role=instructor
php bin/console mail:work
php bin/console db:backup
```

Die Passworteingabe für `user:create` erfolgt verdeckt im interaktiven Terminal. Ein neu angelegtes Konto muss seine E-Mail-Adresse bestätigen. Die CLI ist ein Werkzeug des vertrauenswürdigen Serverbetreibers und kein öffentlicher Administrationsendpunkt.

Der spätere Kundenbetrieb verlangt eine geprüfte Serverinstallation mit HTTPS und ausschließlich `public/` als Webroot. Die lokale PHP-Serverfunktion und `mail:watch` dienen der Entwicklung. Produktiver Versand verwendet `mail:work` als begrenzten CLI-Lauf; die vollständige Cron-/Queue-Betriebsabnahme folgt im Automatisierungsmodul.

## Namecheap / cPanel

Die eingecheckte `.cpanel.yml` startet den Deploymenthelfer für das Konto `pushgobt`. Vor dem ersten Deployment private Konfiguration einrichten: [Namecheap-Anleitung](docs/NAMECHEAP.md).

## Dokumentation

- [Öffentliche Website und Präsentationsablauf](docs/WEBSITE.md)
- [Bildmotiv und Generierungsprompt](docs/BILDQUELLEN.md)
- [Architektur und offene Geschäftsentscheidungen](docs/ARCHITEKTUR.md)
- [Machbarkeitsanalyse](docs/MACHBARKEIT.md)
- [Lokale Werkzeuge und SMTP-Postfach](docs/LOKALE_ENTWICKLUNG.md)
- [Implementiertes Fundament, Sicherheitsgrenzen und Installation](docs/FUNDAMENT.md)

`.env`, `storage/`, `.local/` und `vendor/` sind von Git ausgeschlossen. Der Anwendungsschlüssel wird mit den zugehörigen Daten gesichert und niemals in Git eingecheckt.
