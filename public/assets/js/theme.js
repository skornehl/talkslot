/*
 * Light/dark: apply the appearance chosen in the page footer before the first paint
 * (no brief flash). Only "light" or "dark" is stored (localStorage "tp_theme");
 * without an entry the page follows the device setting. Toggling: app.js.
 */
(function () {
  'use strict';
  try {
    var t = window.localStorage.getItem('tp_theme');
    if (t === 'light' || t === 'dark') {
      document.documentElement.setAttribute('data-theme', t);
    }
  } catch (e) { /* storage blocked: automatic */ }
})();
