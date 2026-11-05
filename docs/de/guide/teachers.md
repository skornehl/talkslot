---
title: Anleitung für Lehrkräfte
description: Terminrunden anlegen, Eltern einladen und Buchungen verwalten.
---

Lehrkräfte melden sich unter `/admin` mit ihrer E-Mail-Adresse an. Sie sehen und ändern nur ihre eigenen
Terminrunden. Die Bezeichnungen sind die des deutschen Admin-Bereichs; unter *Mein Profil* lässt er sich auf
Englisch umstellen.

## Runde anlegen

*+ Neue Runde* öffnet das Formular. Die Termine werden zusammen mit der Runde angelegt:

- ***Vorlage*** (falls Ihre Admins welche angelegt haben): *Übernehmen* trägt Zeiten, Pausen und
  Einstellungen ein, z. B. „Elternsprechtag: 08:00–16:00, 15 Minuten, Mittagspause“. Alles bleibt
  änderbar; nur Titel und Datum fehlen dann noch.
- **Titel** und optional ein ***Beschreibungstext***, die den Eltern angezeigt werden. Reiner Text.
- ***Ort*** (optional), z. B. „Raum 1.12“. Er steht auf der Buchungsseite, auf der Bestätigungsseite, in
  der Bestätigungsmail, in der Kalenderdatei, in der Druckansicht und im Einladungstext. Findet ein Tag
  woanders statt, tragen Sie das unter *Tag bearbeiten* ein.
- ***Datum***: ein oder mehrere Tage mit denselben Zeiten (*+ weiteren Tag*).
- **Erster Termin**, **Ende des letzten Termins** und **Dauer pro Termin** in Minuten.
- **Pausen** (*+ Pause hinzufügen*): beliebig viele pro Tag; nach der Pause geht es mit den Terminen
  weiter.
- Eine **Vorschau** zeigt die entstehenden Termine, bevor Sie speichern.
- ***Mehrfachbuchungen***: ein Termin pro Person in dieser Runde (Standard), einer pro Person und Tag
  oder erlaubt mit Hinweis. Als dieselbe Person gilt, wer denselben Namen (Groß-/Kleinschreibung und
  Leerzeichen werden ignoriert) und, sofern bei beiden Buchungen angegeben, dieselbe E-Mail-Adresse
  verwendet.
  Geschwister buchen jeweils mit dem Namen des Kindes. Ihre eigenen Einträge sind nicht begrenzt.
- **Fristen**: Buchungen sind bis *N* Stunden vor einem Termin möglich (*Buchungsschluss*), Eltern können
  bis *N* Stunden vorher ändern oder absagen (*Ändern/Absagen bis*).
- ***Runde löschen nach Ablauf***: Stunden nach dem letzten Tag, standardmäßig 24, leer = nie. Buchungen werden in jedem Fall am Tag nach dem Termin gelöscht.
  Ablaufen kann nur eine Runde, die in Gebrauch war: die irgendwann aktiv war oder in die Sie etwas
  eingetragen haben. Eine Runde, die nie aktiv war, etwa eine Kopie mit den alten Daten, bleibt, bis Sie
  sie nutzen oder löschen (*Noch nie aktiv: wird nicht automatisch gelöscht*). Die Seite der Runde nennt
  das Datum der Löschung (*Runde wird am … gelöscht*); eine abgelaufene Runde sagt außerdem, wie Sie sie
  behalten: einen Tag ab heute hinzufügen oder dieses Feld leeren.
- **Erinnerung** (*Eltern am Vorabend an ihren Termin erinnern*, standardmäßig an): Eltern mit
  E-Mail-Adresse bekommen am Vorabend eine Erinnerung mit dem Link zum Absagen. Wer am selben Tag eine
  Bestätigung oder Änderungsmitteilung bekommen hat, bekommt keine (eine Namenskorrektur ohne E-Mail zählt
  nicht). Sie gilt für Buchungen, die gemacht wurden, während sie eingeschaltet war; Ihre eigenen Einträge
  bekommen sie erst, wenn die Eltern eine Bestätigung oder Änderungsmitteilung per E-Mail erhalten haben.
