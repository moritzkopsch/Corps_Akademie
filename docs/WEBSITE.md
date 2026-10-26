# Öffentliche Website und Präsentationsablauf

Stand: 25. Oktober 2026. Auf dem bestehenden PHP-/PostgreSQL-Fundament ist eine öffentliche Website mit echter Inhaltsverwaltung und verifizierten Gastanfragen umgesetzt. Sie ist als lokale Kundenpräsentation nutzbar. Die vollständige automatische Buchungskoordination bleibt ein eigenes Modul.

## Gestaltung und Zielgruppe

Verbindliche visuelle Referenz: die vier Website-Mockups auf Folie 15 der gelieferten Präsentation `Corpsakademie_2_0_Backend_Funktionskonzept.pptx` (interne Folienbeschriftung 16). Übernommen sind dunkles Marineblau `#082244`, Gold `#B38A40`, Weiß, großzügige Flächen, Serifentitel, eine helle Bildbühne, Seminarkarten, Themenfilter und Einstiege nach Zielgruppen. Der Aufbau der Verwaltungsoberflächen orientiert sich zusätzlich an den Folien zur Seminar- und Referentenverwaltung.

Die Ansprache richtet sich an Altherrenvorsitzende und Verantwortliche in Corps. Aktive, Studierende und Berufseinsteiger bleiben als Teilnehmende sichtbar. Es gibt keine erfundenen Bewertungen, Verkaufszahlen, freien Termine oder festen Preise.

Das C-Monogramm führt die vorhandene lokale Markenidee fort. Es ist kein neu erfundenes Corpswappen. Das Seminarmotiv ist eine generierte Illustration in fotografischer Form, keine Aufnahme tatsächlicher Kunden oder Referenten. Herkunft und vollständiger Prompt: [BILDQUELLEN.md](BILDQUELLEN.md).

## Seiten

- `/`: Startseite mit Seminaren, Zielgruppen und Planungsablauf.
- `/ueber-uns`: Anliegen und Corpsbezug der Akademie.
- `/seminare`: datenbankgestütztes Angebot mit Suche und Themenfiltern.
- `/seminare/{slug}`: Inhalte, Lernziele, Zielgruppe und zugeordnete Referenten.
- `/referenten` und `/referenten/{slug}`: öffentliche Profile ohne private Kontaktdaten.
- `/termine`: Themen und Terminwünsche; keine simulierten Live-Verfügbarkeiten.
- `/faq`: Antworten zum derzeit tatsächlich verfügbaren Ablauf.
- `/anfrage`: Gastanfrage mit drei Schritten und Referentenwunsch.
- `/kontakt`: echte Kontaktanfrage mit anschließender E-Mail-Bestätigung.
- `/newsletter`: eigenständige Anmeldung mit Double-Opt-in.
- `/anfrage/bestaetigen`, `/newsletter/bestaetigen`, `/newsletter/abmelden`: bewusste Bestätigung über Formular-POST. Ein GET verbraucht keinen Link.
- `/impressum` und `/datenschutz`: ausdrücklich als Entwicklungsstand ausgewiesene Hinweise und Verweise zur Bestandswebsite. Vor Veröffentlichung sind freigegebene Betreiber- und Datenschutzhinweise erforderlich.

Die öffentliche Website erfordert kein Benutzerkonto. Administration und Referentenportal behalten ihre Authentifizierungs- und MFA-Grenzen.

## Präsentation in wenigen Minuten

1. `http://127.0.0.1:8080/` öffnen. Die Navigation und die Zielgruppeneinstiege zeigen den Gesamtaufbau.
2. Die Seminarübersicht öffnen, nach einem Begriff suchen oder eine Kategorie wählen.
3. Zum Beispiel das Bewerbungstraining öffnen, die Referentenprofile ansehen und „Seminar anfragen“ wählen.
4. Im Assistenten eine Empfehlung, eine Person aus Alternativen oder ein gewünschtes Team angeben. Gruppengröße, Corpshaus und Ansprechpartner mit erfundenen Angaben ausfüllen. Ein Termin darf offen bleiben.
5. Eine Adresse mit `@example.test` verwenden. Die Bestätigungsmail unter `http://127.0.0.1:8025/` öffnen und den Link im Browser bestätigen.
6. Mit einem vorhandenen Administratorkonto `/admin/anfragen` öffnen. Die bestätigte Anfrage ist sichtbar und lässt sich auf „In Bearbeitung“ oder „Abgeschlossen“ setzen.
7. In `/admin/seminare` und `/admin/referenten` Inhalte ändern. Nur veröffentlichte Einträge erscheinen anschließend für Gäste.

