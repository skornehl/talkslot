---
title: FAQ
---

## Können Eltern ohne E-Mail-Adresse buchen?

Ja. Die E-Mail-Adresse ist optional. Ohne sie erhalten Eltern ihren persönlichen Änderungslink auf der
Bestätigungsseite. Dort können sie ihn kopieren, den Termin in ihren Kalender eintragen (der Link steht im
Kalendereintrag) oder sich die Bestätigung doch noch an eine E-Mail-Adresse schicken lassen; diese Adresse
wird nicht gespeichert.

## Erfahren Eltern, wenn eine Lehrkraft ihren Termin absagt oder verlegt?

Ja, per E-Mail in ihrer Sprache, sofern sie eine Adresse angegeben haben: eine Absage, wenn die Lehrkraft
die Buchung, ihren Tag oder die Runde löscht (oder ein Admin das Konto der Lehrkraft), und die neuen Daten,
wenn ein Tag ein anderes Datum, eine andere Uhrzeit oder einen anderen Ort bekommt oder die Runde einen
anderen Ort oder eine andere Lehrkraft. *Eltern per E-Mail informieren* ist standardmäßig angehakt. Eltern
ohne Adresse und Einträge, zu denen die Lehrkraft nie eine E-Mail geschickt hat, nennt die Meldung danach,
damit die Lehrkraft sie persönlich informiert. Siehe die
[Anleitung für Lehrkräfte]({{ '/de/guide/teachers/' | relative_url }}).

## Kann ich TalkSlot auch für etwas anderes als Elternsprechtage nutzen?

Die Begriffe sind auf Schulen zugeschnitten („Lehrkraft“, „Eltern“), aber es passt überall, wo eine Person
Zeitfenster anbietet und andere eines davon buchen: Sprechstunden, Anmeldegespräche an Schulen,
Praktikumsgespräche.

## Braucht es einen Cronjob?

Ja, damit Buchungen rechtzeitig gelöscht sowie Zusammenfassungen, Erinnerungen und erneut versuchte
Bestätigungen verschickt werden: Außer den sofort verschickten Bestätigungen geht keine E-Mail hinaus, während ein
Besucher wartet. Ohne
Shell-Zugang nutzen Sie den Web-Cron (der mitgelieferte GitHub-Workflow ruft ihn stündlich auf). Als
Rückfallebene läuft das Aufräumen außerdem bei Seitenaufrufen, höchstens alle 15 Minuten, und mit PHP-FPM
oder LiteSpeed verschickt ein Seitenaufruf nach seiner Antwort bis zu 10 wartende E-Mails.

## In welchen Sprachen gibt es den Adminbereich?

Auf Deutsch und Englisch. Jedes Konto wählt seine Sprache unter *Mein Profil* (*Sprache / Language*);
E-Mails an Lehrkräfte (Einladung, Passwort-Link, Zusammenfassungen) folgen ihr. Vor der Anmeldung
entscheidet die Sprache des Browsers, `admin_language` legt den Standard fest. Elternseiten und E-Mails an
Eltern sind in sechs Sprachen übersetzt, weil Eltern ganz unterschiedlicher Herkunft sind.

## Eine Lehrkraft kann sich nicht anmelden, obwohl das Passwort stimmt

Nach zu vielen Fehlversuchen lehnt TalkSlot weitere eine Zeit lang ab, auch mit dem richtigen Passwort: aus
demselben Netz (gekürzte IP-Adresse) für 15 Minuten, und für ein Konto nach 20 Fehlversuchen innerhalb
einer Stunde von Geräten, die sich dort noch nie angemeldet haben. Geräte, die sich schon einmal angemeldet
haben, sind von diesem zweiten Limit ausgenommen, und das Konto bekommt eine E-Mail, wenn es erreicht ist.
Auswege: warten, ein bekanntes Gerät nutzen oder über *Passwort vergessen?* ein neues Passwort festlegen;
danach kann sich dieser Browser sofort anmelden.

## Wie füge ich eine Sprache für Eltern hinzu?

Kopieren Sie `app/lang/en.php`, übersetzen Sie die Datei, legen Sie eine Flagge unter
`public/assets/flags/` ab (für das Übersetzungsformular im Adminbereich) und tragen Sie den Code in
`languages` ein.
Siehe [Konfiguration]({{ '/de/guide/configuration/' | relative_url }}#languages).

## Funktioniert es mit MySQL, PostgreSQL oder SQLite?

Nein. TalkSlot braucht MariaDB 10.6 oder neuer; die Migrationen verwenden MariaDB-Syntax.

## Funktioniert es mit nginx?

Die mitgelieferten Regeln sind für Apache (`public/.htaccess`). Mit nginx brauchen Sie gleichwertige
Regeln: Alle Anfragen, die keine existierenden Dateien sind, an `index.php` leiten, versteckte Dateien
und `app_root.php` sperren und die Sicherheits-Header senden.

## Warum so viele Tests für eine so kleine Anwendung?

Weil sie personenbezogene Daten von Familien verarbeitet und dort läuft, wo man nicht einfach debuggen
kann: auf Shared Hosting, oft von Ehrenamtlichen betreut. Jeder Fehler, den ein Test findet, ist einer,
den eine Schule nicht am Abend des Elternsprechtags findet.

## Gibt es eine gehostete Version?

Nein. TalkSlot ist dafür gedacht, von der Schule oder ihrem IT-Dienstleister betrieben zu werden, damit
die Daten unter deren Kontrolle bleiben.

## Was bedeutet die Lizenz für mich?

TalkSlot steht unter der [GNU AGPL v3](https://github.com/skornehl/talkslot/blob/main/LICENSE). Sie
dürfen es frei nutzen, ändern und hosten. Wenn Sie eine **veränderte** Version für andere betreiben,
müssen Sie ihnen den Quellcode Ihrer Änderungen anbieten.