- **Zufallsanteil in der Adresse** (empfohlen), damit die Runde nicht durch Ausprobieren gefunden wird,
  und optional *Nur mit Rundencode*.
- **Aktiv**: Eltern können buchen. Inaktive Runden nehmen keine Buchungen an, Eltern können aber weiterhin
  absagen.

![Formular für eine neue Runde]({{ '/assets/img/admin-round-form.webp' | relative_url }})

## Eltern einladen

Die Seite der Runde zeigt alles, was Sie brauchen:

- den **Link für die Eltern** (*Link kopieren*, *Öffnen*),
- den **Rundencode**, z. B. `3MMP-WYA9`. Eltern können ihn statt des Links auf der Startseite eingeben,
  praktisch für Einladungen auf Papier. Verwechslungen wie `0`/`O`, Bindestriche sowie Groß- und
  Kleinschreibung spielen keine Rolle.
- einen fertigen **Einladungstext** (*Einladungstext kopieren*, *Als E-Mail öffnen*) mit dem Link, einem
  direkten Link für jede weitere Elternsprache (`Türkçe: …?lang=tr`), gegebenenfalls Ort und Code sowie
  demselben Satz zu Mehrfachbuchungen, den Eltern auf der Seite der Runde lesen (zum Beispiel die
  Geschwisterregel).

Eltern wählen ihre Sprache ganz oben auf jeder Seite, jeweils unter ihrem eigenen Namen (*Deutsch,
English, Polski, Русский, Українська, Türkçe*). Ist ihr Browser auf eine andere angebotene Sprache
eingestellt, erscheint über der Seite ein Link dorthin.

Mit *Nur mit Rundencode* zeigt der Link allein nur eine Code-Abfrage; Titel und Termine bleiben für Fremde
verborgen. Ein neuer Code (beim Bearbeiten) macht den alten sofort ungültig; bestehende Buchungen bleiben
über ihre persönlichen Änderungslinks erreichbar.

## Buchungen verwalten

![Seite einer Runde mit Buchungen]({{ '/assets/img/admin-round.webp' | relative_url }})

Pro Tag sehen Sie alle Termine mit den Buchungen:

- ***Bearbeiten***: Name oder E-Mail-Adresse ändern oder die Buchung auf einen anderen
  Termin **verschieben**, mit einer E-Mail an die Eltern (*Änderung per E-Mail mitteilen*, standardmäßig
  angehakt). Die E-Mail nennt Datum, Uhrzeit und Ort in der Sprache der Eltern und geht deshalb auch
  hinaus, wenn Sie hier nichts ändern, etwa nachdem der Tag ein anderes Datum oder einen anderen Ort
  bekommen hat; soll keine E-Mail hinausgehen, nehmen Sie das Häkchen heraus.
- ***Löschen***: eine Buchung entfernen.
- ***Eintragen***: selbst einen Termin eintragen, z. B. nach einem Telefonat. Die Bestätigungs-E-Mail mit
  Änderungslink ist angehakt, sobald Sie eine E-Mail-Adresse eintragen; nur so erfahren die Eltern von
  dem Eintrag.
- ***E-Mail nicht zugestellt*** neben einem Eintrag: Die E-Mail an die Eltern konnte nicht verschickt
  werden. Steht dort *neuer Versuch folgt*, wird sie automatisch wiederholt; sonst informieren Sie die
  Eltern bitte persönlich oder prüfen die Adresse: unter *Bearbeiten* korrigieren und mit angehakter
  E-Mail speichern. Diese E-Mail enthält keinen Änderungslink; kam die Bestätigung eines selbst
  angelegten Eintrags nie an, löschen Sie den Eintrag stattdessen und tragen ihn mit der richtigen Adresse
  neu ein, damit die Eltern den Link bekommen.
