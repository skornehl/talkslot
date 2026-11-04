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
  HSTS, Permissions-Policy und `Cache-Control: no-store`.
- CSRF-Schutz: Session-Token im Admin-Bereich; auf öffentlichen Seiten ein zustandsloses HMAC-Token mit
  Mindest- und Höchstalter.
- Spamschutz: Honeypot-Feld, Mindestzeit zum Ausfüllen eines Formulars, Rate-Limits.

**Tokens**
- Persönliche Änderungslinks, Einladungs- und Passwortlinks sind 256-Bit-Zufallstokens; gespeichert wird
  nur ihr SHA-256-Hash.
- Rundencodes: 8 Zeichen aus 31 Symbolen (31⁸ Kombinationen), Fehlversuche begrenzt auf 30 pro 15 Minuten
  und gekürzter IP-Adresse.

**Admin-Konten**
- Session-Cookie nur für `/admin`, `HttpOnly`, `Secure`, `SameSite=Strict`, Strict Mode, eigenes
  Session-Verzeichnis, 30 Minuten Inaktivitäts- und 8 Stunden absolutes Timeout, neue Session-ID beim
  Login. Anonyme Besucher der Login-Seite erhalten überhaupt keine Session.
- Eine Passwortänderung oder -zurücksetzung beendet alle anderen Sessions des Kontos.
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
  bekanntes Gerät oder *Passwort vergessen?*.
- Passwörter: 12 Zeichen bis 72 Byte (die Grenze von bcrypt) und nicht leicht zu erraten: kein häufiges
  Passwort aus einer lokalen Liste (`app/data/common-passwords.txt`, es wird nichts nach außen gesendet),
  auch nicht als Wort mit Ziffern oder Zeichen davor oder dahinter (`Sommer2024!!!!`), keine Wiederholungen
  oder Tastaturfolgen (`passwortpasswort`, `123456789012`, `1q2w3e4r5t6z`) und weder Benutzername,
  E-Mail-Adresse noch Name der Anwendung darin. Der Installer prüft das erste Admin-Konto nach denselben
  Regeln.
- Das Ändern der E-Mail-Adresse erfordert das aktuelle Passwort und benachrichtigt die alte Adresse.
  „Passwort vergessen“ antwortet auf jede Eingabe gleich und ersetzt keinen Link, der jünger als 15 Minuten
  ist.

**Betrieb**
- Keine Fehlerdetails im Produktivbetrieb; `public/.user.ini` verbirgt auch Fehler, die vor dem Start der
  App auftreten. Die App schreibt ein eigenes Log, aus dem Werte, Anführungszeichen und E-Mail-Adressen
  entfernt sind.
- `app/` und `config/` liegen außerhalb des Web-Roots und werden zusätzlich per `.htaccess` gesperrt.
- Das Deployment prüft Konfigurationswerte gegen eine feste Auswahl (ein Tippfehler in `SMTP_ENCRYPTION`
  würde sonst Passwörter im Klartext bedeuten) und liefert den Installer nach der Installation nie aus.
- CI und Deployment verwenden keine GitHub Actions von Drittanbietern; Code wird mit einfachem `git` geholt,
  und das Token wird nur in einem Request-Header gesendet.

## Bekannte Einschränkungen

- Rate-Limits gelten pro gekürzter IP-Adresse. Wer im selben Netz (z. B. Schul-WLAN) immer wieder falsche
  Passwörter sendet, sperrt die Logins aus diesem Netz für 15 Minuten.
- Wer einen Benutzernamen kennt (bei neuen Konten die E-Mail-Adresse, die Eltern als Antwortadresse sehen),
  kann das Limit dieses Kontos aufbrauchen. Bis zu einer Stunde lang ist das Konto dann nur auf Geräten
  nutzbar, die sich schon einmal angemeldet haben, oder nachdem in diesem Browser über *Passwort vergessen?*
  ein neues Passwort festgelegt wurde. Dazu geht eine E-Mail an die Adresse des Kontos.
- Es gibt keinen zweiten Faktor. Konten schützen die Passwortregeln und die Limits oben.
- Der Rundencode wird im Klartext gespeichert, weil Lehrkräfte ihn weitergeben müssen. Er schützt die
  Terminliste vor Fremden, nicht Konten.
