---
title: Anleitung für Admins
description: Admin-Konten richten die Plattform ein, laden Lehrkräfte ein, sehen alle Runden, pflegen Vorlagen und die Rechtstexte.
---

Das erste **Admin-Konto** wird bei der Installation angelegt; es kann weitere Konten zu Admins machen.
Admins sehen und bearbeiten alle Runden, legen Runden für Lehrkräfte an, verwalten die Konten und pflegen
die Rechtstexte. Verantwortlicher im Sinne der DSGVO ist für alle Terminrunden der Betreiber der Plattform:
die Schule, wenn sie TalkSlot selbst betreibt, sonst die Privatperson, der Verein oder der freie Träger, dessen
Angebot die Lehrkräfte nutzen (die *Betriebsart* unter *Texte*). Die Bezeichnungen unten sind die
des deutschen Admin-Bereichs; jedes Konto kann ihn unter *Mein Profil* auf Englisch umstellen.

![Admin-Übersicht über alle Runden]({{ '/assets/img/admin-dashboard.webp' | relative_url }})

## Erste Schritte

1. **Profil** (*Mein Profil*): Tragen Sie Ihren Anzeigenamen (wird den Eltern bei Ihren eigenen Runden
   angezeigt) und Ihre E-Mail-Adresse ein.
2. **Angaben für Impressum und Datenschutz** (*Texte*): Prüfen Sie die **Betriebsart** (*Wird von der
   Schule betrieben* oder *Wird privat betrieben*, beim Installieren gewählt) und tragen Sie Name der Schule
   bzw. des Betreibers, Vertretung, vollständige Anschrift und E-Mail-Adresse ein. Dieser Text ersetzt
   `[[VERANTWORTLICHE]]` in beiden Rechtstexten und gilt für alle Admins gemeinsam. Ohne diese Angaben ist
   **keine Runde öffentlich erreichbar**.
3. **Rechtstexte** (*Texte*): Impressum und Datenschutzerklärung stammen aus den Vorlagen der Betriebsart.
   Prüfen Sie sie für jede Sprache und ersetzen Sie die verbleibenden Platzhalter in eckigen Klammern.
   Vorher lässt sich keine Runde aktivieren (in den Übersetzungen der Datenschutzerklärung bedeuten offene
   Platzhalter nur, dass Eltern den englischen Text bekommen). Details unter
   [Datenschutz und Recht]({{ '/de/guide/privacy/' | relative_url }}).
4. **E-Mail-Versand prüfen:** *Texte → E-Mail-Versand* zeigt Versandweg und Absender. Machen Sie eine
   Testbuchung mit Ihrer eigenen E-Mail-Adresse und prüfen Sie, ob die Bestätigung ankommt (und nicht als
   Spam markiert wird).
5. **Kontakt für Eltern** (*Texte → Kontakt für Eltern bei Fragen*, optional): z. B. das Sekretariat mit
   Telefonnummer und Zeiten. Eltern sehen ihn, wenn sie nicht weiterkommen: auf der Seite „Nicht gefunden“,
   bei Runden mit Rundencode vor der Eingabe des Codes, nach einem falschen Code auf der Startseite und bei
   den Runden von Lehrkräften, die in ihrem Profil keinen eigenen Kontakt angegeben haben. Er wird nicht
   übersetzt, also bitte kurz und ohne Abkürzungen. Ohne jeden Kontakt verweisen diese Stellen auf das
   Impressum.
6. ***Vorlagen*** (optional): die üblichen Formate einmal anlegen, z. B. Ihren Elternsprechtag.
7. **Lehrkräfte einladen** und bei Bedarf weitere Admins (*Benutzer*).

***Bereit für den Start?*** (verlinkt unter der Überschrift von *Alle Runden* und oben unter *Texte*) zeigt
all das auf einer Seite, jeden Punkt als *Erledigt*, *Bitte prüfen* oder *Noch offen*, mit dem Weg zur Lösung:
Impressum und Datenschutzerklärung, die bzw. der Datenschutzbeauftragte der Schule (nur bei Betrieb durch die
Schule), der Kontakt für Eltern, E-Mail-Versand, Cronjob, Server und Konfiguration sowie die Rechte der Konfigurationsdatei. Der
E-Mail-Versand gilt als erledigt, sobald sich ein eingeladenes Konto angemeldet hat: Seine Einladung ist
angekommen. Bis die erste Runde aktiv war, zeigt *Alle Runden* diese Liste statt der einzelnen Warnungen zu
diesen Punkten; danach erscheinen die Warnungen wie bisher.

