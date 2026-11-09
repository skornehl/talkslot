---
title: Datenschutz und Recht
description: Wie TalkSlot mit personenbezogenen Daten umgeht und was Sie vor dem Start ausfüllen müssen.
---

TalkSlot wurde für Schulen in Deutschland entwickelt und richtet sich daher eng nach der DSGVO und dem
TDDDG. Diese Seite erläutert die Designentscheidungen; sie ist keine Rechtsberatung.

## Was gespeichert wird

| Daten | Wo | Gelöscht |
|---|---|---|
| Name und optionale E-Mail-Adresse einer Buchung | Datenbank | ab 0:00 Uhr am Tag nach dem Termin, bei einer Absage sofort |
| Hash des persönlichen Änderungslinks (und der Links in der Erinnerung und in einer später verschickten Bestätigung) | Datenbank | mit der Buchung |
| Eintrag der Mail-Warteschlange: welche E-Mail für welche Buchung oder welches Konto aussteht (ohne Adresse, Text oder Link) | Datenbank | nach dem Versand, spätestens nach 48 Stunden, mit der Buchung |
| Absage durch die Lehrkraft in der Mail-Warteschlange: Adresse, Sprache, Name, Termin und Lehrkraft (Name und Adresse für Antworten; keine Adresse, wenn das Konto gelöscht wurde) der gelöschten Buchung (ohne Link) | Datenbank | nach dem Versand, spätestens nach 48 Stunden |
| Gekürzte, per HMAC gehashte IP-Adressen; HMAC-Hashes von Konto-IDs und Geräte-Cookies mit fehlgeschlagenen Anmeldungen | Datenbank (Rate Limiting) | spätestens nach 24 Stunden |
| Geräte-Cookie eines Admin- oder Lehrkraft-Kontos (Zufallszahl, Zeitpunkt, Signatur) | Browser der Person | nach 365 Tagen, bei jeder Anmeldung erneuert; ungültig nach einem neuen Passwort oder wenn alle Sitzungen beendet werden; höchstens 20 pro Browser |
| Konten der Lehrkräfte | Datenbank | durch einen Admin; inaktive Konten ohne Runden in Gebrauch 15 Monate nach der letzten Anmeldung, zusammen mit ihren Entwürfen |
| Zusammenfassungen per E-Mail an Lehrkräfte (standardmäßig die Zahl der Termine und der Link, keine Namen; Uhrzeit und Name nur, wo die Schule sie erlaubt; nie E-Mail-Adressen) | Postfach der Lehrkraft | mit Namen: spätestens zum Ende des Schuljahres (Vorlage der Datenschutzerklärung; bei privatem Betrieb empfiehlt die Vorlage das, es gelten die Regeln der Schule) |
| Protokoll der Änderungen durch Admins (wer, wann, was; keine Daten von Eltern) | Datenbank | nach 90 Tagen (`audit_log_days`) |
| Sicherheitsereignisse eines Kontos (Passwort, E-Mail-Adresse, Admin-Rechte, angeforderte Links zum Zurücksetzen, Anmeldelimit; Zeitpunkt, ob ein Admin die Änderung vorgenommen hat; keine IP-Adresse, kein Gerät), nur für den Kontoinhaber sichtbar | Datenbank | nach 30 Tagen (`security_events_days`) und mit dem Konto |

TalkSlot führt **keine** eigenen Zugriffsprotokolle. Für die Logs des Webservers ist der Hoster
zuständig; fragen Sie nach, wie lange sie aufbewahrt werden, und nennen Sie die Dauer in der
Datenschutzerklärung.

## Designentscheidungen

- **Datenminimierung:** ein frei eingegebener Name und eine optionale E-Mail-Adresse, sonst nichts.
- **E-Mail-Adresse nur für den Termin:** Bestätigung, Änderung, Absage und, wenn die Lehrkraft sie für die
  Runde vorgesehen hat, eine Erinnerung am Vorabend. Die Vorlage der Datenschutzerklärung nennt all das.
