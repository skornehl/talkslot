---
title: Sicherheit
description: Die in TalkSlot eingebauten Schutzmaßnahmen und wie Sie eine Sicherheitslücke melden.
---

## Sicherheitslücke melden

Bitte nutzen Sie GitHubs **private vulnerability reporting**
([*Security → Report a vulnerability*](https://github.com/skornehl/talkslot/security/advisories/new)),
keine öffentlichen Issues.

## Eingebaute Schutzmaßnahmen

**Datenbank**
- Überall PDO Prepared Statements, ohne Emulation, im strikten SQL-Modus. Ein Unit-Test analysiert jeden
  Datenbankaufruf und schlägt fehl, wenn eine Variable in SQL-Text eingesetzt (verkettet) wird.
- Smoke-Tests schicken typische SQL-Injection-Payloads an jedes Eingabefeld.
- Doppelbuchungen werden doppelt verhindert: `SELECT … FOR UPDATE` in einer Transaktion und ein
  eindeutiger Schlüssel auf dem Termin.

**Aus- und Eingabe**
- Jede Ausgabe wird escapt. Markdown wird zuerst escapt; Links erlauben nur http(s), mailto und relative
  Pfade.
- Strikte Content Security Policy ohne `unsafe-inline`, `X-Frame-Options: DENY`, `nosniff`,
  `Referrer-Policy: no-referrer` (persönliche Änderungslinks gelangen nicht über den Referrer nach außen),
  HSTS, Permissions-Policy und `Cache-Control: no-store`. Sie gehen mit jeder Antwort hinaus, auch mit 404-
  und Fehlerseiten und den 403-Seiten, die Apache selbst erzeugt.
- CSRF-Schutz: Session-Token im Admin-Bereich; auf öffentlichen Seiten ein zustandsloses HMAC-Token mit
  Mindest- und Höchstalter.
- Formulare, die der Browser als von einer anderen Website abgeschickt kennzeichnet
  (`Sec-Fetch-Site: cross-site`), werden abgelehnt, bevor irgendetwas verarbeitet wird. Das verhindert auch
  Login-CSRF (eine fremde Seite meldet Sie in einem Konto des Angreifers an). `Origin` wird bewusst nicht
  geprüft: Wegen `Referrer-Policy: no-referrer` senden Browser auch bei den Formularen von TalkSlot selbst
  `Origin: null`. Browser ohne diesen Header schützen weiterhin die Tokens.
- Spamschutz: Honeypot-Feld, Mindestzeit zum Ausfüllen eines Formulars, Rate-Limits.

**Tokens**
- Persönliche Änderungslinks, Einladungs- und Passwortlinks sind 256-Bit-Zufallstokens; gespeichert wird
  nur ihr SHA-256-Hash.
- Die Seite des Termins gibt den Änderungslink nur als Kopie, als Kalenderdatei auf einen POST-Button hin
  (keine zusätzliche GET-Adresse mit dem Token) und, wo das Gerät es anbietet, über einen Teilen-Dialog mit
  Warnhinweis heraus. Eine Bestätigungs-E-Mail an eine dort eingegebene Adresse ist auf zwei pro Buchung
  innerhalb von 24 Stunden begrenzt.
- Rundencodes: 8 Zeichen aus 31 Symbolen (31⁸ Kombinationen), Fehlversuche begrenzt auf 30 pro 15 Minuten
  und gekürzter IP-Adresse.

**Admin-Konten**
- Session-Cookie nur für `/admin`, `HttpOnly`, `Secure`, `SameSite=Strict`, Strict Mode, eigenes
  Session-Verzeichnis, 30 Minuten Inaktivitäts- und 8 Stunden absolutes Timeout, neue Session-ID beim
  Login. Eine Anfrage ohne Session-Cookie erhält überhaupt keine Session (keine Datei, kein Cookie): Seiten
  des Adminbereichs schicken sie zur Login-Seite, die nach der Anmeldung zur angefragten Seite zurückführt
  (nur Pfade des Adminbereichs, nie eine andere Website). Bei Links von anderen Websites, etwa dem Link
  einer Zusammenfassung in einem Webmailer, hält der Browser das Strict-Cookie zurück; eine solche Anfrage
  (`Sec-Fetch-Site: cross-site`) erhält eine kleine Zwischenseite, die dieselbe Adresse noch einmal von
  TalkSlot aus öffnet (Meta-Refresh). So landet ein angemeldeter Browser angemeldet, und sein Cookie wird
  nie ersetzt. Das geschieht nur einmal: Die Adresse, die sie öffnet, trägt `reopened=1`, und ein Browser,
  der das Cookie weiter zurückhält, erhält dann die Login-Seite statt einer Schleife.
- Eine Passwortänderung oder -zurücksetzung beendet alle anderen Sessions des Kontos. *Alle Sitzungen
  beenden* (unter *Benutzer*) beendet die Sessions aller Konten (ein globaler Zähler in den Einstellungen).
- Brute-Force-Schutz: höchstens 5 fehlgeschlagene Logins pro gekürzter IP-Adresse in 15 Minuten und 20 pro
  Konto und Stunde (gezählt pro Konto, verschiedene Schreibweisen eines Namens teilen sich den Zähler).
  Nur Benutzernamen, die ein Konto haben kann (`A–Z a–z 0–9 . _ @ + -`), erreichen die Datenbank: Deren
  Kollation ignoriert Groß-/Kleinschreibung und Akzente, `SÁBRİNA` oder ein Name mit Nullbreiten-Leerzeichen
  fände sonst `sabrina`, aber mit eigenem Zähler. Antworten und Antwortzeiten verraten nicht, ob ein Konto
  existiert.
- Das Limit pro Konto sperrt nur *neue* Geräte. Ein Browser, der sich schon einmal beim Konto angemeldet
  oder sein Passwort über einen Link aus einer E-Mail festgelegt hat, behält ein signiertes
  **Geräte-Cookie** (`tp_device_…`, nur an `/admin/login` gesendet, `HttpOnly`, `SameSite=Strict`,
  365 Tage, bei jeder Anmeldung erneuert). Damit gelten nur das IP-Limit und ein Limit von 20 Fehlversuchen
  pro Gerät, Fremde können Lehrkräfte also nicht von ihren eigenen Geräten aussperren. Ist das Limit des
  Kontos erreicht, geht eine E-Mail an die Adresse des Kontos (höchstens eine am Tag) mit dem Ausweg: ein
  bekanntes Gerät oder *Passwort vergessen?*. Die E-Mail geht nach der Antwort hinaus (aus der
  Warteschlange: mit PHP-FPM oder LiteSpeed direkt danach, sonst mit dem Cronjob) und nennt den Zeitpunkt,
  damit die Dauer einer abgelehnten Anmeldung nicht verrät, ob es das Konto gibt.
- Bekannte Geräte lassen sich widerrufen: Das Cookie ist mit dem Sitzungszähler des Kontos und dem aller
  Konten signiert. Ein neues Passwort (selbst oder über einen Link festgelegt) und ein Zurücksetzen durch
  einen Admin machen alle Geräte des Kontos wieder unbekannt, *Alle Sitzungen beenden* die aller Konten,
  ein neues `APP_SECRET` jedes Cookie. Der Browser, in dem das neue Passwort festgelegt oder alle Sitzungen
  beendet wurden, erhält ein neues Cookie. Ein Browser behält höchstens 20 solcher Cookies (die ältesten
  werden zuerst entfernt): Auf einem gemeinsamen PC im Lehrerzimmer würde ein Cookie pro Konto die Anfrage
  sonst über die Grenze des Servers von etwa 8 KB wachsen lassen, und die Anmeldeseite würde mit HTTP 400
  scheitern.
- Passwörter: 12 Zeichen bis 72 Byte (die Grenze von bcrypt) und nicht leicht zu erraten: kein häufiges
  Passwort aus einer lokalen Liste (`app/data/common-passwords.txt`, es wird nichts nach außen gesendet),
  auch nicht als Wort mit Ziffern oder Zeichen davor oder dahinter (`Sommer2024!!!!`), keine Wiederholungen
  oder Tastaturfolgen (`passwortpasswort`, `123456789012`, `1q2w3e4r5t6z`) und weder Benutzername,
  E-Mail-Adresse noch Name der Anwendung darin. Der Installer prüft das erste Admin-Konto nach denselben
  Regeln.
- Jede Admin-Route nennt in der Routentabelle des `AdminController` die Rolle, die sie braucht (`teacher`
  für jedes Konto oder `admin`). Eine Route ohne bekannte Rolle ist nur für Admins erreichbar; eine
  vergessene Rolle sperrt also, statt zu öffnen.
- Einen Admin einladen, Admin-Rechte vergeben oder entziehen, ein Admin-Konto deaktivieren, wieder
  aktivieren oder löschen, eine E-Mail-Adresse ändern (eigene oder fremde) und alle Sitzungen beenden
  fragen das Passwort des handelnden Kontos erneut ab. Fehleingaben dort und beim Ändern des eigenen
  Passworts zählen gemeinsam, höchstens 5 in 15 Minuten pro Anmeldung (Konto und Sitzung). Eine neue
  Anmeldung braucht das Passwort: Eine übernommene Sitzung allein genügt für diese Änderungen also nicht,
  und auch über die Passwortänderung kann sie nicht unbegrenzt raten. Den Kontoinhaber kann sie davon
  nicht aussperren, seine eigene Anmeldung hat einen eigenen Zähler.
- Sicherheitsmails: an den Kontoinhaber, wenn sein Passwort geändert oder festgelegt wurde, an die bisherige
  Adresse, wenn E-Mail-Adresse oder Benutzername geändert wurden, an alle aktiven Admins und die betroffene
  Person, wenn sich Admin-Rechte ändern und wenn alle Sitzungen beendet wurden.
- Mindestens ein aktiver Admin bleibt immer: Prüfung und Änderung laufen in einer Transaktion, die die
  Zeilen der aktiven Admins sperrt (`SELECT … FOR UPDATE`). Zwei Admins, die sich im selben Moment
  gegenseitig herabstufen, können also nicht beide Erfolg haben.
- Passwortlinks gehen nur per E-Mail hinaus und werden nie angezeigt, auch nicht, wenn der Versand
  scheitert. „Passwort vergessen“ antwortet auf jede Eingabe gleich und ersetzt keinen Link, der jünger als
  15 Minuten ist.
- Passwort- und Token-Parameter sind mit `#[\SensitiveParameter]` markiert: Sie erscheinen nie in
  Stacktraces, auch nicht mit `debug` (ein Unit-Test prüft jeden solchen Parameter).

**Betrieb**
- Keine Fehlerdetails im Produktivbetrieb; `public/.user.ini` verbirgt auch Fehler, die vor dem Start der
  App auftreten. Die App schreibt ein eigenes Log, aus dem Werte, Anführungszeichen und E-Mail-Adressen
  entfernt sind.
- `app/` und `config/` liegen außerhalb des Web-Roots und werden zusätzlich per `.htaccess` gesperrt. Das
  Deployment beschränkt `config.php` in jeder Version auf dem Server auf den Eigentümer (`chmod 600`), und
  die Übersicht warnt die Admins, solange alle Benutzer des Servers eine dieser Kopien lesen können (ob die
  Gruppe bei `640` mit anderen Kunden geteilt ist, kann sie nicht erkennen). Nach dem Austausch von
  Secrets löscht `prune` die alten Versionen mit ihrer alten `config.php`.
- Das Deployment prüft Konfigurationswerte gegen eine feste Auswahl (ein Tippfehler in `SMTP_ENCRYPTION`
  würde sonst Passwörter im Klartext bedeuten) und liefert den Installer nach der Installation nie aus.
- CI und Deployment verwenden keine GitHub Actions von Drittanbietern; Code wird mit einfachem `git` geholt,
  und das Token wird nur in einem Request-Header gesendet.

**Getestete Invarianten**

Tests sichern die Regeln ab, die eine Erweiterung sonst unbemerkt brechen könnte:
- Jede Admin-Route hat eine Rolle, und Lehrkräfte erhalten für jede Admin-Route 404 (auch mit gültigem
  Formular-Token).
- Keine GET-Route verändert Daten: Der Smoke-Test ruft jede Admin-GET-Route der Routentabelle und die
  öffentlichen Seiten auf, auch mit Formularfeldern in der Adresse, und danach müssen Datenbank und
  E-Mail-Ausgang unverändert sein. Eine neue Route mit unbekanntem Platzhalter lässt den Test fehlschlagen,
  bis sie abgedeckt ist.
- Das Öffnen eines Änderungslinks (`GET /b/…`, wie es Mail-Scanner und Linkvorschauen tun) sagt nie ab,
  verschiebt nie und verschickt keine E-Mail.
- Jeder Weg, einen Termin zu buchen oder zu verschieben, durch Eltern wie durch die Lehrkraft, wartet auf
  die Sperre des Tages, die das Neuberechnen der Termine hält, und nur `BookingService` schreibt Buchungen.
- 404-, 403- und Fehlerseiten tragen die Sicherheits-Header, und die `.htaccess` sendet dieselben Werte
  wie PHP.

## Bei Verdacht auf Missbrauch

Zum Beispiel ein verlorenes Gerät, das angemeldet war, ein Admin-Konto, das niemand kennt, eine
Sicherheitsmail über eine Änderung, die niemand gemacht hat, oder Einträge im *Protokoll*, die niemand
erklären kann:

1. **Alle Sitzungen beenden:** *Benutzer → Alle Sitzungen beenden* (mit Ihrem Passwort). Alle Konten müssen
   sich neu anmelden (alle bekannten Geräte gelten als neu, Konten über dem Anmeldelimit brauchen also
   *Passwort vergessen?*); ein neues App-Secret allein würde niemanden abmelden.
2. **Konten prüfen:** unter *Benutzer* unbekannte Admins und geänderte E-Mail-Adressen, im *Protokoll* die
   letzten Änderungen. Unbekannte Konten deaktivieren, nicht benötigte Admin-Rechte entziehen.
3. **Neue Passwörter:** das eigene Passwort ändern; für betroffene Konten *Link zum Zurücksetzen senden*
   (das bisherige Passwort gilt dann sofort nicht mehr).
4. **Zugang zum Server:** Wenn Hosting, Datenbank, FTP oder GitHub betroffen sein könnten, diese Passwörter
   und `APP_SECRET` ändern. Das Secret signiert Kurzlebiges (offene Formulare, Freischaltung per
   Rundencode, Schlüssel der Rate-Limits) und die Geräte-Cookies, die damit ungültig werden; die persönlichen Links der Eltern sind bewusst als reiner
   SHA-256-Hash gespeichert und funktionieren weiter.
5. **Spuren sichern:** die Einträge im *Protokoll* (werden nach `audit_log_days` gelöscht, Standard 90
   Tage), die Sicherheitsmails und `app/var/php-error.log` sichern und beim Webhoster die Zugriffslogs
   erfragen.
6. **Melden:** Könnten Unbefugte personenbezogene Daten gesehen haben, muss der Betreiber (meist die
   Schule) das bewerten und binnen 72 Stunden der Datenschutz-Aufsichtsbehörde melden (Art. 33 DSGVO) und
   bei hohem Risiko die Betroffenen informieren (Art. 34 DSGVO). Beziehen Sie Ihre Datenschutzbeauftragte
   bzw. Ihren Datenschutzbeauftragten ein.

## Bekannte Einschränkungen

- Rate-Limits gelten pro gekürzter IP-Adresse. Wer im selben Netz (z. B. Schul-WLAN) immer wieder falsche
  Passwörter sendet, sperrt die Logins aus diesem Netz für 15 Minuten.
- Wer einen Benutzernamen kennt (bei neuen Konten die E-Mail-Adresse, die Eltern als Antwortadresse sehen,
  sofern das Konto keine eigene Antwortadresse für Eltern angibt), kann das Limit dieses Kontos aufbrauchen. Bis zu einer Stunde lang ist das Konto dann nur auf Geräten
  nutzbar, die sich schon einmal angemeldet haben, oder nachdem in diesem Browser über *Passwort vergessen?*
  ein neues Passwort festgelegt wurde. Dazu geht eine E-Mail an die Adresse des Kontos.
- Es gibt keinen zweiten Faktor. Konten schützen die Passwortregeln und die Limits oben.
- Der Rundencode wird im Klartext gespeichert, weil Lehrkräfte ihn weitergeben müssen. Er schützt die
  Terminliste vor Fremden, nicht Konten.