Für die Browserprüfung wurde ausschließlich die ausdrücklich künstliche Anfrage „Präsentationstest – Testcorps“ verwendet. Sie ist keine echte Buchung. Die Formulare funktionieren in Chrome und im integrierten Browser; normale Cookies und JavaScript für den Assistenten und Link-Fragmente sind erforderlich. Ohne JavaScript bleibt das eigentliche Anfrageformular vollständig sichtbar und sendbar; die Bestätigungsseiten weisen auf die notwendige Verarbeitung des Link-Fragments hin.

## Daten und Installation

Neue Migration: `002_public_website.sql`. Sie ergänzt Kategorien, Seminare, Referentenprofile, Zuordnungen, Gastanfragen, Newsletter-Abonnenten, zweckgebundene Einmallinks und eine eigene Mail-Outbox. Bestehende Konten und Identitätsmails werden weiterverwendet.

```bash
php bin/console db:migrate
php bin/console content:seed
```

`content:seed` ist ausschließlich in `local` und `test` erlaubt. Es ergänzt 11 Seminare und 3 Referentenprofile und überschreibt keine vorhandenen Einträge. Es erstellt keine Benutzerkonten und veröffentlicht keine Daten auf einem externen Server. Produktion erhält nur redaktionell abgenommene Inhalte über den späteren Migrationsprozess.

Die Kurzprofile von Jürgen F. von Randow, Jan Wattjes und Frederic Wilhelm basieren auf den unten genannten Bestandsseiten. Sie enthalten keine neu behaupteten Titel, Jahreszahlen oder Kontaktdaten. Die Initialendarstellung ersetzt derzeit fehlende freigegebene Porträtbilder. Seminarterminologien und redaktionelle Beschreibungen sind Präsentationsinhalte. Insbesondere bei Führung, Projektmanagement, Teambuilding sowie Brauch und Sitte sind Umfang und Durchführung noch redaktionell zu bestätigen.

Öffentliche Quellen, am 24.10.2026 eingesehen:

- https://www.corpsakademie.de/
- https://www.corpsakademie.de/kommunikation-und-rhetorik/
- https://www.corpsakademie.de/berufseinsteiger/
- https://www.corpsakademie.de/studium-und-corps/
- https://www.corpsakademie.de/faq-leitfaden/

## Echte Anfrageverarbeitung

Seminaranfragen und Kontaktanfragen werden zunächst im Zustand `unverified` gespeichert. Die Inhalte liegen mit zweckgebundener AEAD-Verschlüsselung vor. E-Mail-Adresse und technische Metadaten verbleiben in der privaten Datenbank; die öffentlich erreichbaren Seiten liefern diese Daten nicht aus. Erst nach Bestätigung ist die Anfrage in der Verwaltung sichtbar.

Einmallinks sind zufällig, nur als Hash gespeichert, zweckgebunden, einmal verwendbar und zur Bestätigung 30 Minuten gültig. Token stehen im URL-Fragment, werden von JavaScript in das Formular übernommen und aus der sichtbaren URL entfernt. Der Verbrauch und die Aktivierung erfolgen gemeinsam in einer Schreibtransaktion mit gesperrter Zeile (`FOR UPDATE`). Auch parallele Bestätigungen erzeugen nur eine Aktivierung und eine Eingangsbestätigung.

Ein zufälliger Formularschlüssel verhindert bei wiederholtem Absenden desselben Formulars doppelte Anfragen. CSRF-Schutz, Herkunftsprüfung, begrenzte Eingabelängen, Teilnehmerzahlprüfung, Datumsvalidierung, ein Honeypot sowie Limits nach IP und normalisierter E-Mail-Adresse gelten serverseitig. Ein Wunschtermin wird technisch auf heute bis zwei Jahre in die Zukunft begrenzt; das ist keine Verfügbarkeits- oder Buchungszusage. Referenten können ausschließlich aus den aktuell veröffentlichten, zum Seminar zugeordneten Profilen gewünscht werden.

