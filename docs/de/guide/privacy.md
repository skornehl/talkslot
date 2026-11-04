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
| Hash des persönlichen Änderungslinks (und des zweiten Links in der Erinnerungsmail) | Datenbank | mit der Buchung |
| Gekürzte, per HMAC gehashte IP-Adressen | Datenbank (Rate Limiting) | spätestens nach 24 Stunden |
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
- **Protokoll ohne Kontrolle der Lehrkräfte:** Erfasst werden nur Änderungen durch Admin-Konten, damit bei
  mehreren Admins nachvollziehbar ist, wer was getan hat. Was Lehrkräfte in ihren eigenen Runden tun,
  wird nicht aufgezeichnet, Buchungen erscheinen nur mit Datum und Uhrzeit. Je nach Bundesland kann ein
  Protokoll von Handlungen der Beschäftigten trotzdem den Personalrat betreffen; die Aufbewahrung lässt
  sich mit `audit_log_days` verkürzen.
- **Keine Cookies auf den Elternseiten.** Die Sprache steht in der URL (`?lang=`), der CSRF-Schutz
  arbeitet zustandslos. Ein Cookie-Banner ist nicht nötig. Nur der Admin-Bereich verwendet ein
  Sitzungs-Cookie, beschränkt auf `/admin`.
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
  vorausgewähltes Kontrollkästchen**. Nur wenn es angehakt ist, speichert der Browser Link und Bezeichnung
  des Termins im `localStorage`. An den Server wird nichts übertragen.
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