- **Mail-Warteschlange nur mit Verweisen:** Erinnerungen, Zusammenfassungen und E-Mails, die der Mailserver
  abgelehnt hat, warten in einer Warteschlange, die der Cronjob verschickt. Ein Eintrag enthält weder
  Adresse noch Text noch Link: Sie entstehen erst beim Versand aus der Buchung, eine später verschickte
  Bestätigung mit einem eigenen, neuen Link (gespeichert wird nur sein Hash, wie bei der Erinnerung).
  Einträge werden nach dem Versand gelöscht, spätestens nach 48 Stunden und mit ihrer Buchung; die Vorlage
  der Datenschutzerklärung nennt das. Einzige Ausnahme ist die Absage durch die Lehrkraft: Die Buchung ist
  dann schon gelöscht, deshalb behält der Eintrag, was genau diese E-Mail braucht (Adresse, Sprache, Name,
  Termin, Lehrkraft; keinen Link, keinen Token, keine Beschreibung), bis sie verschickt ist, höchstens
  48 Stunden. Auch das nennt die Vorlage.
- **Zusammenfassungen ohne Namen:** Die E-Mails an Lehrkräfte bleiben in deren Postfächern, womöglich
  privaten bei einem Anbieter außerhalb der EU, und in daraus synchronisierten Kalendern; TalkSlot kann sie
  dort nicht löschen, und Auskunfts- oder Löschanfragen hießen, Postfächer zu durchsuchen. Standardmäßig
  nennen sie deshalb je Tag nur die Zahl der Termine, Auffälliges (damit jemand, der viele Termine bucht,
  weiterhin auffällt) und den Link zur Liste im Adminbereich. Namen und eine Kalenderdatei, deren Einträge
  statt Namen die Runde tragen, nur, wo die Schule sie erlaubt (`summary_names`) und das Konto sie ankreuzt;
  neue Konten beginnen ohne. `allowed_account_domains` hält die Adressen der Konten und damit
  Zusammenfassungen und Antworten der Eltern in den Postfächern der Schule. Lehrkräfte können statt der
  Adresse ihres Kontos eine eigene Antwortadresse für Eltern angeben.
- **Erinnerungen nur, wo Eltern davon wissen:** Wer selbst bucht, bekommt die Erinnerung, wenn sie für die
  Runde beim Buchen eingeschaltet war (das Formular sagt es). Einträge der Lehrkraft bekommen sie erst, wenn
  eine Bestätigung oder Änderungsmitteilung bei den Eltern angekommen ist, denn diese E-Mail informiert über
  den Eintrag und die Datenschutzhinweise. Ein späteres Einschalten betrifft frühere Buchungen nicht.
- **Bestätigung nachträglich per E-Mail:** Eltern ohne Adresse bei der Buchung können sich die Bestätigung
  auf der Seite ihres Termins an eine dort eingegebene Adresse schicken lassen. Diese Adresse dient nur
  dieser einen E-Mail und wird nicht gespeichert; die Fußzeile der E-Mail sagt das. Höchstens zweimal pro
  Buchung innerhalb von 24 Stunden (Rate-Limit `confirm`, gezählt pro Buchung), damit ein Änderungslink
  die Absenderadresse der Schule nicht zum Mail-Relay macht.
- **Einträge durch die Lehrkraft:** Die Bestätigungs-E-Mail mit Änderungslink ist standardmäßig angehakt,
  sobald eine Adresse eingetragen ist. So erfahren Eltern, die das Buchungsformular nie gesehen haben, von
  dem Eintrag, wer verantwortlich ist und wo die Datenschutzhinweise stehen (Art. 14 DSGVO).
