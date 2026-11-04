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
| Gekürzte, per HMAC gehashte IP-Adressen; HMAC-Hashes von Konto-IDs und Geräte-Cookies mit fehlgeschlagenen Anmeldungen | Datenbank (Rate Limiting) | spätestens nach 24 Stunden |
| Geräte-Cookie eines Admin- oder Lehrkraft-Kontos (Zufallszahl, Zeitpunkt, Signatur) | Browser der Person | nach 365 Tagen, bei jeder Anmeldung erneuert |
| Konten der Lehrkräfte | Datenbank | durch einen Admin; inaktive Konten ohne Runden 15 Monate nach der letzten Anmeldung |
| Zusammenfassungen per E-Mail an Lehrkräfte (Uhrzeit und Name, keine E-Mail-Adressen) | Postfach der Lehrkraft | die Datenschutzerklärung sagt höchstens 15 Monate zu |
| Protokoll der Änderungen durch Admins (wer, wann, was; keine Daten von Eltern) | Datenbank | nach 90 Tagen (`audit_log_days`) |

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
  der Datenschutzerklärung nennt das.
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
  Runde oder eines Kontos, wenn ein Tag ein anderes Datum oder einen anderen Ort bekommt und wenn die
  Runde einen anderen Ort oder eine andere Lehrkraft bekommt, geht noch keine E-Mail an die Eltern;
  Bestätigungsdialoge, Formularhinweise und die Meldung danach sagen das. Auf einen `mailto:`-Link an die
  Eltern wurde bewusst verzichtet, damit ihre Adressen nicht über das Mailprogramm des Browsers in private
  Postfächer gelangen. Nach dem Löschen einer Buchung nennt die Meldung die Eltern einmal mit ihrer
  Adresse (beides stand vorher auf der Seite der Runde), damit die Lehrkraft sie informieren kann, am
  besten aus dem dienstlichen Postfach.
- **Protokoll ohne Kontrolle der Lehrkräfte:** Erfasst werden nur Änderungen durch Admin-Konten, damit bei
  mehreren Admins nachvollziehbar ist, wer was getan hat. Was Lehrkräfte in ihren eigenen Runden tun,
  wird nicht aufgezeichnet, Buchungen erscheinen nur mit Datum und Uhrzeit. Je nach Bundesland kann ein
  Protokoll von Handlungen der Beschäftigten trotzdem den Personalrat betreffen; die Aufbewahrung lässt
  sich mit `audit_log_days` verkürzen.
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

Die Datenschutzerklärung gibt es in zwei Varianten. Im Formular des Textes setzt *Vorlage laden* die
passende ein; den gespeicherten Text ersetzt sie erst beim Speichern.

| Variante | Für | Rechtsgrundlagen |
|---|---|---|
| *Öffentliche Schule* (installiert) | eine staatliche Schule als Verantwortliche | Art. 6 Abs. 1 lit. e, Abs. 3 DSGVO in Verbindung mit dem Schulgesetz Ihres Landes (Buchung, E-Mails, Zusammenfassungen) und dem Landesdatenschutzgesetz (Missbrauchsschutz, Server-Protokolle); Widerspruchsrecht nach Art. 21, kein Recht auf Datenübertragbarkeit (Art. 20 Abs. 3) |
| *Privater Träger* | z. B. Schule in freier Trägerschaft, Förderverein | Art. 6 Abs. 1 lit. b und f DSGVO; Widerspruchsrecht für lit. f, Recht auf Datenübertragbarkeit |

Behörden können sich bei der Erfüllung ihrer Aufgaben nicht auf berechtigte Interessen stützen (Art. 6
Abs. 1 Unterabs. 2 DSGVO), und Gespräche an einer staatlichen Schule gehören zu ihren gesetzlichen Aufgaben,
nicht zu einem Vertrag. Deshalb nennt die Variante für öffentliche Schulen das Landesrecht. Welche
Vorschriften gelten, ist von Land zu Land verschieden; die Vorlage lässt sie deshalb als Platzhalter offen.

Vor dem Start:

1. **Wer verantwortlich ist:** An einer staatlichen Schule ist die Schule Verantwortliche, und die
   Schulleitung beschließt den Einsatz. Betreibt jemand anderes den Server für die Schule (z. B. eine
   ehrenamtliche Person mit eigenem Hostingvertrag), braucht die Schule mit dieser Person eine Vereinbarung
   nach Art. 28 DSGVO (Weisungen, Vertraulichkeit, Hoster als Unterauftragsverarbeiter, Übergabe aller
   Zugänge, Löschung bei Betriebsende), oder die Schule bzw. der Schulträger schließt den Hostingvertrag
   selbst ab.
2. **Verantwortlicher:** Füllen Sie *Texte → Angaben für Impressum und Datenschutz* aus. Diese
   Angaben ersetzen `[[VERANTWORTLICHE]]`; ohne sie ist keine Runde öffentlich.
3. **Hoster:** Setzen Sie `hosting` in der Konfiguration (bzw. `HOSTING_DE`/`HOSTING_EN` beim Deployment).
   Der Wert ersetzt `[[HOSTER]]`. Ist er nicht gesetzt, zeigt die Seite die Platzhalter
   `[HOSTER MIT ANSCHRIFT]` und `[LAND]` (englisch `[WEB HOST WITH ADDRESS]`, `[COUNTRY]`), und Admins
   erhalten einen Hinweis.
4. **Verbleibende Platzhalter** in eckigen Klammern, in jeder Sprache, für die es einen Text gibt:
   Kontaktdaten der oder des Datenschutzbeauftragten, die Vorschriften Ihres Landes (Schulgesetz,
   Landesdatenschutzgesetz), die Speicherdauer der Webserver-Logs (fragen Sie Ihren Hoster) und die
   zuständige Aufsichtsbehörde. Die Vorschriften nennt Ihnen die oder der Datenschutzbeauftragte der Schule.
5. **Auftragsverarbeitungsvertrag** (AVV) mit Ihrem Hoster.
6. **Admin-Konten** nur für Personal der Schule oder für schriftlich zur Vertraulichkeit verpflichtete
   Personen: Die Datenschutzerklärung sagt Eltern zu, dass außer der Lehrkraft nur solche Personen ihre
   Buchungen sehen.

### Keine Aktivierung bei offenen Platzhaltern

TalkSlot prüft Impressum und Datenschutzerklärung aller angebotenen Sprachen so, wie Eltern sie sehen,
also mit eingesetzten Angaben zur verantwortlichen Stelle und zum Hoster. Ein offener Platzhalter ist Text
in eckigen Klammern ohne Kleinbuchstaben und mit mindestens vier Zeichen, etwa `[LAND]`. Ein
Markdown-Link wie `[PDF](https://…)` oder Text in gemischter Schreibung wie `[Hinweis]` zählt nicht.
Solange einer übrig ist:

- **Keine Runde lässt sich aktivieren:** weder eine neue aktive Runde noch über *Aktivieren* noch durch
  Zuordnen einer aktiven Runde zu einer anderen Lehrkraft. Die Meldung nennt die betroffenen Texte.
- **Admins** sehen im Dashboard und unter *Texte*, welche Platzhalter in welchem Text und welcher Sprache
  offen sind. **Lehrkräfte** sehen, dass die Admins die Texte noch ausfüllen müssen.
- **Bereits aktive Runden bleiben erreichbar**, und Eltern können ihre Änderungslinks weiter nutzen. Eine
  bestehende Installation geht nach einem Update also nicht offline: Das Dashboard kündigt die offenen
  Platzhalter an, und erst die nächste Aktivierung wartet darauf.

Bei einem Update werden nie bearbeitete Texte durch die neuen Vorlagen ersetzt (siehe
[Betrieb]({{ '/de/guide/operations/' | relative_url }})), einschließlich neuer Platzhalter. Bearbeitete
Texte bleiben, wie sie sind. Enthalten sie noch die alten Sätze zum „privaten Angebot“ ohne
Datenschutzbeauftragte oder zum Absagen „jederzeit“, bittet ein Hinweis, sie mit der neuen Vorlage
abzugleichen.

Lassen Sie die Texte von jemandem prüfen, der Ihre Situation kennt, am besten von der oder dem
Datenschutzbeauftragten der Schule; die Vorlagen ersetzen keine Rechtsberatung.
