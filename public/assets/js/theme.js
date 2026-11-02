/*
 * Hell/Dunkel: die im Fuß der Seite gewählte Darstellung vor dem ersten Zeichnen setzen
 * (kein kurzes Aufblitzen). Gespeichert ist nur „light“ oder „dark“ (localStorage
 * „tp_theme“); ohne Eintrag folgt die Seite der Einstellung des Geräts. Umschalten: app.js.
 */
(function () {
  'use strict';
  try {
    var t = window.localStorage.getItem('tp_theme');
    if (t === 'light' || t === 'dark') {
      document.documentElement.setAttribute('data-theme', t);
    }
  } catch (e) { /* Speicher gesperrt: automatisch */ }
})();