- **Änderungen durch die Lehrkraft:** Ändert oder verschiebt die Lehrkraft eine einzelne Buchung, geht
  standardmäßig eine Änderungs-E-Mail mit den aktuellen Termindaten an die Eltern (sofern eine Adresse
  hinterlegt ist; das Häkchen lässt sich herausnehmen). Beim Löschen einer Buchung, eines Tages, einer
  Runde oder eines Kontos geht eine Absage hinaus, bei einem neuen Datum, einer neuen Uhrzeit oder einem
  neuen Ort eines Tages und bei einem neuen Ort oder einer neuen Lehrkraft der Runde eine
  Änderungsmitteilung, jeweils in der Sprache der Eltern und mit standardmäßig angehaktem *Eltern per
  E-Mail informieren*. Das ist der Zweck, für den die Adresse angegeben wurde (Absage und Änderung stehen
  in der Datenschutzerklärung). E-Mails gehen nur an Eltern, die selbst gebucht haben, oder deren Eintrag
  durch die Lehrkraft schon eine Bestätigung oder Änderungsmitteilung erhalten hat; Eltern, die nie eine
  E-Mail von TalkSlot bekommen haben, erhalten auch keine Absage (die Regel der Erinnerung). Eine neue
  Adresse, die die Lehrkraft bei einer Buchung einträgt, gilt als unbekannt, bis eine solche E-Mail aus dem
  Eintrag sie erreicht hat, und Änderungsmitteilungen nach der Änderung eines Tages oder einer Runde sind
  pro Konto und Stunde begrenzt (`rate_limits.teacher_bulk`, wie die 30 E-Mails pro Stunde aus Einträgen).
  Das verhindert zugleich, dass ein Konto über selbst angelegte Einträge mit beliebigen Adressen oder
  geänderte Adressen von Buchungen und das anschließende Ändern oder Löschen eines Tages E-Mails an
  beliebige Empfänger schickt. Der Audit-Eintrag nennt nur die Zahl der benachrichtigten
  Eltern. Auf einen `mailto:`-Link an die Eltern wurde bewusst verzichtet, damit ihre Adressen nicht über
  das Mailprogramm des Browsers in private Postfächer gelangen. Wer keine E-Mail bekommt, wird in der
  Meldung danach einmal mit Termin und gegebenenfalls Adresse genannt (beides stand vorher auf der Seite
  der Runde), damit die Lehrkraft ihn persönlich informieren kann, am besten aus dem dienstlichen Postfach.
- **Protokoll ohne Kontrolle der Lehrkräfte:** Erfasst werden nur Änderungen durch Admin-Konten, damit bei
  mehreren Admins nachvollziehbar ist, wer was getan hat. Was Lehrkräfte in ihren eigenen Runden tun,
  wird nicht aufgezeichnet, Buchungen erscheinen nur mit Datum und Uhrzeit. Je nach Bundesland kann ein
  Protokoll von Handlungen der Beschäftigten trotzdem den Personalrat betreffen; die Aufbewahrung lässt
  sich mit `audit_log_days` verkürzen.
- **Sicherheitsereignisse nur für den Kontoinhaber:** Nach einem Vorfall kann eine Lehrkraft sehen, was mit
  ihrem Konto geschehen ist (*Mein Profil → Sicherheit*), ohne dass eine Einrichtung zur Überwachung entsteht:
  keine Anmeldungen, keine IP-Adresse, nichts für Admins in der Anwendung sichtbar (nur für alle, die die Datenbank
  oder Backups lesen können), fehlgeschlagene Anmeldungen nur als Zahl. Beteiligen Sie vor der Einführung den
  Personalrat und stimmen Sie die Aufbewahrung ab (standardmäßig 30 Tage, `security_events_days`, höchstens 90).
- **Anmeldezeiten nur grob:** Der Zeitpunkt der letzten Anmeldung wird gespeichert (um ungenutzte Konten
  aufzuräumen), die Benutzerliste zeigt aber nur *nie*, *in den letzten 12 Monaten* oder *vor über 12
  Monaten*. Admins sehen also nicht, wann Kolleginnen und Kollegen arbeiten. Sicherheitsmails zu
  Kontoänderungen nennen das betroffene Konto und wer die Änderung gemacht hat, nie Daten von Eltern.
- **Keine Cookies auf den Elternseiten.** Die Sprache steht in der URL (`?lang=`), der CSRF-Schutz
  arbeitet zustandslos. Ein Cookie-Banner ist nicht nötig. Nur der Admin-Bereich verwendet Cookies: das
  Sitzungs-Cookie, beschränkt auf `/admin`, und nach der Anmeldung ein Geräte-Cookie, beschränkt auf
  `/admin/login`. Es verhindert, dass Fremde eine Lehrkraft mit falschen Passwörtern von ihren eigenen
  Geräten aussperren (siehe [Sicherheit]({{ '/de/guide/security/' | relative_url }})). Beide sind für die
  von der Lehrkraft gewünschte Anmeldung unbedingt erforderlich und brauchen daher keine Einwilligung
  (§ 25 Abs. 2 Nr. 2 TDDDG); nennen Sie sie in den Informationen für Lehrkräfte.
  Die Browsersprache (`Accept-Language`, die jeder Browser ohnehin sendet) entscheidet nur, ob oben ein
  Link zu dieser Sprache erscheint; die Seite wechselt nie von selbst, und es wird nichts gespeichert.
