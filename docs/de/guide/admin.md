---
title: Anleitung für Admins
description: Das Admin-Konto richtet die Plattform ein, lädt Lehrkräfte ein und ist für die Rechtstexte verantwortlich.
---

Es gibt genau **ein Admin-Konto**. Es wird bei der Installation angelegt. Der Admin ist für alle
Terminrunden Verantwortlicher im Sinne der DSGVO; die Lehrkräfte nutzen die Plattform in seinem Auftrag.
Die Bezeichnungen unten sind die des Admin-Bereichs.

![Admin-Übersicht über alle Runden]({{ '/assets/img/admin-dashboard.webp' | relative_url }})

## Erste Schritte

1. **Profil** (*Mein Profil*): Tragen Sie den Anzeigenamen (wird den Eltern bei Ihren eigenen Runden
   angezeigt), Ihre E-Mail-Adresse und vor allem die *Angaben für Impressum und Datenschutz* ein: Name der
   Schule bzw. Person, vollständige Anschrift und E-Mail-Adresse. Dieser Text ersetzt
   `[[VERANTWORTLICHE]]` in beiden Rechtstexten. Ohne diese Angaben ist **keine Runde öffentlich erreichbar**.
2. **Rechtstexte** (*Texte*): Prüfen Sie Impressum und Datenschutzerklärung für jede Sprache und ersetzen
   Sie die verbleibenden Platzhalter in eckigen Klammern. Details unter [Datenschutz und Recht]({{ '/de/guide/privacy/' | relative_url }}).
3. **E-Mail-Versand prüfen:** *Texte → E-Mail-Versand* zeigt Versandweg und Absender. Machen Sie eine
   Testbuchung mit Ihrer eigenen E-Mail-Adresse und prüfen Sie, ob die Bestätigung ankommt (und nicht als
   Spam markiert wird).
4. **Lehrkräfte einladen** (*Benutzer*).

## Konten für Lehrkräfte

Unter *Benutzer* → *+ Neuer Benutzer* geben Sie **Name und E-Mail-Adresse** ein. Die Lehrkraft erhält
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
- **löschen**, einschließlich aller Runden und Buchungen.

Neue Konten sind immer Lehrkräfte; einen zweiten Admin gibt es nicht.

## Runden aller Lehrkräfte

Der Admin sieht und bearbeitet unter *Alle Runden* die Runden aller Lehrkräfte mit ihren Buchungen. Er kann eine Runde
für eine Lehrkraft anlegen oder eine Runde einer anderen Lehrkraft zuordnen (Feld *Lehrkraft* im
Rundenformular). Die Runde erscheint dann bei dieser Lehrkraft, die Eltern sehen ihren Namen, und die
Zusammenfassungen gehen an sie. Lehrkräfte sehen immer nur ihre eigenen Runden; das wird zentral
durchgesetzt und ist durch Tests abgesichert.

Wie Runden funktionieren, steht in der [Anleitung für Lehrkräfte]({{ '/de/guide/teachers/' | relative_url }}).

## Rechtstexte

Unter *Texte* stehen *Impressum* und Datenschutzerklärung (*Datenschutz*) je Sprache. Sie
werden in einfachem Markdown geschrieben (Überschriften mit `#`, Listen, Fettdruck, Links). Zwei
Platzhalter werden automatisch gefüllt:

| Platzhalter | Wird ersetzt durch |
|---|---|
| `[[VERANTWORTLICHE]]` | die Angaben des Admins für Impressum und Datenschutz |
| `[[HOSTER]]` | den Hoster aus der Konfiguration (`hosting`) |

Für Sprachen ohne eigene Fassung wird die englische verwendet. Hinweise im Dashboard und unter *Texte*
zeigen an, wenn einem Text ein Platzhalter fehlt oder er veraltet ist.

## Benachrichtigungen

Unter *Mein Profil* legt jedes Konto fest, welche ***Zusammenfassungen per E-Mail***
es erhält: abends eine Übersicht über den nächsten Tag (standardmäßig ab 18:00 Uhr) und eine Übersicht nach
dem Buchungsschluss eines Tages. Sie enthalten nur Uhrzeit und Name, keine E-Mail-Adressen der Eltern,
dazu eine Kalenderdatei.
