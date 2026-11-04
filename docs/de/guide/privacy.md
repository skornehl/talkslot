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
Zwecke, Rechtsgrundlagen einschließlich berechtigter Interessen, Empfänger, Drittländer, Speicherdauer,
Betroffenenrechte einschließlich des Widerspruchsrechts, Beschwerderecht bei einer Aufsichtsbehörde, ob
die Bereitstellung der Daten erforderlich ist, sowie automatisierte Entscheidungsfindung.

Vor dem Start:

1. **Verantwortlicher:** Füllen Sie *Texte → Angaben für Impressum und Datenschutz* aus. Diese
   Angaben ersetzen `[[VERANTWORTLICHE]]`; ohne sie ist keine Runde öffentlich.
2. **Hoster:** Setzen Sie `hosting` in der Konfiguration (bzw. `HOSTING_DE`/`HOSTING_EN` beim Deployment).
   Der Wert ersetzt `[[HOSTER]]`. Ist er nicht gesetzt, zeigt die Seite einen sichtbaren Platzhalter, und
   Admins erhalten einen Hinweis.
3. **Verbleibende Platzhalter** in eckigen Klammern: Speicherdauer der Webserver-Logs (fragen Sie Ihren
   Hoster) und die zuständige Datenschutz-Aufsichtsbehörde.
4. **Auftragsverarbeitungsvertrag** (AVV) mit Ihrem Hoster.
5. Wenn Lehrkräfte die Plattform nutzen: Der Betreiber (meist die Schule) ist für alle Runden
   verantwortlich, die Lehrkräfte handeln in seinem Auftrag und nach seinen Weisungen.

Lassen Sie die Texte von jemandem prüfen, der Ihre Situation kennt; die Vorlagen ersetzen keine
Rechtsberatung.