- **Keine Drittanbieter:** keine CDNs, Webfonts, Analysetools oder externen Skripte. Übersetzungen des
  Titels einer Runde entstehen im Browser der Lehrkraft mit einem lokalen Modell; es wird kein Text an
  einen Übersetzungsdienst gesendet.
- **Keine Seitenübersetzung von Buchungslisten:** Admin-Bereich und Druckansicht tragen
  `translate="no"`, `class="notranslate"` und `<meta name="google" content="notranslate">`. Browser bieten
  dort deshalb ihre Seitenübersetzung nicht an, die Namen und E-Mail-Adressen an einen Cloud-Dienst
  schicken würde (eine Übermittlung in ein Drittland, die die Datenschutzerklärung ausschließt). Die
  Elternseiten bleiben bewusst übersetzbar: Eltern brauchen womöglich eine andere als die sechs
  angebotenen Sprachen und sehen dort nur ihre eigenen Daten.
- **Auf diesem Gerät merken** (§ 25 TDDDG): Etwas ohne Einwilligung im Browser zu speichern, ist nur
  erlaubt, wenn es für einen vom Nutzer ausdrücklich gewünschten Dienst unbedingt erforderlich ist.
  Doppelbuchungen zu verhindern, liegt im Interesse der Lehrkraft, nicht der Eltern, und würde diese
  Voraussetzung daher nicht erfüllen. Deshalb ist die Funktion „Meinen Termin auf diesem Gerät merken“ ein **nicht
  vorausgewähltes Kontrollkästchen**. Nur wenn es angehakt ist, speichert der Browser Link, Bezeichnung und
  Datum des Termins im `localStorage`, einen Eintrag pro Buchung (Geschwister). An den Server wird nichts
  übertragen. Einträge verschwinden nach einer Absage, wenn ihr Link keine Buchung mehr findet, und beim
  ersten Seitenaufruf nach dem Tag des Termins – dem Tag, an dem auch die Buchung gelöscht wird.
- **Änderungslink aufbewahren:** Die Seite des Termins bietet *Link kopieren* und *In Kalender eintragen*
  (die Kalenderdatei kommt als Antwort auf einen POST-Button, es gibt also keine zweite Adresse mit dem
  Token, die Browser oder Kalender von sich aus abrufen könnten) und, wo das Gerät es kann, *Teilen …* mit
  dem Hinweis, dass jeder mit dem Link den Termin ändern kann.
- **Hell/Dunkel-Auswahl:** wird nur gespeichert, wenn ausdrücklich gewählt (`light` oder `dark`); für
  diese Funktion unbedingt erforderlich, daher ist keine Einwilligung nötig. „Automatisch“ entfernt den
  Eintrag.
- **Buchungsbeschränkungen** vergleichen nur mit Buchungen derselben Runde und nutzen Daten, die ohnehin
  gespeichert sind. Die Ablehnung verrät nicht, wann oder von wem ein Termin gebucht wurde.
- **Kenntnisnahme statt Einwilligung:** Die Datenschutzhinweise müssen nach der DSGVO nicht „akzeptiert“
  werden; sie informieren (Art. 13). Das Pflicht-Häkchen bestätigt daher, dass man die Hinweise *gelesen*
  hat, und die Hinweise stellen klar, dass es sich nicht um eine Einwilligung handelt.

## Vorlagen für die Rechtstexte

Vorlagen für Impressum und Datenschutzerklärung auf Deutsch und Englisch werden bei der Installation in
die Datenbank kopiert und können von Admins unter *Texte* bearbeitet werden. Die Vorlage für die
Datenschutzerklärung deckt die nach Art. 13 DSGVO erforderlichen Informationen ab: Verantwortlicher,
Datenschutzbeauftragte, Zwecke, Rechtsgrundlagen, Empfänger, Drittländer, Speicherdauer, Betroffenenrechte
einschließlich des Widerspruchsrechts, Beschwerderecht bei einer Aufsichtsbehörde, ob die Bereitstellung
der Daten erforderlich ist, sowie automatisierte Entscheidungsfindung.

### Von der Schule oder privat betrieben

