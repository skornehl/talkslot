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
- **Erinnerung** (*Eltern am Vorabend an ihren Termin erinnern*, standardmäßig an): Eltern mit
  E-Mail-Adresse bekommen am Vorabend eine Erinnerung mit dem Link zum Absagen. Wer erst am selben Tag
  gebucht hat, bekommt keine, die Bestätigung kam ja gerade erst.
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
- einen fertigen **Einladungstext** mit Link und Code (*Einladungstext kopieren*, *Als E-Mail öffnen*).

Mit *Nur mit Rundencode* zeigt der Link allein nur eine Code-Abfrage; Titel und Termine bleiben für Fremde
verborgen. Ein neuer Code (beim Bearbeiten) macht den alten sofort ungültig; bestehende Buchungen bleiben
über ihre persönlichen Änderungslinks erreichbar.

## Buchungen verwalten

![Seite einer Runde mit Buchungen]({{ '/assets/img/admin-round.webp' | relative_url }})

Pro Tag sehen Sie alle Termine mit den Buchungen:

- ***Bearbeiten***: Name oder E-Mail-Adresse ändern oder die Buchung auf einen anderen
  Termin **verschieben**, optional mit einer E-Mail an die Eltern.
- ***Löschen***: eine Buchung entfernen.
- ***Eintragen***: selbst einen Termin eintragen, z. B. nach einem Telefonat, optional mit
  Bestätigungs-E-Mail.
- ***Sperren***: einen Termin sperren, damit niemand ihn buchen kann, und wieder freigeben.
- **Tage**: Zeiten und den Ort eines einzelnen Tages bearbeiten (*Tag bearbeiten*), Pausen hinzufügen,
  einen Tag drucken (*Tag drucken*), weitere Tage hinzufügen (*+ Tag hinzufügen*) oder einen Tag löschen.

Wenn Sie Zeiten oder Pausen ändern, werden **gebuchte Termine nie gelöscht**; Termine, die nicht mehr
passen, werden mit ⚠ markiert, damit Sie sie verschieben können.

Weitere Aktionen für die Runde: bearbeiten, deaktivieren, ***Druckansicht / PDF***, löschen und
***Duplizieren …***: eine Kopie mit neuem Link und Code, mit Zeiten, Pausen, gesperrten Terminen,
Einstellungen und Übersetzungen, aber ohne Buchungen; die Kopie ist inaktiv. Geben Sie den **ersten Tag
der Kopie** an, verschieben sich alle Tage um denselben Abstand (Tage am Dienstag und Donnerstag bleiben
Dienstag und Donnerstag), praktisch fürs nächste Halbjahr. Ohne Datum behält die Kopie die bisherigen
Daten.

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
Buchungsschluss eines Tages eine Übersicht erhalten. Sie enthalten Uhrzeit und Name, ohne die
E-Mail-Adressen der Eltern, sowie eine Kalenderdatei. Antworten der Eltern auf ihre Bestätigungs-E-Mails
gehen an Ihre E-Mail-Adresse (Reply-To).
