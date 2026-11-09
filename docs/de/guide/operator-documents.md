---
title: Unterlagen für Schule und Betreiber
description: Vorlagen für das Verzeichnis von Verarbeitungstätigkeiten, die technischen und organisatorischen Maßnahmen, die DSFA-Schwellwertprüfung, Datenpannen, Betroffenenanfragen und ein Informationsblatt für Lehrkräfte.
---

Die DSGVO verlangt von der verantwortlichen Stelle, dass sie nachweisen kann, wie personenbezogene Daten
verarbeitet werden (Rechenschaftspflicht, Art. 5 Abs. 2 DSGVO). Die meisten Fakten stehen schon fest: Sie
ergeben sich daraus, wie TalkSlot arbeitet. Diese Seite fasst sie als **Vorlagen** zusammen, die Sie in Ihre
eigenen Unterlagen übernehmen und ergänzen. Sie ist keine Rechtsberatung: Prüfen Sie das Ergebnis mit Ihrer
oder Ihrem Datenschutzbeauftragten, und passen Sie es an Ihr Bundesland und Ihre Umgebung an.

- Text in `[ECKIGEN KLAMMERN]` füllen Sie selbst aus.
- **Von der Schule betrieben:** Die Schule ist Verantwortliche und führt diese Unterlagen; die Schulleitung
  entscheidet, die oder der Datenschutzbeauftragte der Schule berät. **Privat betrieben:** Sie (Person, Verein
  oder freier Träger) sind Verantwortliche für die Terminvergabe und führen sie; die Gespräche selbst, die
  Zusammenfassungen im Postfach der Lehrkräfte und Ausdrucke gehören zur Schule (siehe
  [Datenschutz und Recht]({{ '/de/guide/privacy/' | relative_url }}#von-der-schule-oder-privat-betrieben)).
  Wo sich beides unterscheidet, sagt diese Seite es.
- Zugehörige Abläufe: [Backup und Wiederherstellung]({{ '/de/guide/operations/' | relative_url }}#backups),
  [Secrets austauschen]({{ '/de/guide/operations/' | relative_url }}#changing-secrets),
  [Bei Verdacht auf Missbrauch]({{ '/de/guide/security/' | relative_url }}#bei-verdacht-auf-missbrauch),
  [Bedrohungsmodell]({{ '/de/guide/security/' | relative_url }}#threat-model).

## Checkliste vor der Einführung {#checklist}

| Schritt | Von der Schule betrieben | Privat betrieben |
|---|---|---|
| Entscheidung | Die Schulleitung beschließt den Einsatz für Elterngespräche, Datum: `[DATUM]` | Sie entscheiden, das Angebot zu machen; die Schule der Lehrkräfte entscheidet, ob sie es nutzen dürfen |
| Datenschutzbeauftragte | Die oder den Datenschutzbeauftragten der Schule vor dem Start beteiligen | Falls für Sie jemand benannt ist, beteiligen; Lehrkräfte fragen die oder den Datenschutzbeauftragten ihrer Schule |
| Personalrat | Vor dem Start beteiligen: TalkSlot verarbeitet Beschäftigtendaten (Konten, Anmeldezeiten, Protokoll) und kann als Einrichtung gelten, die zur Überwachung geeignet ist (Personalvertretungsgesetz Ihres Landes) | Nicht bei einem Angebot, das Lehrkräfte nur nutzen; deren Schulen können ihren Personalrat beteiligen. Nutzen es Ihre eigenen Beschäftigten (z. B. Ersatzschule, Verein): Betriebsrat oder Mitarbeitervertretung beteiligen (z. B. § 87 Abs. 1 Nr. 6 BetrVG, kirchliches Recht) |
| Auftragsverarbeiter (Art. 28) | Verträge mit dem Hoster und, falls getrennt, dem Mailanbieter. Betreibt jemand anderes den Server für die Schule (z. B. eine ehrenamtliche Person mit eigenem Hostingvertrag): Vertrag mit dieser Person, der Hoster als Unterauftragsverarbeiter (Art. 28 Abs. 2, 4) | Verträge mit dem Hoster und, falls getrennt, dem Mailanbieter |
| Verzeichnis | [Eintrag unten](#record-of-processing) im Verzeichnis der Schule | [Eintrag unten](#record-of-processing) in Ihrem eigenen Verzeichnis |
| TOM | [Beschreibung unten](#toms) plus die TOM des Hosters aus seinem Vertrag | Ebenso |
| DSFA | [Schwellwertprüfung unten](#dpia-check), dokumentiert | Ebenso |
| Rechtstexte | Datenschutzerklärung und Impressum unter *Texte*, alle Platzhalter ausgefüllt, auch in den Übersetzungen | Ebenso |
| Lehrkräfte | [Informationsblatt](#information-sheet-for-teachers), dienstliche E-Mail-Adressen (`allowed_account_domains`) | Ebenso; Lehrkräfte klären mit ihrer Schule, ob sie das Angebot nutzen dürfen |
| Admin-Konten | Nur Personal der Schule oder schriftlich zur Vertraulichkeit verpflichtete Personen | Nur Ihr Personal oder schriftlich zur Vertraulichkeit verpflichtete Personen |
| Backups | [Zwei Arten mit Fristen](#backups-and-retention) | Ebenso |
| Hoster | [Log-Aufbewahrung, Statistik](#web-host-logs-and-statistics) | Ebenso |

## Verzeichnis von Verarbeitungstätigkeiten (Art. 30 DSGVO) {#record-of-processing}

Die Ausnahme für kleine Organisationen (Art. 30 Abs. 5) greift nicht: Die Verarbeitung erfolgt nicht nur
gelegentlich.

| Feld | Eintrag |
|---|---|
| Bezeichnung | Online-Terminvergabe für Elterngespräche (TalkSlot) |
| Verantwortliche | Schule: `[NAME UND ANSCHRIFT DER SCHULE, VERTRETEN DURCH DIE SCHULLEITUNG]`. Privat: `[NAME UND ANSCHRIFT DES BETREIBERS]` |
| Datenschutzbeauftragte | `[NAME UND KONTAKT]` (privat auch: „nicht benannt, nicht erforderlich (Art. 37 DSGVO, § 38 BDSG)“) |
| Zwecke | Buchen, Ändern und Absagen von Terminen; Bestätigungen, Erinnerungen und Änderungen per E-Mail; Zusammenfassungen an die Lehrkraft; Schutz vor Missbrauch; Konten und Verwaltung für Lehrkräfte und Admins; Rechenschaft (Protokoll) |
| Rechtsgrundlage | Schule: Art. 6 Abs. 1 lit. e, Abs. 3 DSGVO i. V. m. `[VORSCHRIFT IM SCHULGESETZ DES LANDES]` und `[VORSCHRIFT IM DATENSCHUTZGESETZ DES LANDES]`; Beschäftigtendaten `[LANDESRECHTLICHE VORSCHRIFT ZU BESCHÄFTIGTENDATEN]`. Privat: Art. 6 Abs. 1 lit. b DSGVO (Terminvereinbarung), lit. b und f (Zusammenfassungen), lit. f (Missbrauchsschutz); Konten lit. b. Beide: „Termin merken“ im Browser nur mit Einwilligung (§ 25 Abs. 1 TDDDG, Art. 6 Abs. 1 lit. a) |
| Betroffene | Eltern und Erziehungsberechtigte; die in einer Buchung genannten Kinder; Lehrkräfte und Admins |
| Datenkategorien | Eltern: eingegebener Name (meist Name und Klasse des Kindes), optionale E-Mail-Adresse, in Runden, die danach fragen, freiwillig die Sprache eines Wunsches nach Dolmetschung, Termin, Sprache der Buchung, Hash des persönlichen Änderungslinks, gekürzte und gehashte IP-Adresse (Missbrauchsschutz). Lehrkräfte und Admins: Benutzername, Anzeigename, E-Mail- und Antwortadresse, optionaler Kontakt für Eltern, Sprache, Passwort-Hash, Anmeldezeit, Einstellungen, Geräte-Cookie im Browser, Einträge im Protokoll |
| Besondere Kategorien (Art. 9) | Nicht vorgesehen. Titel und Beschreibungen von Runden bleiben neutral: `[WIE LEHRKRÄFTE DARAUF HINGEWIESEN WERDEN]` |
| Empfänger | Die Lehrkraft der Runde; die Admins; Hoster und Mailanbieter als Auftragsverarbeiter; wer den Server für die Schule betreibt, als Auftragsverarbeiter. Privat: Die Lehrkraft handelt für die Schule, eine eigene Verantwortliche |
| Drittländer | Keine, außer `[MAILANBIETER AUSSERHALB VON EU/EWR UND GARANTIE]` |
| Löschfristen | Buchungen ab 0:00 am Tag nach dem Termin, bei Absage sofort; Mail-Warteschlange nach dem Versand, höchstens 48 Stunden; Daten zum Missbrauchsschutz 24 Stunden; Protokoll 90 Tage; Vermerk versendeter Zusammenfassungen (ohne Namen) 60 Tage; Runden nach ihrem letzten Tag (`[LÖSCHFRIST JE RUNDE, STANDARD 24 STUNDEN]`); Konten von Lehrkräften während der Nutzung, deaktivierte ohne genutzte Runden 15 Monate nach der letzten Anmeldung; Zusammenfassungen mit Namen im Postfach bis spätestens Ende des Schuljahres (privat betrieben: Empfehlung, es gelten die Regeln der Schule); Fehler-Log rotiert bei 5 MB; Konfigurations-Backup `[Z. B. 12 MONATE]`, Voll-Backup `[HÖCHSTENS 14 TAGE]`; Logs des Webservers `[FRIST BEIM HOSTER]` (Details: [Datenschutz und Recht]({{ '/de/guide/privacy/' | relative_url }}#was-gespeichert-wird)) |
| Technische und organisatorische Maßnahmen | Siehe [TOM](#toms) und die Verträge mit den Auftragsverarbeitern |
| Datum, zuständige Person | `[DATUM, NAME]` |

Was Lehrkräfte rund um das Gespräch selbst verarbeiten (Notizen, Zusammenfassungen im Postfach, Ausdrucke), gehört
in der Regel bei beiden Betriebsarten zum Verzeichnis der Schule unter ihrem vorhandenen Eintrag für Elterngespräche.
Nutzt eine Lehrkraft ein privat betriebenes Angebot ohne Freigabe der Schule, kann sie selbst Verantwortliche werden.

## Technische und organisatorische Maßnahmen (Art. 32 DSGVO) {#toms}

In Verwaltungssprache, für das Verzeichnis und die oder den Datenschutzbeauftragten. Was der Hoster tut
(Rechenzentrum, Zutritt, eigene Backups), steht in seinem Vertrag zur Auftragsverarbeitung; fügen Sie seine
TOM bei.

**Vertraulichkeit**
- Zugang nur mit persönlichem Konto; Passwörter mit mindestens 12 Zeichen, geprüft gegen verbreitete Passwörter
  und die eigenen Kontodaten; Begrenzung fehlgeschlagener Anmeldungen je Adresse, Konto und Gerät; Sitzungen enden
  nach 30 Minuten Inaktivität; Admins können alle Sitzungen auf einmal beenden.
- Rollen: Lehrkräfte sehen nur ihre eigenen Runden (zentral durchgesetzt, jede andere ID ergibt „nicht
  gefunden“); nur Admins sehen alle Runden, Konten, Rechtstexte und das Protokoll.
- Eltern brauchen kein Konto. Ihr persönlicher Änderungslink wird nur als Hash gespeichert; wo eine Runde ihn
  verlangt (*Nur mit Rundencode*), hält der Rundencode Fremde von der Terminliste fern.
- Datensparsamkeit: ein Name (der des Kindes), wahlweise eine E-Mail-Adresse und, in Runden, die danach fragen, die Sprache eines Wunsches nach Dolmetschung, sonst nichts; kein Tracking, keine
  Cookies auf öffentlichen Seiten, nichts von Dritten nachgeladen.
- Transportverschlüsselung: HTTPS für die Seite (`force_https`); zum Mailserver `[TLS BEI MAIL.TRANSPORT SMTP UND MAIL.SMTP.ENCRYPTION / MAILSERVER DES HOSTERS BEI PHP MAIL()]`.
- Zusammenfassungen an Lehrkräfte enthalten keine Namen, außer die Schule erlaubt sie, und nie E-Mail-Adressen.

**Integrität**
- Formulare sind gegen seitenübergreifende Anfragen geschützt; jeder Datenbankzugriff läuft über Prepared
  Statements; Eingaben werden geprüft, Ausgaben maskiert.
- Das Protokoll hält fest, wer welche Runde, welches Konto oder welchen Text geändert hat (keine Namen oder
  E-Mail-Adressen von Eltern, Buchungen nur mit Datum).
- Updates kommen als getestete Releases (automatische Tests bei jeder Änderung); `[BEIM AUTOMATISCHEN DEPLOYMENT:
  VERSIONEN WERDEN ATOMAR UMGESCHALTET, DIE VORHERIGE BLEIBT FÜR EIN ROLLBACK]`.

**Verfügbarkeit und Belastbarkeit**
- Backups: [zwei Arten mit festen Fristen](#backups-and-retention), Wiederherstellung getestet `[WIE OFT]`.
- Überwachung: Fehlermeldungen per E-Mail, Warnung, wenn der Cronjob ausbleibt, Health-Check beim Deployment.

**Löschung**
- Automatische Löschung wie im Verzeichnis oben; die Bereinigung läuft mit dem Cronjob und bei Seitenaufrufen.

**Organisation**
- Admin-Konten nur für `[PERSONAL DER SCHULE / DES BETREIBERS]` oder schriftlich zur Vertraulichkeit verpflichtete
  Personen.
- Konten von Personen, die ausscheiden, werden deaktiviert ([Offboarding](#offboarding)); das Dashboard nennt
  Konten, an denen sich lange niemand angemeldet hat.
- Secrets werden ausgetauscht, wenn sie bekannt geworden sein können
  ([Ablauf]({{ '/de/guide/operations/' | relative_url }}#changing-secrets)).
- Datenpannen folgen dem [Ablauf unten](#data-breach).
- Verantwortlich für den technischen Betrieb: `[NAME, KONTAKT]`.

## Schwellwertprüfung zur Datenschutz-Folgenabschätzung (Art. 35 DSGVO) {#dpia-check}

Eine DSFA ist nötig, wenn die Verarbeitung voraussichtlich ein hohes Risiko mit sich bringt. Prüfen Sie die Liste Ihrer
Aufsichtsbehörde mit Verarbeitungen, die immer eine DSFA brauchen: `[LISTE IHRER BEHÖRDE, GEPRÜFT AM]`. Üblich ist die
Prüfung anhand der neun Kriterien der europäischen Datenschutzbehörden (WP248 rev.01): Ab zwei erfüllten Kriterien ist
in der Regel eine DSFA nötig, im Einzelfall kann eines genügen, und wer trotz zweier darauf verzichtet, begründet das
schriftlich. Füllen Sie das aus und bewahren Sie es auf:

| Kriterium | Bewertung für TalkSlot |
|---|---|
| Bewertung oder Scoring | Nein |
| Automatisierte Entscheidungen mit Rechtswirkung | Nein |
| Systematische Überwachung | Nein (kein Tracking; das Protokoll erfasst nur Handlungen von Admins) |
| Sensible oder höchstpersönliche Daten | Nicht vorgesehen; Risiko durch Freitext-Titel gemindert durch den Hinweis, sie neutral zu halten |
| Umfangreiche Verarbeitung | Nein (`[ANZAHL]` Eltern einer Schule bzw. der Lehrkräfte, die das Angebot nutzen) |
| Abgleich oder Zusammenführung von Datensätzen | Nein |
| Schutzbedürftige Betroffene | Teilweise: Kinder werden genannt; Eltern können sich einem Werkzeug der Schule schwer entziehen; Lehrkräfte sind Beschäftigte |
| Innovative Technologie | Nein |
| Betroffene werden an der Nutzung eines Dienstes gehindert | Nein: Termine sind auch `[ALTERNATIVE, Z. B. TELEFONISCH ODER PERSÖNLICH]` möglich |

Ergebnis: `[ERFÜLLTE KRITERIEN; ERGEBNIS MIT BEGRÜNDUNG]`. Datum: `[DATUM]`, geprüft von:
`[NAME]`, Datenschutzbeauftragte beteiligt am: `[DATUM]`. Wiederholen Sie die Prüfung bei wichtigen Änderungen
(z. B. Namen in den Zusammenfassungen erlaubt, Mailanbieter außerhalb der EU, Runden zu sensiblen Themen).

## Informationsblatt für Lehrkräfte (Art. 13, 14 DSGVO) {#information-sheet-for-teachers}

Auch Lehrkräfte sind Betroffene. Geben Sie ihnen dieses Blatt, ausgefüllt, mit der Einladung:

> **TalkSlot: Was mit Ihren Daten geschieht**
>
> **Verantwortlich:** `[SCHULE / BETREIBER]`, Kontakt `[KONTAKT]`; Datenschutzbeauftragte `[KONTAKT]`.
>
> **Was gespeichert wird:** Ihr Benutzername (meist Ihre E-Mail-Adresse), Anzeigename, E-Mail-Adresse, eine
> optionale Antwortadresse und ein Kontakt für Eltern, Ihre Sprache und Einstellungen, Ihr Passwort als sicherer
> Hash, der Zeitpunkt Ihrer letzten Anmeldung (Admins sehen nur „nie“, „in den letzten 12 Monaten“ oder „vor über
> 12 Monaten“), fehlgeschlagene Anmeldungen und eine gekürzte, gehashte IP-Adresse (zur Begrenzung von Versuchen, 24 Stunden)
> und die Änderungen, die Admins an Ihrem Konto oder Ihren Runden vornehmen, im Protokoll (90 Tage). Während der
> Anmeldung hält Ihr Browser ein Sitzungs-Cookie; nach einer erfolgreichen Anmeldung behält er
> außerdem 365 Tage lang ein Cookie für bekannte Geräte, damit sich dieses Gerät auch dann anmelden kann, wenn
> jemand anderes das Limit für Ihr Konto aufgebraucht hat.
>
> **Wofür:** Ihr Konto, Ihre Runden und die Buchungen darin; E-Mails an Sie (Einladung, Passwort-Link,
> Zusammenfassungen, Sicherheitshinweise zu Ihrem Konto); Schutz vor Missbrauch.
>
> **Rechtsgrundlage:** `[SCHULE: LANDESRECHTLICHE VORSCHRIFT ZU BESCHÄFTIGTENDATEN, ART. 6 ABS. 1 LIT. C/E DSGVO / PRIVAT, LEHRKRÄFTE NUTZEN DAS ANGEBOT: ART. 6 ABS. 1 LIT. B UND F DSGVO (SICHERHEIT DES DIENSTES) / PRIVAT, EIGENE BESCHÄFTIGTE: VORSCHRIFT ZU BESCHÄFTIGTENDATEN]`.
>
> **Wer es sieht:** die Admins `[NAMEN ODER ROLLE]`; Eltern sehen Ihren Anzeigenamen, den Kontakt, den Sie angeben, und beim
> Antworten auf eine E-Mail Ihre Antwortadresse, ohne sie die E-Mail-Adresse Ihres Kontos; Hoster und Mailanbieter als
> Auftragsverarbeiter. Drittländer: `[KEINE / MAILANBIETER UND GARANTIE]`.
>
> **Zusammenfassungen:** Sie nennen standardmäßig die Zahl der Termine je Tag und einen Link, keine Namen. Sind
> Namen erlaubt und schalten Sie sie ein, löschen Sie diese E-Mails, sobald Sie sie nicht mehr brauchen, spätestens
> zum Ende des Schuljahres. `[REGELN DER SCHULE FÜR DIENSTLICHE POSTFÄCHER]`
>
> **Wie lange:** solange Sie TalkSlot nutzen. Die Admins löschen oder deaktivieren Ihr Konto, wenn Sie ausscheiden;
> ein deaktiviertes Konto ohne genutzte Runden wird 15 Monate nach der letzten Anmeldung automatisch gelöscht.
>
> **Müssen Sie das angeben?** Ohne Benutzername und Passwort gibt es kein Konto; `[PFLICHT ZUR NUTZUNG, FALLS VORHANDEN]`.
>
> **Ihre Rechte:** Auskunft, Berichtigung, Löschung, Einschränkung, Widerspruch (bei Art. 6 Abs. 1 lit. e oder f),
> `[PRIVAT BETRIEBEN: DATENÜBERTRAGBARKEIT]` und Beschwerde bei `[AUFSICHTSBEHÖRDE]`.

## Datenpannen (Art. 33, 34 DSGVO) {#data-breach}

Eine Datenpanne (Verletzung des Schutzes personenbezogener Daten) ist jede Verletzung der Vertraulichkeit, Integrität oder Verfügbarkeit personenbezogener Daten: ein
Admin-Konto in falschen Händen, ein verlorenes Backup, eine E-Mail mit Änderungslinks an die falsche Person, eine für
andere lesbare Datenbank.

1. **Eindämmen:** Gehen Sie vor wie unter
   [Bei Verdacht auf Missbrauch]({{ '/de/guide/security/' | relative_url }}#bei-verdacht-auf-missbrauch)
   (Sitzungen beenden, Konten prüfen, Passwörter und Secrets austauschen).
2. **Sofort intern melden**, entlang dieser Kette: `[ADMIN / TECHNISCHER KONTAKT]` → `[SCHULLEITUNG / BETREIBER]` →
   `[DATENSCHUTZBEAUFTRAGTE]`, Vertretung `[NAME]`. Ein Auftragsverarbeiter (Hoster, Mailanbieter, wer den Server für
   die Schule betreibt) muss die Verantwortliche unverzüglich informieren (Art. 33 Abs. 2). Privat betrieben melden Sie für
   die Terminvergabe (Datenbank, ihre E-Mails, Ihre Backups) und informieren die betroffenen Schulen; eine verlorene
   Terminliste oder eine offengelegte Zusammenfassung im Postfach einer Lehrkraft meldet die Schule.
3. **Risiko für die Betroffenen einschätzen:** Welche Daten, wie viele Personen, wer kann sie gesehen haben, lässt
   es sich rückgängig machen?
4. **Innerhalb von 72 Stunden** nach Bekanntwerden (Wochenenden und Feiertage zählen mit) an die Aufsichtsbehörde melden, außer die Panne führt
   voraussichtlich nicht zu einem Risiko: `[AUFSICHTSBEHÖRDE, ONLINE-FORMULAR]`. An einer Schule: `[WER MELDET:
   SCHULLEITUNG / ÜBER DEN SCHULTRÄGER ODER DIE SCHULAUFSICHT]`. Spätere oder schrittweise Meldungen sind mit Begründung zulässig.
5. **Betroffene informieren**, wenn das Risiko hoch ist (Art. 34): in einfachen Worten, was geschehen ist und was
   sie tun können.
6. **Jede Panne dokumentieren**, auch nicht gemeldete (Art. 33 Abs. 5):

| Feld | Eintrag |
|---|---|
| Datum und Uhrzeit der Feststellung, durch wen | |
| Was ist geschehen, seit wann | |
| Betroffene Daten und Personen (Anzahl) | |
| Folgen für sie | |
| Getroffene Maßnahmen | |
| Meldung an die Behörde (Datum, Aktenzeichen) oder warum nicht | |
| Information der Betroffenen (Datum, wie) oder warum nicht | |

## Betroffenenanfragen {#requests-of-data-subjects}

Antworten Sie unverzüglich, spätestens innerhalb eines Monats (Art. 12 Abs. 3). Buchungen bestehen nur bis zum Tag nach dem Termin; die meisten
Anfragen betreffen also Daten, die schon gelöscht sind, oder eine noch anstehende Buchung.

- **Identität:** Der persönliche Änderungslink ist der Schlüssel zu einer Buchung. Geben Sie Buchungsdaten nicht an
  jemanden heraus, der nur den Namen eines Kindes nennt; bitten Sie um den Link oder antworten Sie persönlich.
- **Auskunft (Art. 15):** Die Lehrkraft oder ein Admin sieht die Buchung in der Runde (Name, E-Mail-Adresse, Wunsch nach Dolmetschung, Termin,
  Sprache). Prüfen Sie auch Ihre Backups (`[BACKUP-FRIST]`). Zusammenfassungen im Postfach der Lehrkraft: von der Schule
  betrieben ebenfalls prüfen; privat betrieben verweisen Sie an die Lehrkraft oder die Schule.
- **Berichtigung (Art. 16):** Die Lehrkraft bearbeitet den Eintrag; Eltern können bis zur Änderungsfrist selbst
  umbuchen oder absagen.
- **Löschung (Art. 17):** Die Lehrkraft löscht den Eintrag (Eltern können mit ihrem Link absagen); die Buchung ist
  sofort weg, Backups folgen mit ihrer Frist. Zusammenfassungen löscht die Lehrkraft (privat betrieben: an die Lehrkraft
  oder die Schule verweisen).
- **Einschränkung (Art. 18):** erledigt sich meist durch die kurze Frist; sonst beim Eintrag vermerken und nicht nutzen.
- **Datenübertragbarkeit (Art. 20):** nur privat betrieben (Grundlage Art. 6 Abs. 1 lit. b): Name, E-Mail-Adresse und Termin in einem gängigen Format.
- **Widerspruch (Art. 21, bei Art. 6 Abs. 1 lit. e oder f) und keine Erinnerungen gewünscht:** Praktisch entfernt die
  Lehrkraft die E-Mail-Adresse aus dem Eintrag oder löscht ihn.
- **Anfragen von Lehrkräften:** Die Admins sehen das Konto unter *Benutzer*; das Protokoll nennt Änderungen daran.
- Halten Sie jede Anfrage und Antwort fest: `[WO]`.

## Backups und Fristen {#backups-and-retention}

Halten Sie zwei Arten von Backups mit fester Frist vor, verschlüsselt, und testen Sie `[EINMAL IM JAHR]` eine
Wiederherstellung:

- **Konfigurations-Backup** (`config/config.php` und die Datenbank ohne die Tabellen mit Daten von Eltern; es enthält
  die Konten der Lehrkräfte): darf `[Z. B. 12 MONATE]` aufbewahrt werden.
- **Voll-Backup:** höchstens `[14]` Tage aufbewahrt, dann überschrieben.

Die Befehle und die Wiederherstellung stehen unter [Betrieb]({{ '/de/guide/operations/' | relative_url }}#backups). Fragen
Sie Ihren Hoster, wie lange seine eigenen Backups Datenbank und Dateien aufbewahren, und tragen Sie beide Fristen
in das Verzeichnis oben ein. Die Vorlage der Datenschutzerklärung sagt noch nichts zu Backups: Ergänzen Sie in Abschnitt 5
einen Punkt, z. B. „Sicherungskopien: Ihre Angaben können bis zu `[TAGE]` Tage in Backups verbleiben; sie werden dort nicht
genutzt und anschließend überschrieben.“

## Logs und Statistik beim Hoster {#web-host-logs-and-statistics}

TalkSlot führt keine Zugriffsprotokolle; die des Webservers liegen beim Hoster. Sie enthalten die Änderungslinks der
Eltern (`/b/…`) und die Adressen der Runden, deshalb:

- Fragen Sie den Hoster, wie lange Zugriffs- und Fehler-Logs aufbewahrt werden; wählen Sie die kürzeste Option,
  möglichst mit gekürzten IP-Adressen, und tragen Sie sie in die Datenschutzerklärung ein (Platzhalter
  *[SPEICHERDAUER BEIM HOSTER ERFRAGEN UND HIER ANGEBEN.]*).
- Schalten Sie Besucherstatistiken des Hosting-Pakets (z. B. Webalizer, AWStats) für die Domain von TalkSlot ab,
  oder stellen Sie sicher, dass sie weder vollständige IP-Adressen speichern noch die aufgerufenen Adressen anderen zeigen.

## Datenbankrechte {#database-privileges}

Zur Laufzeit braucht TalkSlot `SELECT`, `INSERT`, `UPDATE` und `DELETE` auf seine Tabellen. Die Installation und die
Datenbank-Migrationen, die nach einem Update automatisch beim ersten Aufruf laufen, brauchen zusätzlich `CREATE`,
`ALTER`, `INDEX`, `REFERENCES` und `DROP`. Auf einem eigenen Server oder einer verwalteten Datenbank vergeben Sie
genau diese Rechte auf die Datenbank von TalkSlot. Beim Shared Hosting gibt es meist nur einen Benutzer mit allen
Rechten; geben Sie ihm eine eigene Datenbank (oder wenigstens ein eigenes Tabellenpräfix) und ein Passwort, das
nirgends sonst genutzt wird.

## E-Mail-Versand

- Die Vorlage der Datenschutzerklärung sagt, dass E-Mails über den Mailserver des Hosters laufen. Nutzen Sie einen
  anderen Anbieter (z. B. einen Maildienst der Schule oder Microsoft 365), brauchen Sie mit ihm einen Vertrag zur
  Auftragsverarbeitung, und Abschnitt 6 der Datenschutzerklärung muss ihn nennen (auch eine Übermittlung außerhalb von
  EU/EWR mit ihrer Garantie).
- Mit `mail.transport` `smtp` nutzen Sie Transportverschlüsselung zum Mailserver (`mail.smtp.encryption` `tls` oder `ssl`)
  und einen Anbieter, der mit TLS zustellt; bei PHP `mail()` (Standard) fragen Sie Ihren Hoster, wie er versendet. E-Mails an Eltern enthalten Namen, Lehrkraft, Termin und den persönlichen Link.

## Offboarding {#offboarding}

Wenn eine Lehrkraft oder ein Admin ausscheidet:

1. **Runden:** Ein Admin gibt weiterlaufende Runden an eine andere Lehrkraft (*Runde bearbeiten → Lehrkraft*) oder
   löscht sie (Eltern können per E-Mail informiert werden).
2. **Konto:** Deaktivieren Sie es unter *Benutzer* (es kann sich nicht mehr anmelden; deaktivierte Konten ohne genutzte
   Runden werden 15 Monate nach der letzten Anmeldung gelöscht), oder löschen Sie es.
3. **Admins:** Entziehen Sie vorher die Admin-Rolle; hatten sie Zugang zu Server, FTP, GitHub oder `config.php`,
   [tauschen Sie die Secrets aus]({{ '/de/guide/operations/' | relative_url }}#changing-secrets).
4. **Was außerhalb von TalkSlot bleibt:** Zusammenfassungen, Ausdrucke und lokale Backup-Kopien werden gelöscht oder
   übergeben.
5. Streichen Sie die Person aus der Admin-Liste in Ihren Unterlagen.

Endet der Betrieb ganz: Daten übergeben oder löschen, Datenbank, Dateien und Backups löschen und die Verträge mit den
Auftragsverarbeitern beenden.

Das Dashboard nennt aktive Konten, an denen sich lange niemand angemeldet hat; prüfen Sie sie `[JEDES HALBJAHR]`.