TalkSlot lässt sich an einer Schule nutzen, ohne dass die Schule es betreibt. Wer es betreibt, entscheidet,
wer Verantwortlicher ist, welche Rechtsgrundlagen gelten und was im Impressum steht. Der Installer fragt
deshalb die **Betriebsart** ab; Admins können sie später unter *Texte → Angaben für Impressum und
Datenschutz* ändern.

| Betriebsart | Verantwortlich | Vorlage der Datenschutzerklärung | Vorlage des Impressums |
|---|---|---|---|
| *Wird von der Schule betrieben*: eine staatliche Schule als öffentliche Stelle | die Schule | Art. 6 Abs. 1 lit. e, Abs. 3 DSGVO in Verbindung mit dem Schulgesetz Ihres Landes (Buchung, E-Mails, Zusammenfassungen) und dem Landesdatenschutzgesetz (Missbrauchsschutz, Server-Protokolle); Datenschutzbeauftragte der Schule; Widerspruchsrecht nach Art. 21, kein Recht auf Datenübertragbarkeit (Art. 20 Abs. 3) | die Schule als Anbieter |
| *Wird privat betrieben*: eine Privatperson, ein Verein oder ein freier Träger (z. B. eine Schule in freier Trägerschaft) | der Betreiber, für die Terminvergabe; das Gespräch selbst, die Zusammenfassungen im Postfach der Lehrkraft und Ausdrucke gehören zur Tätigkeit der Lehrkraft an ihrer Schule (verantwortlich ist in der Regel die Schule, es gelten deren Vorgaben) | Art. 6 Abs. 1 lit. b und f DSGVO; Widerspruchsrecht für lit. f, Recht auf Datenübertragbarkeit; bei Privatpersonen in der Regel keine Datenschutzbeauftragte, mit einem Platzhalter, um das zu prüfen | ein privates, nicht kommerzielles Angebot (wie bis v1.5) |

Behörden können sich bei der Erfüllung ihrer Aufgaben nicht auf berechtigte Interessen stützen (Art. 6
Abs. 1 Unterabs. 2 DSGVO), und Gespräche an einer staatlichen Schule gehören zu ihren gesetzlichen Aufgaben,
nicht zu einem Vertrag. Deshalb nennt die Vorlage für Schulen das Landesrecht. Welche Vorschriften gelten,
ist von Land zu Land verschieden; die Vorlage lässt sie deshalb als Platzhalter offen.

**Die Vorlagen folgen der Betriebsart.** Der Installer speichert die Vorlagen der gewählten Betriebsart.
Ändert ein Admin die Betriebsart, wird jeder Text, der noch unverändert einer Vorlage entspricht (beider
Betriebsarten, aktuell oder früher), durch die Vorlage der neuen Betriebsart ersetzt, und die Meldung nennt
die Anzahl; bearbeitete Texte werden nie ersetzt. Das Protokoll hält fest, wer die Betriebsart wann
festgelegt hat (Rechenschaftspflicht, Art. 5 Abs. 2 DSGVO): jede Änderung durch einen Admin mit der Zahl der
ersetzten Texte, eine beim Update übernommene Betriebsart (eingetragen als *TalkSlot*) und den Admin, der sie
danach bestätigt hat, sowie Texte, die bei einem Update durch neuere Vorlagen ersetzt wurden. Im Formular
eines Textes bietet *Vorlage laden* die
Vorlagen beider Betriebsarten an, die zur Betriebsart passende zuerst; den gespeicherten Text ersetzt sie
erst beim Speichern. Hinweise gleichen die Texte mit der Betriebsart ab:

- **Von der Schule betrieben:** ein Hinweis, wenn ein Text noch der alten Vorlage folgt („privates Angebot“
  ohne Datenschutzbeauftragte, Absagen „jederzeit“) oder sich auf Art. 6 Abs. 1 lit. b oder f DSGVO stützt.
- **Privat betrieben:** ein Hinweis, wenn ein Text die Vorlage für Schulen enthält (Art. 6 Abs. 1 lit. e
  DSGVO, Schulgesetz, Datenschutzbeauftragte der Schule) oder noch sagt, Eltern könnten „jederzeit“ absagen.

**Installationen aus der Zeit vor der Betriebsart** erhalten sie beim ersten Aufruf nach dem Update:

- Entsprechen alle Texte noch unverändert den Vorlagen bis v1.6, die ein privates Angebot beschrieben,
  wird die Betriebsart *privat betrieben* (die bisherige Bedeutung) und die Datenschutzerklärung die
  aktuelle Vorlage dafür. Das Dashboard weist darauf hin, bis ein Admin die Angaben einmal gespeichert hat.
- Sonst (bearbeitete Texte oder die unveränderten Vorlagen für Schulen, die v1.7.0 eingesetzt hat) wird
  keine Betriebsart gesetzt: Admins sehen im Dashboard und unter *Texte* „Bitte Betriebsart wählen“.
  **Bis einer von ihnen wählt, lässt sich keine Runde aktivieren**; bereits aktive Runden bleiben
  erreichbar, und Lehrkräfte sehen, dass die Admins noch wählen müssen. Wer *privat betrieben* wählt,
  bekommt die von v1.7.0 eingesetzten Vorlagen für Schulen wieder als private Vorlagen zurück, solange
  niemand sie bearbeitet hat.

Vor dem Start:

1. **Wer verantwortlich ist**, je nach Betriebsart:
   - **Von der Schule betrieben:** Die Schule ist Verantwortliche, und die Schulleitung beschließt den
     Einsatz. Die Vorlage hat einen Pflicht-Platzhalter für die oder den Datenschutzbeauftragten der Schule.
     Betreibt jemand anderes den Server für die Schule (z. B. eine ehrenamtliche Person mit eigenem
     Hostingvertrag), braucht die Schule mit dieser Person eine Vereinbarung nach Art. 28 DSGVO (Weisungen,
     Vertraulichkeit, Hoster als Unterauftragsverarbeiter, Übergabe aller Zugänge, Löschung bei
     Betriebsende), oder die Schule bzw. der Schulträger schließt den Hostingvertrag selbst ab.
   - **Privat betrieben:** Der Betreiber (Sie selbst, Ihr Verein oder Ihr Träger) ist Verantwortlicher für die
     Terminvergabe einschließlich der E-Mails, die sie verschickt; Lehrkräfte nutzen das Angebot für ihre
     Elterngespräche. Das Gespräch selbst, Notizen, die Zusammenfassungen im Postfach der Lehrkraft und
     Ausdrucke gehören zur Arbeit der Lehrkraft an ihrer Schule: Verantwortlich ist dafür in der Regel die
     Schule, es gelten deren Regeln (die Zusammenfassungen enthalten keine Namen, solange Namen nicht erlaubt
     sind; die Vorlage empfiehlt, solche mit Namen bis zum Ende des Schuljahres zu löschen). Lehrkräfte
     sollten für ihr Konto die dienstliche E-Mail-Adresse angeben; sonst landen die Zusammenfassungen in einem
     privaten Postfach, eventuell bei einem Anbieter außerhalb der EU (`allowed_account_domains` kann die
     Domain der Schule vorschreiben). Schulen
     können eigene Vorgaben haben, welche Dienste ihre Lehrkräfte nutzen dürfen; Lehrkräfte sollten das mit
     ihrer Schulleitung oder der oder dem Datenschutzbeauftragten der Schule klären. Bestimmt die Schule über
     die Terminvergabe selbst mit – etwa weil sie sie als ihr Werkzeug für Elterngespräche einführt, ihren
     Lehrkräften die Nutzung vorschreibt oder Einstellungen, Löschfristen oder Admin-Konten vorgibt –, passt
     die private Vorlage nicht mehr: Nötig ist dann die Betriebsart *von der Schule betrieben* mit einer
     Vereinbarung nach Art. 28 DSGVO oder eine Vereinbarung nach Art. 26 DSGVO (dann sind die Texte
     anzupassen, Art. 26 Abs. 2 DSGVO). Regeln der Schule nur für ihre Lehrkräfte, etwa die Freigabe des
     Dienstes, die dienstliche E-Mail-Adresse oder Löschfristen für das Postfach, ändern daran nichts. Nutzt
     eine Lehrkraft das Angebot ohne Freigabe der Schule, kann sie selbst Verantwortliche werden. Ist für den
     Betreiber eine Datenschutzbeauftragte bzw. ein Datenschutzbeauftragter benannt, nennen Sie sie bzw. ihn
     in der Datenschutzerklärung statt des Satzes, dass niemand benannt ist. Die Vorlage des Impressums ist
     auf eine Privatperson zugeschnitten: Ein Verein oder freier Träger ergänzt die vertretungsberechtigte
     Person und, falls vorhanden, den Registereintrag (§ 5 DDG).