***Neu seit dem letzten Update***: Nach einem Update nennt *Alle Runden*, was sich für Admins und Lehrkräfte
geändert hat, z. B. eine neue Einstellung im Profil, damit das nicht wie ein Fehler wirkt. *Verstanden*
blendet die Hinweise für Ihr Konto aus. Nach dem Update angelegte Konten und eine neue Installation bekommen
die älteren Hinweise nicht.

Warnt die Übersicht, dass die Konfiguration für alle Benutzer des Servers lesbar ist, setzen Sie die
Rechte der genannten Dateien auf `600` oder geben Sie die Warnung an die technische Betreuung weiter: Sie
enthalten die Passwörter für Datenbank und Mailversand. Könnten diese inzwischen in falsche Hände gelangt
sein, siehe [Secrets austauschen]({{ '/de/guide/operations/' | relative_url }}#changing-secrets).

Meldet die Übersicht, dass der Cronjob seit einem bestimmten Zeitpunkt nicht mehr vollständig gelaufen ist
oder noch kein vollständiger Lauf verzeichnet ist, prüfen Sie den Cronjob auf dem Server bzw. den GitHub-Workflow *Cron* oder geben
Sie die Warnung an die technische Betreuung weiter; siehe
[Überwachung des Cronjobs]({{ '/de/guide/operations/' | relative_url }}#cron-monitoring).

Meldet die Übersicht, dass die Einrichtung des Servers Aufmerksamkeit braucht, geben Sie die Liste an die
technische Betreuung weiter: Sie nennt Optionen in `config/config.php`, die so nicht wirken (etwa wegen eines
Tippfehlers), und einen Datenbankserver, der nicht MariaDB 10.6 oder neuer ist; siehe
[Prüfung der Konfiguration]({{ '/de/guide/configuration/' | relative_url }}#checking).

## Konten

Unter *Benutzer* → *+ Neuer Benutzer* geben Sie **Name und E-Mail-Adresse** ein und wählen die **Rolle**:
*Lehrkraft* (verwaltet ihre eigenen Runden) oder *Admin*. Die Person erhält
eine **Einladungs-E-Mail** mit einem Link, über den sie ein eigenes Passwort festlegt (einmal verwendbar,
standardmäßig 48 Stunden gültig). Passwörter werden nie per E-Mail verschickt. Der Benutzername ist die
E-Mail-Adresse, außer Sie geben unter *Benutzername (Login)* einen eigenen ein: Der ist schwerer zu
erraten, denn die E-Mail-Adresse sehen Eltern, wenn sie auf eine E-Mail antworten; die Einladung nennt
ihn. Kann die E-Mail nicht versendet werden, sehen Sie nur einen Hinweis, nicht den Link (mit
ihm könnten Sie das Passwort eines fremden Kontos festlegen): Prüfen Sie den Mailversand (*Texte →
E-Mail-Versand*) und senden Sie die Einladung danach erneut.

Zusammenfassungen, Passwort-Links und die Antworten der Eltern gehen an die Adressen der Konten. Damit sie in
den Postfächern der Schule bleiben, wo ihre Löschregeln gelten, kann die technische Betreuung die Adressen
auf die Domains der Schule beschränken (`allowed_account_domains` in `config/config.php`, siehe
[Konfiguration]({{ '/de/guide/configuration/' | relative_url }}#zusammenfassungen-und-konten)). Eine neue
oder geänderte Adresse anderswo wird dann abgelehnt; Konten, die schon eine haben, funktionieren weiter,
erhalten an dieser Adresse aber keine Zusammenfassungen und keine Antworten der Eltern, bis sie geändert
ist (statt einer solchen Antwortadresse für Eltern gilt dann die E-Mail-Adresse des Kontos, wenn diese
erlaubt ist). Die Übersicht sagt es dem Konto und nennt Admins die Zahl der Konten mit solchen Adressen.

Die Spalte *Letzter Login* zeigt nur *nie*, *in den letzten 12 Monaten* oder *vor über 12 Monaten*, keine
Uhrzeiten: Das genügt, um Konten aufzuräumen, ohne allen Admins die Anmeldezeiten der Kolleginnen und
Kollegen zu zeigen. Die Übersicht nennt Admins die Zahl der aktiven Konten, die seit über 12 Monaten
niemand genutzt hat.

Ein bestehendes Konto können Sie:

- **deaktivieren** (*Konto aktiv* abwählen): keine Anmeldung möglich, die Runden der Lehrkraft sind nicht
  öffentlich erreichbar;
- mit einer neuen **Einladung** oder einem **Link zum Zurücksetzen des Passworts** versehen (*Einladung
  erneut senden* / *Link zum Zurücksetzen senden*). Das alte Passwort gilt dann nicht mehr, und alle
  Sitzungen des Kontos werden beendet;
- in der **Rolle** ändern, also eine Lehrkraft zum Admin machen oder umgekehrt;
- **löschen**, einschließlich aller Runden und Buchungen. Mit angehaktem *Eltern per E-Mail informieren*
  (Standard) erhalten die Eltern künftiger Termine eine Absage per E-Mail, wie wenn eine Lehrkraft eine
  Runde löscht (siehe [Anleitung für Lehrkräfte]({{ '/de/guide/teachers/' | relative_url }})); der Dialog
  nennt ihre Zahl, und die Meldung danach nennt diejenigen, die keine E-Mail bekommen konnten, damit sie
  persönlich informiert werden.

Die eigene Rolle kann niemand ändern, das eigene Konto niemand deaktivieren oder löschen, und mindestens
ein aktiver Admin bleibt immer bestehen, auch wenn zwei Admins sich im selben Moment gegenseitig
herabstufen.

### Schutz vor Missbrauch

Eine übernommene Sitzung oder ein missbrauchtes Admin-Konto soll andere Konten nicht unbemerkt übernehmen
können:

- **Ihr Passwort noch einmal** (*Ihr Passwort (zur Bestätigung)*) braucht es, um einen Admin einzuladen,
  Admin-Rechte zu vergeben oder zu entziehen, ein Admin-Konto zu deaktivieren, wieder zu aktivieren oder zu
  löschen und um eine E-Mail-Adresse zu ändern (dorthin gehen Passwortlinks; das gilt auch für die eigene
  Adresse). Nach 5 falschen Eingaben in 15 Minuten (falsche aktuelle Passwörter beim Ändern des eigenen
  Passworts zählen mit) wird die Bestätigung eine Weile abgelehnt; nach Ab- und erneutem Anmelden geht es
  sofort wieder.
- **Sicherheitsmails:** Der Kontoinhaber erhält eine E-Mail, wenn sein Passwort geändert oder festgelegt
  wurde; die bisherige Adresse, wenn ein Admin die E-Mail-Adresse oder den Benutzernamen geändert hat; alle
  aktiven Admins (auch Sie selbst) und die betroffene Person, wenn sich Admin-Rechte ändern oder ein
  Admin-Konto deaktiviert, wieder aktiviert oder gelöscht wird. Bitte filtern Sie diese Mails nicht weg:
  Oft sind sie der einzige Weg, einen Missbrauch zu bemerken.
- **Passwortlinks** gehen nur per E-Mail hinaus und werden nie angezeigt. Setzt ein Admin ein Passwort
  zurück, erfährt die Person, dass ihr bisheriges Passwort nicht mehr gilt.
- **Missbrauch der öffentlichen Seiten:** Kommen innerhalb von 5 Minuten mehr als 5 Buchungen einer Runde
  aus einem Netzwerk, erhalten die Lehrkraft der Runde und alle Admins eine E-Mail (*Auffällig viele
  Buchungen in der Runde …*), höchstens eine pro Runde und Stunde. Prüfen Sie die Buchungen; *Mehrere
  Buchungen löschen …* unter jedem Tag löscht die eines Zeitraums auf einmal, und *Nur mit Rundencode*
  verhindert eine Wiederholung. Der Betreiber (`alert_email`) erhält eine Warnmeldung, wenn Eltern in einer
  Stunde mehr als 100 E-Mails auslösen; an eine Adresse gehen höchstens 10 solcher E-Mails pro Stunde (siehe
  [Sicherheit]({{ '/de/guide/security/' | relative_url }})).
- **Alle Sitzungen beenden** (unten auf der Seite *Benutzer*) meldet alle Konten auf allen Geräten ab, nur
  Ihre aktuelle Sitzung bleibt bestehen, z. B. nach einem verlorenen Gerät oder bei Verdacht auf einen
  Einbruch. Es braucht Ihr Passwort, und alle Admins erhalten eine E-Mail. Was in so einem Fall außerdem zu
  tun ist, steht unter [Sicherheit]({{ '/de/guide/security/' | relative_url }}#bei-verdacht-auf-missbrauch).

### Wenn jemand die Schule verlässt

1. Noch benötigte Runden einer anderen Lehrkraft zuordnen (Feld *Lehrkraft* im Rundenformular).
2. Das Konto deaktivieren (*Konto aktiv* abwählen) oder löschen, sobald nichts davon mehr gebraucht wird.
3. Bei Admins: zuerst die Rolle entziehen und die Zugangsdaten ändern, die die Person kannte (z. B.
   Hosting, Datenbank, `APP_SECRET`, GitHub).

Der Hinweis in der Übersicht auf Konten, die seit über 12 Monaten niemand genutzt hat, hilft, niemanden zu
vergessen.

## Runden aller Lehrkräfte

Admins sehen und bearbeiten unter *Alle Runden* die Runden aller Lehrkräfte mit ihren Buchungen. Sie
können eine Runde für eine Lehrkraft anlegen oder eine Runde einer anderen Lehrkraft zuordnen (Feld *Lehrkraft* im
Rundenformular). Die Runde erscheint dann bei dieser Lehrkraft, die Eltern sehen ihren Namen, und die
Zusammenfassungen gehen an sie. Eltern, die schon gebucht haben, erhalten eine Änderungsmitteilung mit der
neuen Lehrkraft, wenn *Eltern per E-Mail informieren* angehakt bleibt; die Meldung nach dem Speichern nennt
diejenigen, die keine E-Mail bekommen konnten. Lehrkräfte sehen immer nur
ihre eigenen Runden; das wird zentral durchgesetzt und ist durch Tests abgesichert. Im *Papierkorb* sehen Admins
auch die gelöschten Runden ohne Buchungen aller Lehrkräfte.

Wie Runden funktionieren, steht in der [Anleitung für Lehrkräfte]({{ '/de/guide/teachers/' | relative_url }}).

## Vorlagen

Unter *Vorlagen* legen Admins Formate an, die alle beim Anlegen einer Runde auswählen können, z. B.
„Elternsprechtag ganzer Tag: 08:00–16:00, 15 Minuten, Mittagspause 12:00–13:00, ein Termin pro Person“.
Eine Vorlage enthält Zeiten, Dauer, Pausen, Fristen, Mehrfachbuchungen, *Nur mit Rundencode*, die
Erinnerung, Ort und Beschreibung. Im Formular für eine neue Runde trägt *Vorlage* → *Übernehmen* das ein;
Titel und Datum kommen von Hand dazu, alles bleibt änderbar.

*Als Vorlage speichern* auf der Seite einer Runde macht aus ihr eine Vorlage: mit den Zeiten und Pausen
ihres ersten Tages und den Einstellungen der Runde. Wird eine Vorlage geändert oder gelöscht, bleiben die
damit angelegten Runden unverändert.

## Protokoll

Das *Protokoll* zeigt die Änderungen durch Admin-Konten, die neuesten zuerst: wer welche Runde angelegt,
bearbeitet, (de)aktiviert, dupliziert, einer anderen Lehrkraft gegeben oder gelöscht hat, wer Tage,
Pausen, gesperrte Termine oder Buchungen geändert, Konten und Rollen angelegt, geändert oder gelöscht,
Links verschickt und Vorlagen oder Rechtstexte bearbeitet hat. Bei mehreren Admins lässt sich so
nachvollziehen, wer was getan hat. Was TalkSlot bei einem Update selbst ändert, steht als *TalkSlot* im
Protokoll: eine aus unveränderten Vorlagen übernommene Betriebsart und Rechtstexte, die durch neuere
Vorlagen ersetzt wurden. Der Admin, der die Betriebsart danach speichert, steht mit „Betriebsart bestätigt“
im Protokoll, ein Rechtstext, der nach dem Abgleich mit einer neueren Vorlage gespeichert wurde, mit „… mit
dem aktuellen Stand der Vorlage … abgeglichen“.

Was Lehrkräfte in ihren eigenen Runden tun, erfasst das Protokoll **nicht**, und es enthält nie Namen oder
E-Mail-Adressen von Eltern: Eine Buchung erscheint nur mit ihrem Datum (z. B. „Buchung am 12.11.2026
gelöscht“), ohne Uhrzeit, mit der sie sich zusammen mit einer Zusammenfassung oder einem Ausdruck einer Person
zuordnen ließe; Runde, Lehrkraft und Datum können trotzdem auf eine Familie deuten, deshalb sehen das Protokoll
nur Admins.
Einträge werden nach 90 Tagen gelöscht (einstellbar, `audit_log_days`). Nur Admins sehen das Protokoll.

## Rechtstexte

Unter *Texte* stehen *Impressum* und Datenschutzerklärung (*Datenschutz*) je Sprache. Sie
werden in einfachem Markdown geschrieben (Überschriften mit `#`, Listen, Fettdruck, Links). Zwei
Platzhalter werden automatisch gefüllt:

| Platzhalter | Wird ersetzt durch |
|---|---|
| `[[VERANTWORTLICHE]]` | die *Angaben für Impressum und Datenschutz* (oben unter *Texte*) |
| `[[HOSTER]]` | den Hoster aus der Konfiguration (`hosting`) |

Die Datenschutzerklärung gibt es als Vorlage in allen sechs Elternsprachen; die Übersetzungen sagen, dass
die deutsche Fassung maßgeblich ist, und enthalten dieselben Platzhalter wie die deutsche, Sie tragen dort
also dieselben Angaben ein. Das Impressum gibt es auf Deutsch und Englisch. Für eine Sprache ohne Text, mit
leerem Text oder mit einer Übersetzung, deren Platzhalter noch offen sind, wird die englische Fassung
verwendet, danach die deutsche, danach die der Standardsprache (Englisch und Deutsch nur, wenn sie angeboten
werden), mit einem Hinweis an die Eltern in ihrer Sprache. Hat keine davon einen Text für eine Seite, lässt
sich keine Runde aktivieren. Füllen Sie die Übersetzungen aus, bevor Sie Eltern in diesen Sprachen einladen.
Speichern Sie die deutsche Datenschutzerklärung nach einer vollständigen Übersetzung, bittet das Dashboard,
diese Übersetzung zu prüfen (maßgeblich ist die deutsche Fassung); erneutes Speichern bestätigt sie. Für beide Texte
gibt es je eine Vorlage pro **Betriebsart** (*Von der Schule betrieben*, *Privat betrieben*). *Vorlage laden* im Formular eines Textes
setzt eine davon ein, die zur Betriebsart passende steht zuerst; der gespeicherte Text ändert sich erst beim
Speichern. Ändern Sie die Betriebsart oben unter *Texte*, ersetzt TalkSlot alle Texte, die noch unverändert
einer Vorlage entsprechen, durch die Vorlagen der neuen Betriebsart und nennt die Anzahl; bearbeitete Texte
bleiben, wie sie sind. Die Änderung steht im Protokoll.

**Neuere Vorlagen für bearbeitete Texte.** Einen Text, den Sie bearbeitet haben, überschreibt TalkSlot nie.
Stattdessen merkt es sich zu jedem Text die Vorlage, aus der er gespeichert oder mit der er zuletzt
abgeglichen wurde (seinen *Vorlagenstand*). Bringt ein Update eine neuere Vorlage Ihrer Betriebsart, nennen
das Dashboard und *Texte* die Texte und die Abschnitte, die die Vorlage seitdem geändert hat, z. B.
*Datenschutzerklärung (DE): 5. Speicherdauer und Löschung, 6. Empfänger*; in der Liste unter *Texte* steht
dann „Vorlage geändert“. Das Formular des Textes zeigt für jeden dieser Abschnitte, welche Zeilen die Vorlage
entfernt (−) und hinzugefügt (+) hat. Danach können Sie:

- **die Abschnitte übernehmen:** *Abschnitte aus der Vorlage übernehmen* lädt Ihren Text mit diesen
  Abschnitten in der Fassung der Vorlage ins Formular. Ihre Angaben in Zeilen, die die Vorlage nicht geändert
  hat (z. B. die Aufsichtsbehörde), bleiben, auch wo sie in den Zeilen darunter weitergehen (z. B. Name,
  Anschrift und E-Mail-Adresse der oder des Datenschutzbeauftragten), ebenso was an Stelle von
  `[[VERANTWORTLICHE]]` steht; eigene Änderungen in diesen Abschnitten nicht. Was aus Ihrem Text dabei
  wegfällt (eigene Zeilen, Ihre Angaben in einer Zeile, die die Vorlage geändert hat), listet der Hinweis als
  „nicht übernommen“ auf: Übernehmen Sie daraus, was noch in den Text gehört. Einen Abschnitt, den die Vorlage
  gestrichen hat, entfernt das Übernehmen, und der Hinweis nennt ihn als „entfernt“. Zum Vergleich bleiben die
  Unterschiede darunter sichtbar. Prüfen Sie das Formular, ersetzen Sie neue Platzhalter in eckigen Klammern
  und speichern Sie;
- **den Text selbst anpassen** und beim Speichern *Abgleich erledigt* ankreuzen; oder
- **die ganze Vorlage laden** (*Vorlage laden*) und neu ausfüllen.

Speichern Sie nach dem Übernehmen, dem Laden oder mit dem Häkchen, gilt die aktuelle Vorlage als Vorlagenstand
des Textes, der Hinweis verschwindet, und das Protokoll hält fest, wer den Text abgeglichen hat. Wurde die
Vorlage geändert, während das Formular offen war (z. B. durch ein Update), speichert TalkSlot den Text, vermerkt
den Abgleich aber nicht und weist darauf hin. Ein Text, der schon alles enthält, was die Vorlage seit seinem
Vorlagenstand geändert hat (oder ohne Vorlagenstand den Wortlaut der aktuellen Vorlage, nur die Platzhalter
ausgefüllt), braucht nichts: TalkSlot vermerkt den Vorlagenstand selbst. Ein Platzhalter innerhalb einer
Zeile steht für Text bis zum Zeilenende: Haben Sie einen über mehrere Zeilen ausgefüllt (z. B. die Anschrift
der oder des Datenschutzbeauftragten unter dem Namen), zeigt der Hinweis diese Zeilen bei einem Text ohne
Vorlagenstand als Abweichung, bis Sie den Abgleich einmal bestätigen. Bei einem Text, der bearbeitet wurde, bevor TalkSlot Vorlagenstände
festhielt, oder der aus der Vorlage der anderen Betriebsart stammt, ist die Ausgangsvorlage unbekannt: Der
Hinweis nennt dann die Abschnitte, in denen er von der aktuellen Vorlage abweicht („weicht von der Vorlage
ab“; ausgefüllte Platzhalter zählen nicht), mit den Zeilen, die nur in Ihrem Text (−) und nur in der Vorlage
(+) stehen. Dazu gehören auch Abschnitte, die es nur in Ihrem Text gibt: Ob sie Ihre eigenen sind oder aus
einer früheren Vorlage stammen, ist ohne Vorlagenstand nicht bekannt, deshalb bleiben sie beim Übernehmen, wie
sie sind (der Hinweis nennt sie als „unverändert“); prüfen Sie selbst, ob sie noch zutreffen. Bestätigen Sie den
Abgleich einmal, danach sehen Sie nur noch, was spätere Vorlagen ändern.

Heißt ein Abschnitt in Ihrem Text anders als in der Vorlage (z. B. „# 5. Wie lange wir Daten speichern“), fügt
das Übernehmen den Abschnitt der Vorlage zusätzlich ein; löschen Sie dann den alten vor dem Speichern.

Ist keine Betriebsart gewählt (Installationen aus der Zeit davor mit bearbeiteten Texten), zeigt das
Dashboard „Bitte Betriebsart wählen“, und bis ein Admin sie wählt, lässt sich keine Runde aktivieren;
bereits aktive Runden bleiben erreichbar.

Angaben, die nur Sie kennen, stehen als Platzhalter in eckigen Klammern im Text. Solange der englische Text
oder der der Standardsprache noch einen enthält, lässt sich keine Runde aktivieren; bereits aktive Runden
bleiben erreichbar. Offene Platzhalter in einer Übersetzung der Datenschutzerklärung halten die Aktivierung
nicht auf; Dashboard und *Texte* zeigen dann *Übersetzungen noch nicht vollständig*, bis Sie sie ausfüllen
oder die Übersetzung leeren. Hinweise im Dashboard und unter *Texte* nennen die offenen Platzhalter je Text
und zeigen an, wenn einem Text ein Platzhalter fehlt, er veraltet ist (z. B. noch das Pflicht-Häkchen
erwähnt, das das Buchungsformular nicht mehr hat) oder nicht zur Betriebsart passt (z. B. die
Rechtsgrundlagen der Vorlage für Schulen bei privatem Betrieb).

## Benachrichtigungen

Unter *Mein Profil* legt jedes Konto fest, welche ***Zusammenfassungen per E-Mail***
es erhält: abends eine Übersicht über den nächsten Tag (standardmäßig ab 18:00 Uhr) und eine Übersicht nach
dem Buchungsschluss eines Tages. Standardmäßig nennen sie je Runde und Tag die Zahl der Termine, Auffälliges
(derselbe Name oder dieselbe Adresse mehrmals, Eltern ohne Adresse, nicht zugestellte E-Mails, Konflikte) und
den Link zur Liste, aber **keine Namen** und nie E-Mail-Adressen der Eltern; siehe die
[Anleitung für Lehrkräfte]({{ '/de/guide/teachers/' | relative_url }}#zusammenfassungen-per-e-mail). Die
E-Mails bleiben in Postfächern und Kalendern, die die Löschregeln der Schule womöglich nicht erreichen,
eventuell bei Anbietern außerhalb der EU.

Namen (und eine Kalenderdatei der Termine, selbst ohne Namen) gibt es nur, wenn die Schule sie erlaubt:
`summary_names` in `config/config.php` (Deployment: Variable `SUMMARY_NAMES=1`). Dann wählt jedes Konto im
Profil *Mit Namen*; neue Konten beginnen ohne. Erlauben Sie das nur, wenn alle Konten dienstliche Adressen
verwenden, deren Löschregeln die Schule in der Hand hat (`allowed_account_domains`), und empfehlen Sie, die
E-Mails bis zum Ende des Schuljahres zu löschen. Nach dem Update sehen Konten von vorher in der Übersicht
einen Hinweis, was sich für sie ändert. Wo Namen erlaubt sind, behalten ihre Zusammenfassungen sie; sonst
sind sie ohne Namen, und sobald der Hinweis bestätigt ist, gilt das Konto wie ein neues (Namen nur, wenn
es sie ankreuzt).

Eltern mit E-Mail-Adresse bekommen am Vorabend ihres Termins eine **Erinnerung** (zur selben Uhrzeit,
standardmäßig ab 18:00 Uhr) mit einem Link zum Absagen. Sie lässt sich pro Runde abschalten (*Eltern am
Vorabend an ihren Termin erinnern*). Die Vorlage der Datenschutzerklärung nennt die Erinnerung als Zweck der
E-Mail-Adresse; haben Sie Ihre Datenschutzerklärung bearbeitet, bittet ein Hinweis in der Übersicht, das
zu ergänzen.

Zusammenfassungen und Erinnerungen verschickt der Cronjob über die
[Mail-Warteschlange]({{ '/de/guide/operations/' | relative_url }}#mail-warteschlange); die Übersicht weist
Admins darauf hin, wenn E-Mails länger als `cron_alert_hours` (mindestens zwei Stunden) warten.
