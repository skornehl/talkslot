---
title: Anleitung für Admins
description: Admin-Konten richten die Plattform ein, laden Lehrkräfte ein, sehen alle Runden, pflegen Vorlagen und die Rechtstexte.
---

Das erste **Admin-Konto** wird bei der Installation angelegt; es kann weitere Konten zu Admins machen.
Admins sehen und bearbeiten alle Runden, legen Runden für Lehrkräfte an, verwalten die Konten und pflegen
die Rechtstexte. Verantwortlicher im Sinne der DSGVO ist für alle Terminrunden der Betreiber der Plattform
(meist die Schule); die Lehrkräfte nutzen die Plattform in seinem Auftrag. Die Bezeichnungen unten sind die
des Admin-Bereichs.

![Admin-Übersicht über alle Runden]({{ '/assets/img/admin-dashboard.webp' | relative_url }})

## Erste Schritte

1. **Profil** (*Mein Profil*): Tragen Sie Ihren Anzeigenamen (wird den Eltern bei Ihren eigenen Runden
   angezeigt) und Ihre E-Mail-Adresse ein.
2. **Angaben für Impressum und Datenschutz** (*Texte*): Name der Schule, Vertretung, vollständige Anschrift
   und E-Mail-Adresse. Dieser Text ersetzt `[[VERANTWORTLICHE]]` in beiden Rechtstexten und gilt für alle
   Admins gemeinsam. Ohne diese Angaben ist **keine Runde öffentlich erreichbar**.
3. **Rechtstexte** (*Texte*): Prüfen Sie Impressum und Datenschutzerklärung für jede Sprache und ersetzen
   Sie die verbleibenden Platzhalter in eckigen Klammern. Details unter [Datenschutz und Recht]({{ '/de/guide/privacy/' | relative_url }}).
4. **E-Mail-Versand prüfen:** *Texte → E-Mail-Versand* zeigt Versandweg und Absender. Machen Sie eine
   Testbuchung mit Ihrer eigenen E-Mail-Adresse und prüfen Sie, ob die Bestätigung ankommt (und nicht als
   Spam markiert wird).
5. ***Vorlagen*** (optional): die üblichen Formate einmal anlegen, z. B. Ihren Elternsprechtag.
6. **Lehrkräfte einladen** und bei Bedarf weitere Admins (*Benutzer*).

## Konten

Unter *Benutzer* → *+ Neuer Benutzer* geben Sie **Name und E-Mail-Adresse** ein und wählen die **Rolle**:
*Lehrkraft* (verwaltet ihre eigenen Runden) oder *Admin*. Die Person erhält
eine **Einladungs-E-Mail** mit einem Link, über den sie ein eigenes Passwort festlegt (einmal verwendbar,
standardmäßig 48 Stunden gültig). Passwörter werden nie per E-Mail verschickt. Der Benutzername ist die
E-Mail-Adresse. Kann die E-Mail nicht versendet werden, wird der Link einmalig angezeigt, damit Sie ihn
selbst weitergeben können.

Ein bestehendes Konto können Sie:

- **deaktivieren** (*Konto aktiv* abwählen): keine Anmeldung möglich, die Runden der Lehrkraft sind nicht
  öffentlich erreichbar;
- mit einer neuen **Einladung** oder einem **Link zum Zurücksetzen des Passworts** versehen (*Einladung
  erneut senden* / *Link zum Zurücksetzen senden*). Das alte Passwort gilt dann nicht mehr, und alle
  Sitzungen des Kontos werden beendet;
- in der **Rolle** ändern, also eine Lehrkraft zum Admin machen oder umgekehrt;
- **löschen**, einschließlich aller Runden und Buchungen.

Die eigene Rolle kann niemand ändern, das eigene Konto niemand deaktivieren oder löschen, und mindestens
ein aktiver Admin bleibt immer bestehen.

## Runden aller Lehrkräfte

Admins sehen und bearbeiten unter *Alle Runden* die Runden aller Lehrkräfte mit ihren Buchungen. Sie
können eine Runde für eine Lehrkraft anlegen oder eine Runde einer anderen Lehrkraft zuordnen (Feld *Lehrkraft* im
Rundenformular). Die Runde erscheint dann bei dieser Lehrkraft, die Eltern sehen ihren Namen, und die
Zusammenfassungen gehen an sie. Lehrkräfte sehen immer nur ihre eigenen Runden; das wird zentral
durchgesetzt und ist durch Tests abgesichert.

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
nachvollziehen, wer was getan hat.

Was Lehrkräfte in ihren eigenen Runden tun, erfasst das Protokoll **nicht**, und es enthält nie Daten von
Eltern: Eine Buchung erscheint nur mit Datum und Uhrzeit (z. B. „Buchung 12.11.2026 14:00 gelöscht“).
Einträge werden nach 90 Tagen gelöscht (einstellbar, `audit_log_days`). Nur Admins sehen das Protokoll.

## Rechtstexte

Unter *Texte* stehen *Impressum* und Datenschutzerklärung (*Datenschutz*) je Sprache. Sie
werden in einfachem Markdown geschrieben (Überschriften mit `#`, Listen, Fettdruck, Links). Zwei
Platzhalter werden automatisch gefüllt:

| Platzhalter | Wird ersetzt durch |
|---|---|
| `[[VERANTWORTLICHE]]` | die *Angaben für Impressum und Datenschutz* (oben unter *Texte*) |
| `[[HOSTER]]` | den Hoster aus der Konfiguration (`hosting`) |

Für Sprachen ohne eigene Fassung wird die englische verwendet. Hinweise im Dashboard und unter *Texte*
zeigen an, wenn einem Text ein Platzhalter fehlt oder er veraltet ist.

## Benachrichtigungen

Unter *Mein Profil* legt jedes Konto fest, welche ***Zusammenfassungen per E-Mail***
es erhält: abends eine Übersicht über den nächsten Tag (standardmäßig ab 18:00 Uhr) und eine Übersicht nach
dem Buchungsschluss eines Tages. Sie enthalten nur Uhrzeit und Name, keine E-Mail-Adressen der Eltern,
dazu eine Kalenderdatei.

Eltern mit E-Mail-Adresse bekommen am Vorabend ihres Termins eine **Erinnerung** (zur selben Uhrzeit,
standardmäßig ab 18:00 Uhr) mit einem Link zum Absagen. Sie lässt sich pro Runde abschalten (*Eltern am
Vorabend an ihren Termin erinnern*). Die Vorlage der Datenschutzerklärung nennt die Erinnerung als Zweck der
E-Mail-Adresse; haben Sie Ihre Datenschutzerklärung bearbeitet, bittet ein Hinweis in der Übersicht, das
zu ergänzen.