2. **Verantwortlicher:** Füllen Sie *Texte → Angaben für Impressum und Datenschutz* aus. Diese
   Angaben ersetzen `[[VERANTWORTLICHE]]`; ohne sie ist keine Runde öffentlich.
3. **Hoster:** Setzen Sie `hosting` in der Konfiguration (bzw. `HOSTING_DE`/`HOSTING_EN` beim Deployment).
   Der Wert ersetzt `[[HOSTER]]`. Ist er nicht gesetzt, zeigt die Seite die Platzhalter
   `[HOSTER MIT ANSCHRIFT]` und `[LAND]` (englisch `[WEB HOST WITH ADDRESS]`, `[COUNTRY]`), und Admins
   erhalten einen Hinweis.
4. **Verbleibende Platzhalter** in eckigen Klammern, in jeder Sprache, für die es einen Text gibt: die oder
   der Datenschutzbeauftragte (Kontaktdaten; bei privatem Betrieb die Prüfung, ob jemand benannt ist), bei
   einer Schule die Vorschriften Ihres Landes (Schulgesetz, Landesdatenschutzgesetz), die Speicherdauer der
   Webserver-Logs (fragen Sie Ihren Hoster) und die zuständige Aufsichtsbehörde. An einer Schule nennt
   Ihnen die oder der Datenschutzbeauftragte die Vorschriften.
5. **Auftragsverarbeitungsvertrag** (AVV) mit Ihrem Hoster.
6. **Admin-Konten** nur für Personal des Betreibers (an einer Schule: Personal der Schule) oder für
   schriftlich zur Vertraulichkeit verpflichtete Personen: Die Datenschutzerklärung sagt Eltern zu, dass
   außer der Lehrkraft nur solche Personen ihre Buchungen sehen.

### Keine Aktivierung bei offenen Platzhaltern

TalkSlot prüft Impressum und Datenschutzerklärung aller angebotenen Sprachen so, wie Eltern sie sehen,
also mit eingesetzten Angaben zur verantwortlichen Stelle und zum Hoster, in beiden Betriebsarten. Ein
offener Platzhalter ist Text in eckigen Klammern ohne Kleinbuchstaben und mit mindestens vier Zeichen, etwa
`[LAND]`. Ein Markdown-Link wie `[PDF](https://…)` oder Text in gemischter Schreibung wie `[Hinweis]` zählt
nicht. Solange einer übrig ist:

- **Keine Runde lässt sich aktivieren:** weder eine neue aktive Runde noch über *Aktivieren* noch durch
  Zuordnen einer aktiven Runde zu einer anderen Lehrkraft. Die Meldung nennt die betroffenen Texte.
- **Admins** sehen im Dashboard und unter *Texte*, welche Platzhalter in welchem Text und welcher Sprache
  offen sind. **Lehrkräfte** sehen, dass die Admins die Texte noch ausfüllen müssen.
- **Bereits aktive Runden bleiben erreichbar**, und Eltern können ihre Änderungslinks weiter nutzen. Eine
  bestehende Installation geht nach einem Update also nicht offline: Das Dashboard kündigt die offenen
  Platzhalter an, und erst die nächste Aktivierung wartet darauf.

Bei einem Update werden nie bearbeitete Texte durch die neuen Vorlagen der Betriebsart ersetzt (siehe
[Betrieb]({{ '/de/guide/operations/' | relative_url }})), einschließlich neuer Platzhalter.

### Neuere Vorlagen für bearbeitete Texte

