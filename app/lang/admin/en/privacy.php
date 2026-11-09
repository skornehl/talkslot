<?php
/** English texts of the admin area: German original => English (see App\AdminI18n). Privacy and legal texts. */
return [
    // Data-minimal wording (expert review P2-4): neutral round titles, organisational notes only, the log
    'Bitte neutral halten: Titel und Beschreibung stehen in den E-Mails an Eltern und in Kalenderdateien, der Titel auch im Protokoll und beim Anlegen in der Adresse der Runde. Keine Hinweise auf Förderbedarf, Gesundheit oder andere sensible Themen.'
        => 'Please keep it neutral: title and description appear in the emails to parents and in calendar files, the title also in the log and, when the round is created, in its address. No hints at special educational needs, health or other sensitive topics.',
    'Name des Kindes wie bei der Online-Buchung, bei Bedarf mit Klasse.' => 'The child’s name as in an online booking, with the class if needed.',
    'Organisatorisches' => 'Organisational notes',
    'Erlaubt (nur Hinweis „Bitte buchen Sie nur einen Termin pro Kind.“)' => 'Allowed (only the note “Please book only one appointment per child.”)',
    'Stand: {time} Uhr · Vertraulich – nur Organisatorisches notieren, keine Gesprächsinhalte; nach dem Termin vernichten.'
        => 'As of {time} · Confidential – organisational notes only, nothing about the conversation; destroy after the appointment.',
    'Änderungen durch Admins: wer wann welche Runde, welches Konto oder welchen Text geändert hat. Änderungen von Lehrkräften an ihren eigenen Runden werden nicht erfasst, Namen und E-Mail-Adressen von Eltern nie, Buchungen nur mit ihrem Datum. Einträge werden nach {days} Tagen gelöscht.'
        => 'Changes made by admins: who changed which round, account or text, and when. Changes made by teachers to their own rounds are not recorded, names and email addresses of parents never, bookings with their date only. Entries are deleted after {days} days.',
    'Buchung am {date} eingetragen' => 'booking on {date} entered',
    'Buchung am {date} geändert' => 'booking on {date} changed',
    'Buchung am {date} geändert, auf eine andere Uhrzeit am selben Tag umgebucht' => 'booking on {date} changed, rebooked to another time on the same day',
    'Buchung am {date} geändert, umgebucht auf den {target}' => 'booking on {date} changed, rebooked to {target}',
    'Buchung am {date} gelöscht' => 'booking on {date} deleted',
    // Version markers of the legal texts: newer templates for edited texts
    'Einleitung' => 'Introduction',
    '{count} Abschnitte' => '{count} sections',
    'Die Vorlagen wurden geändert, seit diese Texte auf ihnen beruhen – {texts}. Bearbeitete Texte werden nicht automatisch ersetzt: Beim jeweiligen Text unter „Texte“ sehen Sie, was sich geändert hat, und können die Abschnitte übernehmen.'
        => 'The templates have changed since these texts were based on them – {texts}. Edited texts are never replaced automatically: on each text under “Texts” you can see what has changed and take over the sections.',
    'Diese Texte weichen von der aktuellen Vorlage ab, und es ist nicht bekannt, mit welchem Stand der Vorlage sie zuletzt abgeglichen wurden – {texts}. Bearbeitete Texte werden nicht automatisch ersetzt: Beim jeweiligen Text unter „Texte“ sehen Sie die Unterschiede und können die Abschnitte übernehmen oder den Abgleich bestätigen.'
        => 'These texts differ from the current template, and it is not known which state of the template they were last reconciled with – {texts}. Edited texts are never replaced automatically: on each text under “Texts” you can see the differences and take over the sections or confirm that you have reconciled the text.',
    '{page} ({lang}) geändert und mit dem aktuellen Stand der Vorlage „{template}“ abgeglichen' => '{page} ({lang}) changed and reconciled with the current state of the template “{template}”',
    'Vorlage geändert' => 'template changed',
    'weicht von der Vorlage ab' => 'differs from the template',
    'Abschnitte aus der aktuellen Vorlage „{name}“ übernommen: {sections}.' => 'Sections taken over from the current template “{name}”: {sections}.',
    'Entfernt, weil die Vorlage sie nicht mehr enthält: {sections}.' => 'Removed because the template no longer contains them: {sections}.',
    'Unverändert, weil es sie nur in Ihrem Text gibt (eigene oder aus einer früheren Vorlage – bitte prüfen, ob sie noch zutreffen): {sections}.'
        => 'Left as they are because only your text has them (your own or from an earlier template – please check whether they still apply): {sections}.',
    'Ihre Angaben in Zeilen, die die Vorlage nicht geändert hat, bleiben erhalten, auch wo sie in den Zeilen darunter weitergehen; eigene Änderungen in übernommenen Abschnitten nicht. Der gespeicherte Text ändert sich erst, wenn Sie speichern – bitte vorher prüfen und neue Platzhalter in eckigen Klammern ersetzen.'
        => 'Your details in lines the template did not change are kept, also where they go on in the lines below; your own changes in the sections taken over are not. The stored text only changes when you save – please check it first and replace new placeholders in square brackets.',
    'Aus Ihrem Text nicht übernommen (eigene Zeilen oder Ihre Angaben in Zeilen, die die Vorlage geändert hat) – bitte prüfen, ob etwas davon wieder in den Text gehört:'
        => 'Not taken over from your text (your own lines, or your details in lines the template changed) – please check whether any of it belongs back in the text:',
    'nicht übernommen:' => 'not taken over:',
    'Zum Vergleich die Unterschiede vor dem Übernehmen:' => 'For comparison, the differences before taking them over:',
    'Abschnitte, die es nur in Ihrem Text gibt, bleiben beim Übernehmen unverändert.' => 'Sections only your text has stay as they are.',
    'Diese Abschnitte gibt es nur in Ihrem Text. Prüfen Sie, ob sie noch zutreffen, passen Sie sie unten an oder löschen Sie sie, und bestätigen Sie den Abgleich beim Speichern.'
        => 'Only your text has these sections. Check whether they still apply, adjust or delete them below, and confirm the reconciliation when saving.',
    'Text gespeichert, aber nicht als abgeglichen vermerkt: Die Vorlage wurde geändert, während das Formular offen war (z. B. durch ein Update). Bitte prüfen Sie den Hinweis zur Vorlage erneut.'
        => 'Text saved, but not recorded as reconciled: the template changed while the form was open (e.g. by an update). Please check the notice on the template again.',
    'Die Vorlage „{name}“ wurde geändert, seit dieser Text auf ihr beruht. Bearbeitete Texte werden nicht automatisch ersetzt. Geändert hat sich (− bisher in der Vorlage, + neu in der Vorlage):'
        => 'The template “{name}” has changed since this text was based on it. Edited texts are never replaced automatically. What has changed (− previously in the template, + new in the template):',
    'Mit welchem Stand der Vorlage „{name}“ dieser Text zuletzt abgeglichen wurde, ist nicht bekannt: Er wurde bearbeitet, bevor TalkSlot das festgehalten hat, oder stammt aus der Vorlage der anderen Betriebsart. In diesen Abschnitten weicht er von der aktuellen Vorlage ab (− nur in Ihrem Text, + nur in der Vorlage; ausgefüllte Platzhalter zählen nicht als Abweichung):'
        => 'It is not known which state of the template “{name}” this text was last reconciled with: it was edited before TalkSlot kept track of that, or it comes from the template of the other operator type. It differs from the current template in these sections (− only in your text, + only in the template; filled-in placeholders do not count as a difference):',
    'neu in der Vorlage:' => 'new in the template:',
    'nur in der Vorlage:' => 'only in the template:',
    'bisher in der Vorlage:' => 'previously in the template:',
    'nur in Ihrem Text:' => 'only in your text:',
    'Abschnitte aus der Vorlage übernehmen' => 'Take over the sections from the template',
    'Übernehmen lädt Ihren Text mit diesen Abschnitten in der Fassung der Vorlage in das Formular; Ihre Angaben in Zeilen, die die Vorlage nicht geändert hat, bleiben. Gespeichert wird erst mit „Speichern“. Oder Sie passen den Text unten selbst an und bestätigen den Abgleich beim Speichern.'
        => 'Taking them over puts your text into the form with these sections as worded in the template; your details in lines the template did not change are kept. Nothing is stored until you click “Save”. Or adjust the text below yourself and confirm the reconciliation when saving.',
    'Abgleich erledigt: Der Text berücksichtigt den aktuellen Stand der Vorlage „{name}“ (der Hinweis oben verschwindet nach dem Speichern).'
        => 'Reconciled: the text takes the current state of the template “{name}” into account (the notice above disappears once you save).',
];