- ***Sperren***: einen Termin sperren, damit niemand ihn buchen kann, und wieder freigeben.
- **Tage**: Zeiten und den Ort eines einzelnen Tages bearbeiten (*Tag bearbeiten*), Pausen hinzufügen,
  einen Tag drucken (*Tag drucken*), weitere Tage hinzufügen (*+ Tag hinzufügen*) oder einen Tag löschen.

Wenn Sie Zeiten oder Pausen ändern, werden **gebuchte Termine nie gelöscht**; Termine, die nicht mehr
passen, werden mit ⚠ markiert, damit Sie sie verschieben können.

**Die Eltern erfahren Ihre Änderungen per E-Mail.** Wenn Sie eine Buchung, einen Tag oder eine Runde
löschen, einem Tag ein anderes Datum, eine andere Uhrzeit oder einen anderen Ort geben oder der Runde einen
anderen Ort oder (als Admin) eine andere Lehrkraft, ist im Dialog bzw. Formular ***Eltern per E-Mail
informieren*** angehakt. Die Eltern erhalten dann eine E-Mail in ihrer Sprache:

- eine **Absage**, wenn ihre Buchung gelöscht wird, mit einer Kalenderdatei, die den Termin aus ihrem
  Kalender entfernt, und dem Link zur Seite der Runde, um neu zu buchen, falls die Runde noch Buchungen
  annimmt. Sie enthält keinen persönlichen Link, denn die Buchung gibt es nicht mehr. Ein einzelner Eintrag
  geht sofort hinaus; mehrere (ein Tag, eine Runde) gehen über die
  [Mail-Warteschlange]({{ '/de/guide/operations/' | relative_url }}#mail-warteschlange) hinaus, mit dem
  nächsten Lauf des Cronjobs oder, mit PHP-FPM, gleich nach der Seite; Absagen für Termine in den nächsten
  24 Stunden gehen dort zuerst hinaus;
- eine **Änderungsmitteilung** mit aktuellem Datum, Uhrzeit, Ort und Lehrkraft, wenn sich davon etwas
  geändert hat (über die Mail-Warteschlange). Ihr persönlicher Link bleibt gültig, und die Kalenderdatei
  aktualisiert den Termin in ihrem Kalender. Ändern Sie denselben Tag noch einmal, bevor die Mitteilung
  hinausgegangen ist, bekommen die Eltern eine E-Mail mit dem letzten Stand.

E-Mails gehen an Eltern, die selbst mit E-Mail-Adresse gebucht haben, und an Ihre eigenen Einträge, sobald
deren Eltern eine Bestätigung oder Änderungsmitteilung von TalkSlot erhalten haben. Alle anderen, und alle,
wenn Sie das Häkchen herausnehmen, werden **nicht** benachrichtigt: Dialoge und Formulare nennen ihre Zahl,
und die Meldung danach nennt sie mit Datum, Uhrzeit und Adresse (einmalig; nach dem Löschen zeigt sie sonst
nichts mehr an), damit Sie sie persönlich informieren können. Vergangene Termine betrifft das nicht.

Geben Sie einer Buchung (*Bearbeiten*) eine **neue E-Mail-Adresse**, erhält diese Adresse Änderungen,
Absagen und Erinnerungen erst, wenn Sie den Eintrag mit *Änderung per E-Mail mitteilen* gespeichert haben
und die E-Mail hinausgegangen ist; bis dahin sagt das die Meldung nach dem Speichern, und die Buchung gilt
als Ihr eigener Eintrag (*selbst eingetragen*). Änderungsmitteilungen nach einer Änderung eines Tages oder
einer Runde sind auf 200 pro Stunde aus Ihrem Konto begrenzt (einstellbar in der Konfiguration,
`rate_limits.teacher_bulk`); die Meldung nennt die Eltern darüber, damit Sie sie persönlich informieren oder
ihnen die Daten später aus ihrem Eintrag schicken.

Buchungen, die nach einer Änderung von Zeiten oder Pausen nicht mehr passen, werden zu **Konflikten** (⚠).
Deren Eltern erhalten noch keine E-Mail: Sie erfahren davon, wenn Sie die Buchung umbuchen (*Bearbeiten*,
*Änderung per E-Mail mitteilen* angehakt) oder löschen (*Eltern per E-Mail informieren* angehakt). Bekommt
ein Konflikt zugleich ein neues Datum, einen neuen Ort oder eine neue Lehrkraft, sagt die Warnung nach dem
Speichern, dass seine Eltern die neuen Daten noch nicht kennen. Passt ein Konflikt nach einer späteren
Änderung (des Tages oder einer Pause) wieder, erhalten seine Eltern eine Änderungsmitteilung mit den
aktuellen Daten, wenn sich diese seit ihrer letzten E-Mail geändert haben. Ein Eintrag, den Sie mit
*Änderung per E-Mail mitteilen* speichern, schickt die aktuellen Daten auch ohne Änderung (höchstens 30
E-Mails pro Stunde aus Ihrem Konto).

Weitere Aktionen für die Runde: bearbeiten, deaktivieren, ***Druckansicht / PDF*** und
***Duplizieren …***: eine Kopie mit neuem Link und Code, mit Zeiten, Pausen, gesperrten Terminen,
Einstellungen und Übersetzungen, aber ohne Buchungen; die Kopie ist inaktiv. Geben Sie den **ersten Tag
der Kopie** an, verschieben sich alle Tage um denselben Abstand (Tage am Dienstag und Donnerstag bleiben
Dienstag und Donnerstag), praktisch fürs nächste Halbjahr. Ohne Datum behält die Kopie die bisherigen
Daten; weil sie nie aktiv war, wird sie nicht automatisch gelöscht, und Sie können sie in Ruhe vorbereiten.

**Eine Runde löschen** Sie in einem eigenen Abschnitt am Ende ihrer Seite (*Runde löschen*; *Löschen …*
bei den Aktionen springt dorthin). Das löscht die Runde mit allen Tagen, Pausen, Übersetzungen und
Buchungen und lässt sich nicht rückgängig machen. Hat die Runde Buchungen, haken Sie vorher
*inkl. N Buchung(en)* an: Ohne den Haken wird nichts gelöscht, auch wenn Ihr Browser den
Bestätigungsdialog nicht anzeigt. Sollen nur keine neuen Buchungen mehr möglich sein, wählen Sie
*Stattdessen deaktivieren*: Tage und Buchungen bleiben erhalten, Eltern können weiterhin absagen.

## Übersetzungen

Eltern sehen Titel und Beschreibung in ihrer Sprache, wenn eine Übersetzung vorhanden ist. Unter
*Übersetzungen von Titel und Beschreibung* können Sie:

- ***Im Browser übersetzen***: Chrome ab 138 oder Edge ab 148 am Computer
  übersetzen mit einem lokalen Modell auf Ihrem Gerät. Der Text wird an keinen Dienst gesendet; nur das
  Sprachmodell wird einmal pro Sprache heruntergeladen (beim ersten Mal ein Klick pro Sprache);
- Übersetzungen von Hand eingeben oder korrigieren, eine veraltete Übersetzung als weiterhin korrekt
  bestätigen oder eine Sprache bewusst unübersetzt lassen (leerer Titel).

Fehlende oder veraltete Übersetzungen zeigen das Original. Der Admin-Bereich und die Druckansicht sind
mit `translate="no"` gekennzeichnet, damit Browser dort ihre eigene Funktion „Seite übersetzen“ nicht
anbieten: Sie würde die ganze Seite einschließlich der Buchungen an Google oder Microsoft schicken.
Browser oder Erweiterungen, die diese Kennzeichnung missachten, könnten das trotzdem tun – bitte
übersetzen Sie Admin-Seiten deshalb auch nicht auf anderem Weg.

## Zusammenfassungen per E-Mail

Unter *Mein Profil* legen Sie fest, ob Sie abends eine Übersicht über den nächsten Tag und nach dem
Buchungsschluss eines Tages eine Übersicht erhalten. Sie nennen je Runde und Tag die Zahl der Termine mit
der ersten und letzten Uhrzeit, Auffälliges und den Link zur Liste im Verwaltungsbereich:

```
Elternsprechtag 7b – Montag, 17.08.2026
  6 Termine zwischen 14:00 und 15:30
  Auffällig: 2× gleicher Name, 1× ohne E-Mail-Adresse
  Übersicht und Druckansicht: https://termine.example.org/admin/round/5
```

*Auffällig* zählt denselben Namen oder dieselbe E-Mail-Adresse mehrmals (Geschwister, oder jemand bucht
viele Termine), Eltern, die ohne E-Mail-Adresse gebucht haben (sie erfahren nichts von Änderungen),
E-Mails, die nicht zugestellt werden konnten, und Termine, die nicht mehr zum Zeitplan passen.

Die E-Mails enthalten **keine Namen** und nie die E-Mail-Adressen der Eltern: Sie bleiben in Postfächern
und Kalendern, die die Löschregeln Ihrer Schule womöglich nicht erreichen. Die Namen stehen in der Liste
hinter dem Link (nach der Anmeldung). Nur wenn Ihre Schule es erlaubt (ein Admin setzt `summary_names`),
können Sie im Profil *Mit Namen* ankreuzen: Dann nennen die E-Mails Uhrzeit und Name jedes Termins und
bringen eine Kalenderdatei der Termine mit, die selbst keine Namen enthält (jeder Termin trägt den Titel
der Runde). Löschen Sie solche E-Mails, sobald Sie sie nicht mehr brauchen, spätestens zum Ende des
Schuljahres, und verwenden Sie für Ihr Konto Ihre dienstliche E-Mail-Adresse. Bei Konten von vor dieser
Änderung sagt ein Hinweis in der Übersicht, was sich geändert hat. Erlaubt die Schule Namen, behalten Ihre
Zusammenfassungen sie, und Sie können sie im Profil abschalten. Erlaubt sie sie nicht, sind sie ab dem
Update ohne Namen; sobald Sie den Hinweis bestätigen (*Verstanden*), gilt Ihr Konto wie ein neues: Erlaubt
die Schule Namen später, kreuzen Sie *Mit Namen* selbst an.

### Antworten der Eltern

Antworten der Eltern auf die E-Mails zu ihrem Termin gehen an Ihre E-Mail-Adresse (Reply-To), Eltern sehen
sie also, sobald sie antworten. Unter *Mein Profil → Antwortadresse für Eltern* können Sie dafür eine
andere Adresse angeben, z. B. Ihre dienstliche Adresse oder das Sekretariat; wie bei der E-Mail-Adresse
fragt die Änderung nach Ihrem Passwort. Erlaubt die Schule nur ihre eigenen Adressen
(`allowed_account_domains`), müssen beide bei ihrer Domain liegen.

### Links aus den E-Mails

Der Link in einer Zusammenfassung öffnet die Seite direkt. Sind Sie angemeldet, öffnet sie sich angemeldet
(bei Links von anderen Websites, etwa aus einem Webmailer, hält der Browser das Sitzungs-Cookie zurück;
eine kurze Zwischenseite *Weiterleitung* öffnet die Seite deshalb noch einmal von TalkSlot aus). Sind Sie
nicht angemeldet, melden Sie sich an und landen auf dieser Seite, nicht in der Übersicht.