Bearbeitete Texte werden nie automatisch ersetzt: Was ein Admin geschrieben hat, bleibt. Damit Korrekturen an
den Vorlagen – z. B. zu Speicherfristen, Empfängern, E-Mails oder Rechtsgrundlagen – trotzdem Installationen
mit ausgefüllten Texten erreichen, merkt sich TalkSlot zu jedem Text einen **Vorlagenstand**: die Vorlage,
aus der er gespeichert wurde (Installation, Wechsel der Betriebsart, *Vorlage laden*) oder mit der er zuletzt
abgeglichen wurde. Kommt mit einem Update eine neuere Vorlage der Betriebsart, sehen Admins im Dashboard und
unter *Texte*, welche Abschnitte sie seitdem geändert hat, und im Formular des Textes jeweils die alte und
die neue Fassung, mit *Abschnitte aus der Vorlage übernehmen*, um sie abschnittsweise zu übernehmen; die
ausgefüllten Angaben bleiben dabei erhalten (siehe [Admins]({{ '/de/guide/admin/' | relative_url }}#rechtstexte)).
Abschnitte, die ein Admin bewusst umformuliert hat, werden nur gemeldet, wenn die Vorlage sie ebenfalls ändert.

- Der Vorlagenstand ist der Wortlaut der Vorlage selbst, gespeichert in der Tabelle `settings`
  (`legal_base.<seite>.<sprache>`, keine personenbezogenen Daten). Ein Text, der genau der Vorlage seines
  Vorlagenstands entspricht, gilt als unbearbeitet und wird durch eine neuere Vorlage ersetzt, auch wenn deren
  Hash in `OLD_PAGE_HASHES` fehlt.
- Texte, die bearbeitet wurden, bevor TalkSlot Vorlagenstände festhielt, bekommen beim ersten Aufruf nach dem
  Update einen, wenn sie den Wortlaut der aktuellen Vorlage haben (nur die Platzhalter ausgefüllt). Sonst nennt
  der Hinweis die Abschnitte, in denen sie von der aktuellen Vorlage abweichen; hat ein Admin den Text
  abgeglichen und das bestätigt, erscheinen nur noch spätere Änderungen der Vorlage.
- Mit Vorlagenstand gilt ein Abschnitt erst als übernommen, wenn keine Zeile mehr im Text steht, die die
  Vorlage seitdem gestrichen hat – auch nicht neben einem Platzhalter oder an Stelle eines neuen Platzhalters –
  und jeder Platzhalter im Text steht, den die Vorlage seitdem hinzugefügt hat, auch einer ohne neuen
  Wortlaut drumherum (z. B. ein Prüfhinweis in eckigen Klammern am Zeilenende oder ein Absatz, der nur aus
  einem Platzhalter besteht): Ein Platzhalter darf auch durch nichts ausgefüllt sein, sonst sähe die frühere
  Zeile aus wie die neue, ausgefüllt. Ohne Vorlagenstand lässt sich nicht unterscheiden, ob eine solche Zeile
  einen Platzhalter ausfüllt oder noch den Wortlaut einer früheren Vorlage hat; bestätigen Sie den Abgleich
  deshalb einmal.
- Beim Übernehmen bleiben die ausgefüllten Angaben erhalten, auch wo sie in den Zeilen unter einem Platzhalter
  am Zeilenende weitergehen (z. B. Name, Anschrift und E-Mail-Adresse der oder des Datenschutzbeauftragten).
  Was der Text in den übernommenen Abschnitten sonst noch hatte (eigene Zeilen, Angaben in einer Zeile, die die
  Vorlage geändert hat), listet das Formular als nicht übernommen auf, damit nichts unbemerkt wegfällt, etwa
  die Kontaktdaten nach Art. 13 Abs. 1 lit. b DSGVO.
- Der Abgleich steht mit dem Namen des Admins im Protokoll (Rechenschaftspflicht, Art. 5 Abs. 2 DSGVO); so
  lässt sich zeigen, wann eine Korrektur der Vorlage übernommen wurde. Das Protokoll wird nach
  `audit_log_days` gelöscht (Standard 90 Tage); wer den Abgleich länger belegen muss, sichert den Eintrag
  vorher, z. B. zusammen mit der gespeicherten Fassung des Textes.
- Vorlagen gibt es auf Deutsch und Englisch. Datenschutzerklärungen in den anderen Sprachen (Polnisch,
  Russisch, Türkisch, Ukrainisch) vergleicht TalkSlot mit keiner Vorlage: Meldet der Hinweis eine Änderung der
  deutschen oder englischen Fassung, gleichen Sie die Übersetzungen bitte selbst ab.

Die Hinweise zur Betriebsart oben bleiben: Sie erklären, warum ein Wortlaut nicht passt, unabhängig vom
Vorlagenstand.

Lassen Sie die Texte von jemandem prüfen, der Ihre Situation kennt, an einer Schule am besten von der oder
dem Datenschutzbeauftragten der Schule; die Vorlagen ersetzen keine Rechtsberatung.