Newsletter-Anmeldungen sind von Anfragen getrennt. Ohne ausdrückliche Anmeldung und Double-Opt-in wird kein Abonnement aktiv. Die Bestätigungsmail nach erfolgreicher Anmeldung enthält einen eigenen Abmeldelink; dessen derzeitige technische Gültigkeit beträgt ein Jahr. Vor einem späteren Kampagnenversand muss je Empfänger ein aktueller Abmeldeweg in jede Nachricht integriert werden. Ein Kampagneneditor und automatischer Newsletter-Versand sind damit noch nicht implementiert.

Die öffentliche Mail-Outbox verwendet die gleichen Versandgrenzen wie das Fundament: außerhalb der Produktion nur lokales SMTP und `@example.test`. SMTP arbeitet außerhalb fachlicher Transaktionen. Nachrichteninhalte werden nach Übergabe entfernt. Abgelaufene Versand-Leases und uneindeutige SMTP-Ergebnisse werden als `ambiguous` gekennzeichnet und nicht automatisch erneut versandt. Der aktualisierte lokale Watcher verarbeitet Konto- und öffentliche Mails.

Bei der Browserprüfung wurde ein bereits im Fundament enthaltener Konflikt korrigiert: `Referrer-Policy: no-referrer` führt bei normalen Formular-POSTs zu `Origin: null`. Die Website verwendet jetzt `same-origin`. Externe Seiten erhalten weiterhin keinen Referrer; die Herkunftsprüfung kann normale Formulare erkennen. `Origin: null` und fremde Origins werden weiterhin abgewiesen. Technische Referenz: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Referrer-Policy#effect_on_the_origin_header

## Verwaltung

`content.manage` erlaubt das Anlegen und Bearbeiten von Seminar- und Referenteninhalten. `inquiries.manage` erlaubt ausschließlich den dafür berechtigten Konten die Einsicht und Bearbeitung bestätigter Anfragen. Beide Berechtigungen erfordern MFA. Die Standardrollen Admin und Superadmin erhalten diese Rechte, Referenten nicht.

Seminare und Profile unterstützen die Zustände `draft`, `published`, `inactive`, `archived`. Inhalte bleiben bei Deaktivierung erhalten. Versionszähler verhindern, dass ein älteres geöffnetes Formular eine neuere Änderung überschreibt. HTML in redaktionellen Textfeldern wird escaped und nicht ausgeführt.

## Prüfungen und weitere Produktmodule

Die Tests decken alle öffentlichen Seiten, Suche, Filter, öffentliche Sichtbarkeit, Gastanfragen, Verschlüsselung, CSRF, fremde Origins, ungültige Termine, Referentenauswahl, doppelte Übermittlung, Link-Ablauf und Zweckbindung, Double-Opt-in, Abmeldung, Mail-Ambiguität, Rollenrechte, Inhaltsverwaltung und Versionskonflikte ab. Ein Test mit zwei echten PHP-Prozessen prüft die einmalige Aktivierung einer Gastanfrage.

Zusätzlich wurde eine künstliche Anfrage in Chrome tatsächlich abgesendet, über Symfony Mailer und SMTP in Mailpit empfangen und im Browser bestätigt. Der Versand einer Konto-Mail wurde mit `scripts/check-live.php` und einer separaten temporären Datenbank geprüft. Mobile Navigation, Startseite und Seminarübersicht wurden bei 390 Pixeln geprüft. Der Anfrageassistent wurde zusätzlich bei 820 Pixeln, die Startseite bei normaler Desktopgröße geprüft. Die kontrollierten Ansichten zeigten keinen horizontalen Überlauf. Auch eine Newsletter-Testanmeldung wurde im integrierten Browser erfolgreich abgesendet.

Abschließende automatisierte Prüfung: **53 Tests mit 397 Assertions erfolgreich**. PHP-Syntaxprüfung und `doctor` melden keine Fehler; Migration und Seed sind bei Wiederholung ohne neue Änderungen.

Vor dem Produktivbetrieb fehlen weiterhin: Referentenkalender und echte Verfügbarkeitsberechnung, automatische Einladungen und verbindliche Buchungskoordination, Erinnerungen/Ersatz/Eskalationen, freigegebene Preisregeln und Angebote, Newsletter-Kampagnen/Archiv, allgemeines Seiten-/Medien-CMS sowie Zielhosting, rechtliche und redaktionelle Freigaben, Datenaufbewahrung und Löschläufe sowie die vollständige Betriebs- und Migrationsabnahme. Die öffentliche Oberfläche behauptet diese Abläufe nicht als bereits verfügbar.
