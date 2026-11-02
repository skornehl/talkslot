/*
 * TalkSlot – kleines, abhängigkeitsfreies Skript.
 *
 * Browser-Merker (localStorage, Schlüssel "tp_bookings"):
 * Gespeichert wird nur, wenn Eltern beim Buchen „Meinen Termin auf diesem Gerät
 * merken“ ankreuzen: { <runden-id>: { u: <link zur terminseite>, l: <terminbezeichnung> } }.
 * Es wird nichts an den Server übertragen. Seiten funktionieren auch ohne JavaScript.
 *
 * Verwaltung (Lehrkraft): Übersetzung im Browser über die Translator API; sessionStorage
 * nur als Schleifenschutz für die automatische Übersetzung und für einen Fehlerhinweis.
 */
(function () {
  'use strict';

  var KEY = 'tp_bookings';

  function load() {
    try {
      var data = JSON.parse(window.localStorage.getItem(KEY) || '{}');
      return data && typeof data === 'object' ? data : {};
    } catch (e) {
      return {};
    }
  }

  function save(data) {
    try {
      if (Object.keys(data).length) {
        window.localStorage.setItem(KEY, JSON.stringify(data));
      } else {
        window.localStorage.removeItem(KEY);
      }
    } catch (e) { /* Speicher nicht verfügbar – Merker entfällt */ }
  }

  // Nach der Buchung: Termin merken (nur mit ausdrücklicher Zustimmung)
  var remember = document.querySelector('[data-remember-round]');
  if (remember) {
    var d1 = load();
    d1[remember.getAttribute('data-remember-round')] = {
      u: remember.getAttribute('data-remember-url'),
      l: remember.getAttribute('data-remember-label')
    };
    save(d1);
  }

  // Termin geändert: vorhandenen Merker aktualisieren (nie neu anlegen)
  var update = document.querySelector('[data-update-round]');
  if (update) {
    var d2 = load();
    var id2 = update.getAttribute('data-update-round');
    // nur denselben Termin (gleicher Link) aktualisieren, nie einen fremden übernehmen
    if (d2[id2] && String(d2[id2].u || '').split('?')[0] === String(update.getAttribute('data-remember-url')).split('?')[0]) {
      d2[id2] = { u: update.getAttribute('data-remember-url'), l: update.getAttribute('data-remember-label') };
      save(d2);
    }
  }

  // Termin abgesagt: Merker entfernen
  var forget = document.querySelector('[data-forget-round]');
  if (forget) {
    var d3 = load();
    delete d3[forget.getAttribute('data-forget-round')];
    save(d3);
  }

  // Rundenseite: statt der Terminliste den eigenen Termin zeigen
  var round = document.querySelector('[data-round-id]');
  if (round) {
    var roundId = round.getAttribute('data-round-id');
    var entry = load()[roundId];
    var box = document.getElementById('remembered');
    var area = document.getElementById('booking-area');
    if (entry && entry.u && box && area && !document.getElementById('form-errors')) {
      box.querySelector('[data-remembered-label]').textContent = entry.l || '';
      var link = box.querySelector('[data-remembered-link]');
      // Nur Links auf eigene Termine dieser Seite zulassen (/b/<token>, ggf. mit Unterverzeichnis und Sprache)
      if (/^(\/[A-Za-z0-9_-]+)*\/b\/[A-Za-z0-9_-]{43}(\?lang=[a-z]{2})?$/.test(entry.u)) {
        link.setAttribute('href', entry.u);
      } else {
        link.hidden = true;
      }
      box.hidden = false;
      area.hidden = true;
      box.querySelector('[data-remembered-forget]').addEventListener('click', function () {
        var d4 = load();
        delete d4[roundId];
        save(d4);
        box.hidden = true;
        area.hidden = false;
      });
    }
  }

  // Fehlerbox nach dem Absenden fokussieren (Screenreader)
  var errors = document.getElementById('form-errors');
  if (errors) {
    errors.focus();
  }

  // Link kopieren (Admin)
  Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      var original = btn.textContent;
      var done = function () {
        btn.textContent = 'Kopiert ✓';
        setTimeout(function () { btn.textContent = original; }, 2000);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, function () { window.prompt('Link kopieren:', text); });
      } else {
        window.prompt('Link kopieren:', text);
      }
    });
  });

  // Sicherheitsabfrage vor Löschaktionen
  Array.prototype.forEach.call(document.querySelectorAll('form[data-confirm]'), function (form) {
    form.addEventListener('submit', function (ev) {
      if (!window.confirm(form.getAttribute('data-confirm'))) {
        ev.preventDefault();
      }
    });
  });

  // Doppeltes Absenden verhindern (z. B. Doppelklick, während die Übersetzung läuft).
  // Nach „Zurück“ aus dem Browser-Cache darf wieder abgesendet werden.
  Array.prototype.forEach.call(document.querySelectorAll('form[data-once]'), function (form) {
    form.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) { return; }
      if (form.getAttribute('data-sent') === '1') { ev.preventDefault(); return; }
      form.setAttribute('data-sent', '1');
    });
  });
  window.addEventListener('pageshow', function () {
    Array.prototype.forEach.call(document.querySelectorAll('form[data-sent]'), function (form) {
      form.removeAttribute('data-sent');
    });
  });

  // Übersetzen im Browser der Lehrkraft (Translator API in Chrome/Edge am Computer).
  // Titel und Beschreibung werden auf dem eigenen Gerät mit einem lokalen Modell übersetzt,
  // kein Text geht an einen Übersetzungsdienst; das Ergebnis wird je Sprache an den eigenen
  // Server geschickt. Chrome erlaubt pro Klick nur einen Download eines Sprachpakets: Ein
  // Klick übersetzt alle schon bereiten Sprachen und lädt höchstens eine weitere; bereite
  // Sprachen werden auch ohne Klick übersetzt.
  Array.prototype.forEach.call(document.querySelectorAll('form[data-browser-translate]'), function (form) {
    var api = self.Translator;
    var brands = (navigator.userAgentData && navigator.userAgentData.brands) || [];
    var onDevice = brands.some(function (b) { return /^(Google Chrome|Microsoft Edge|Chromium)$/.test(b.brand); });
    if (!api || typeof api.create !== 'function' || typeof api.availability !== 'function' || !onDevice) {
      return; // Hinweis „von Hand eintragen“ bleibt sichtbar
    }
    var list = function (attr) { return (form.getAttribute(attr) || '').split(',').filter(Boolean); };
    var source = form.getAttribute('data-source');
    var title = form.getAttribute('data-title') || '';
    var description = form.getAttribute('data-description') || '';
    var pending = list('data-pending');
    var all = list('data-all');
    var status = form.querySelector('[data-bt-status]');
    var btnPending = form.querySelector('[data-bt-pending]');
    var btnAll = form.querySelector('[data-bt-all]');
    var btnNext = form.querySelector('[data-bt-next]');
    var hint = document.querySelector('[data-bt-hint]');
    var state = {};     // Sprache → availability() bzw. 'done'
    var saved = [];
    var failed = [];
    var busy = false;
    var armedUntil = 0;
    if (hint) { hint.hidden = true; }
    form.hidden = false;

    function say(text, kind) {
      status.textContent = text;
      status.className = 'alert alert-' + (kind || 'info');
      status.hidden = text === '';
    }
    function upper(langs) { return langs.map(function (l) { return l.toUpperCase(); }).join(', '); }
    function lock(on) {
      [btnPending, btnAll, btnNext].forEach(function (b) { if (b) { b.disabled = on; } });
    }
    function store(key, value) {
      try { if (value === null) { window.sessionStorage.removeItem(key); } else { window.sessionStorage.setItem(key, value); } return true; } catch (e) { return false; }
    }
    function load(key) {
      try { return window.sessionStorage.getItem(key); } catch (e) { return null; }
    }
    function signal(ms) {
      return typeof AbortSignal !== 'undefined' && typeof AbortSignal.timeout === 'function' ? AbortSignal.timeout(ms) : undefined;
    }
    // Zeilenweise, damit Absätze und Aufzählungen erhalten bleiben
    function translateText(translator, text, sig) {
      return Promise.all(text.split('\n').map(function (line) {
        return line.trim() === '' ? Promise.resolve(line) : translator.translate(line, sig ? { signal: sig } : undefined);
      })).then(function (lines) { return lines.join('\n'); });
    }

    // create() wird sofort (noch im Klick) aufgerufen; Downloads brauchen diese Nutzeraktion
    function translateOne(lang, download) {
      var sig = signal(download ? 300000 : 60000);
      var shown = -10;
      var opts = {
        sourceLanguage: source,
        targetLanguage: lang,
        monitor: function (m) {
          m.addEventListener('downloadprogress', function (e) {
            var pct = Math.round((e.loaded || 0) * 100);
            if (pct - shown >= 10 || pct === 100) { shown = pct; say('Lade Sprachpaket ' + lang.toUpperCase() + ' … ' + pct + ' %'); }
          });
        }
      };
      if (sig) { opts.signal = sig; }
      return api.create(opts).then(function (translator) {
        return Promise.all([translateText(translator, title, sig), translateText(translator, description, sig)]).then(function (out) {
          if (typeof translator.destroy === 'function') { translator.destroy(); }
          return { lang: lang, title: out[0], description: out[1] };
        });
      });
    }

    // Gespeicherte Übersetzung sofort im Formular „Übersetzungen“ zeigen (Felder, Status, Zähler)
    function applyRow(lang, row) {
      var fs = document.querySelector('fieldset[lang="' + lang + '"]');
      if (!fs) { return; }
      var set = function (el, value) { if (el) { el.value = value; el.defaultValue = value; } };
      set(fs.querySelector('[name="t_title[' + lang + ']"]'), row.title);
      set(fs.querySelector('[name="t_description[' + lang + ']"]'), row.description);
      set(fs.querySelector('[name="t_seen[' + lang + ']"]'), row.seen);
      var badge = fs.querySelector('legend .status');
      if (badge) { badge.textContent = 'automatisch'; badge.className = 'status status-on'; }
      var confirmBox = fs.querySelector('[name="t_confirm[' + lang + ']"]');
      if (confirmBox && confirmBox.parentNode) { confirmBox.parentNode.remove(); }
      fs.classList.add('bt-updated');
      var all = document.querySelectorAll('fieldset[lang] legend .status');
      var ok = document.querySelectorAll('fieldset[lang] legend .status.status-on');
      var counter = document.querySelector('[data-bt-counter]');
      if (counter) { counter.textContent = '(' + ok.length + ' von ' + all.length + ' aktuell)'; }
    }

    function saveOne(r, force) {
      var body = new FormData(form); // enthält das CSRF-Token
      body.append('hash', form.getAttribute('data-hash'));
      if (force) { body.append('force', '1'); }
      body.append('t_title[' + r.lang + ']', r.title);
      body.append('t_description[' + r.lang + ']', r.description);
      return fetch(form.getAttribute('action'), { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (res) {
          if (res.status === 409) { throw new Error('outdated'); }
          if (!res.ok || (res.headers.get('Content-Type') || '').indexOf('application/json') !== 0) { throw new Error('save'); }
          return res.json();
        })
        .then(function (json) {
          Object.keys((json && json.rows) || {}).forEach(function (lang) { applyRow(lang, json.rows[lang]); });
          return json;
        });
    }

    function finish() {
      busy = false;
      lock(false);
      if (btnNext) { btnNext.hidden = true; }
      var parts = [];
      if (saved.length) { parts.push('Fertig: ' + upper(saved) + ' im Browser übersetzt und gespeichert – unten prüfen und bei Bedarf korrigieren.'); }
      if (failed.length) { parts.push('Nicht im Browser übersetzbar: ' + upper(failed) + ' – bitte unten von Hand eintragen.'); }
      if (saved.length && btnPending && pending.every(function (l) { return state[l] === 'done' || failed.indexOf(l) >= 0; })) {
        btnPending.hidden = true;
      }
      say(parts.join(' '), failed.length ? 'warning' : 'success');
    }

    // Ein Durchgang: alle bereiten Sprachen + höchstens ein Download (nur mit Klick)
    function step(queue, force, clicked) {
      if (busy) { return; }
      var todo = queue.filter(function (l) { return state[l] !== 'done' && failed.indexOf(l) < 0; });
      todo.filter(function (l) { return state[l] === 'unavailable'; }).forEach(function (l) { failed.push(l); });
      var ready = todo.filter(function (l) { return state[l] === 'available'; });
      var downloads = todo.filter(function (l) { return state[l] !== 'available' && state[l] !== 'unavailable'; });
      var run = ready.map(function (l) { return { lang: l, download: false }; });
      if (clicked && downloads.length) { run.push({ lang: downloads[0], download: true }); }
      if (!run.length) { return finish(); }
      busy = true;
      lock(true);
      if (btnNext) { btnNext.hidden = true; }
      say('Übersetze im Browser …');
      var outdated = false;
      var waitClick = [];
      var jobs = run.map(function (job) {
        return translateOne(job.lang, job.download)
          .then(function (r) { return saveOne(r, force); })
          .then(function () { state[job.lang] = 'done'; saved.push(job.lang); say('Übersetzt und gespeichert: ' + upper(saved) + ' …'); })
          .catch(function (err) {
            if (err && err.message === 'outdated') { outdated = true; return; }
            if (err && err.name === 'NotAllowedError') { waitClick.push(job.lang); return; } // braucht einen eigenen Klick
            if (err && err.message === 'save') { throw err; }
            failed.push(job.lang);
          });
      });
      Promise.all(jobs).then(function () {
        if (outdated) {
          busy = false;
          lock(false);
          say('Titel oder Beschreibung wurden inzwischen geändert. Bitte die Seite neu laden.', 'warning');
          return;
        }
        var left = queue.filter(function (l) { return state[l] !== 'done' && failed.indexOf(l) < 0 && state[l] !== 'unavailable'; });
        if (left.length && btnNext) {
          busy = false;
          lock(false);
          btnNext.hidden = false;
          if (btnPending) { btnPending.hidden = true; } // nur noch ein Knopf zum Weitermachen
          btnNext.textContent = 'Nächste Sprache laden: ' + left[0].toUpperCase() + (left.length > 1 ? ' (noch ' + left.length + ')' : '');
          btnNext.onclick = function () { step(queue, force, true); };
          say((saved.length ? 'Übersetzt und gespeichert: ' + upper(saved) + '. ' : '') +
            'Für jedes weitere Sprachpaket einmal auf „' + btnNext.textContent + '“ klicken (der Browser lädt es nur nach einem Klick).');
          return;
        }
        busy = false;
        finish();
      }).catch(function () {
        busy = false;
        lock(false);
        say('Die Übersetzungen konnten nicht gespeichert werden. Bitte die Seite neu laden und erneut versuchen.', 'error');
      });
    }

    if (btnPending) {
      btnPending.addEventListener('click', function () { step(pending, false, true); });
    }
    if (btnAll) {
      // Bestätigung auf der Seite (ein Dialog würde die Klick-Erlaubnis für Downloads verbrauchen)
      var label = btnAll.textContent;
      btnAll.addEventListener('click', function () {
        if (Date.now() > armedUntil) {
          armedUntil = Date.now() + 8000;
          btnAll.textContent = btnAll.getAttribute('data-bt-confirm');
          window.setTimeout(function () { if (Date.now() > armedUntil) { btnAll.textContent = label; } }, 8100);
          return;
        }
        armedUntil = 0;
        btnAll.textContent = label;
        state = Object.keys(state).reduce(function (o, k) { o[k] = state[k] === 'done' ? 'available' : state[k]; return o; }, {});
        saved = [];
        failed = [];
        step(all, true, true);
      });
    }

    // Verfügbarkeit vorab abfragen; bereite Sprachen ohne Klick übersetzen – je Stand nur einmal
    // (verhindert Schleifen, falls das Speichern scheitert)
    Promise.all(all.map(function (lang) {
      return api.availability({ sourceLanguage: source, targetLanguage: lang })
        .catch(function () { return 'unavailable'; })
        .then(function (st) { state[lang] = st; });
    })).then(function () {
      var key = 'tp_bt_auto_' + form.getAttribute('data-hash');
      var ready = pending.filter(function (l) { return state[l] === 'available'; });
      if (ready.length && load(key) !== '1' && store(key, '1')) {
        step(pending, false, false);
      } else if (pending.length && !ready.length && btnPending) {
        say('Zum Übersetzen auf „Im Browser übersetzen“ klicken. Beim ersten Mal lädt der Browser je Sprache ein Sprachpaket (je ein Klick).');
      }
    });
  });

  // Drucken
  Array.prototype.forEach.call(document.querySelectorAll('[data-print]'), function (btn) {
    btn.addEventListener('click', function () { window.print(); });
  });
})();

/*
 * Termintage: weitere Datums-/Pausenfelder und Live-Vorschau der entstehenden Termine
 * (gleiche Berechnung wie SlotGenerator::compute auf dem Server).
 */
(function () {
  'use strict';
  function minutes(v) {
    var m = /^(\d{2}):(\d{2})/.exec(v || '');
    return m ? parseInt(m[1], 10) * 60 + parseInt(m[2], 10) : null;
  }
  function hhmm(m) {
    return ('0' + Math.floor(m / 60)).slice(-2) + ':' + ('0' + (m % 60)).slice(-2);
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-day-fields]'), function (box) {
    var preview = box.querySelector('[data-slot-preview]');
    function update() {
      var from = minutes(box.querySelector('[name="start_time"]').value);
      var to = minutes(box.querySelector('[name="end_time"]').value);
      var len = parseInt(box.querySelector('[name="slot_minutes"]').value, 10);
      if (from === null || to === null || !(len >= 5) || from >= to) { preview.hidden = true; return; }
      var starts = box.querySelectorAll('[name="break_start[]"]');
      var ends = box.querySelectorAll('[name="break_end[]"]');
      var breaks = [];
      for (var i = 0; i < starts.length; i++) {
        var bs = minutes(starts[i].value), be = minutes(ends[i].value);
        if (bs !== null && be !== null && bs < be) { breaks.push([bs, be]); }
      }
      var slots = [], t = from;
      while (t + len <= to) {
        var jump = null;
        for (var j = 0; j < breaks.length; j++) {
          if (t < breaks[j][1] && t + len > breaks[j][0]) { jump = Math.max(jump || 0, breaks[j][1]); }
        }
        if (jump !== null) { t = jump; continue; }
        slots.push(hhmm(t) + '–' + hhmm(t + len));
        t += len;
      }
      var days = Array.prototype.filter.call(box.querySelectorAll('[name="day_dates[]"]'), function (d) { return d.value; }).length || 1;
      preview.textContent = slots.length
        ? 'Vorschau: ' + slots.length + ' Termine pro Tag' + (days > 1 ? ' (' + days * slots.length + ' insgesamt an ' + days + ' Tagen)' : '') + ': ' + slots.join(', ')
        : 'Mit diesen Zeiten entsteht kein Termin.';
      preview.hidden = false;
    }
    box.addEventListener('input', update);
    box.addEventListener('change', update);
    var addDate = box.querySelector('[data-add-date]');
    addDate.addEventListener('click', function () {
      var inputs = box.querySelectorAll('[name="day_dates[]"]');
      var n = inputs[inputs.length - 1].cloneNode();
      n.value = ''; n.required = false; n.setAttribute('aria-label', 'Datum ' + (inputs.length + 1));
      addDate.parentNode.insertBefore(n, addDate);
      n.focus();
    });
    // Pausen: keine leeren Zeilen vorab, „+ Pause hinzufügen“ holt eine aus der Vorlage
    var breakList = box.querySelector('[data-breaks]');
    box.querySelector('[data-add-break]').addEventListener('click', function () {
      var r = box.querySelector('[data-break-template]').content.firstElementChild.cloneNode(true);
      breakList.appendChild(r);
      r.querySelector('input').focus();
    });
    breakList.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-remove-break]');
      if (!btn) { return; }
      btn.closest('.break-row').remove();
      update();
      box.querySelector('[data-add-break]').focus();
    });
    update();
  });
})();

/*
 * Uhrzeitfelder im 24-Stunden-Format (HH:MM) unabhängig von der Browsersprache.
 * Ergänzt den Doppelpunkt beim Tippen („1430“ → „14:30“) und vervollständigt beim
 * Verlassen („9“ → „09:00“, „930“ → „09:30“). Der Server prüft das Format zusätzlich.
 */
(function () {
  'use strict';
  function normalize(v) {
    var d = v.replace(/\D/g, '');
    if (d.length === 0) { return ''; }
    if (d.length <= 2) { d = ('0' + d).slice(-2) + '00'; }
    else if (d.length === 3) { d = '0' + d; }
    var h = parseInt(d.slice(0, 2), 10), m = parseInt(d.slice(2, 4), 10);
    return h < 24 && m < 60 ? d.slice(0, 2) + ':' + d.slice(2, 4) : v;
  }
  document.addEventListener('input', function (ev) {
    var el = ev.target;
    if (!el.matches || !el.matches('[data-time]') || ev.inputType === 'deleteContentBackward') { return; }
    // Nur Ziffern und Trenner zulassen; Doppelpunkt nur ergänzen, wenn keiner getippt wurde
    var v = el.value.replace(/[^0-9:.]/g, '');
    var d = v.replace(/\D/g, '').slice(0, 4);
    // Ohne Trenner ab 3 Ziffern Doppelpunkt setzen; stehen hinter dem Trenner mehr als
    // 2 Ziffern (z. B. „1:830“ nach „1830“), neu aufteilen: „18:30“
    if (/^\d{3,4}$/.test(v) || /^\d{1,2}[:.]\d{3,}$/.test(v)) { v = d.slice(0, d.length - 2) + ':' + d.slice(-2); }
    if (v !== el.value) { el.value = v; } // unverändert lassen schont die Cursorposition
  });
  document.addEventListener('focusout', function (ev) {
    var el = ev.target;
    if (el.matches && el.matches('[data-time]')) {
      el.value = normalize(el.value);
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });
})();

/*
 * Datumsfelder: Die Anzeige von type=date folgt der Browsersprache (z. B. 11/19/2026).
 * Daneben steht deshalb immer das Datum in deutscher Schreibweise mit Wochentag.
 */
(function () {
  'use strict';
  var days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
  function update(input) {
    var label = input.nextElementSibling;
    if (!label || !label.classList.contains('date-de')) {
      label = document.createElement('span');
      label.className = 'date-de';
      input.insertAdjacentElement('afterend', label);
    }
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(input.value);
    label.textContent = m ? days[new Date(+m[1], m[2] - 1, +m[3]).getDay()] + ', ' + m[3] + '.' + m[2] + '.' + m[1] : '';
  }
  Array.prototype.forEach.call(document.querySelectorAll('input[data-de-date]'), update);
  ['input', 'change'].forEach(function (t) {
    document.addEventListener(t, function (ev) {
      if (ev.target.matches && ev.target.matches('input[data-de-date]')) { update(ev.target); }
    });
  });
})();

/*
 * Hell/Dunkel-Schalter im Fuß der Seite: Automatisch (Einstellung des Geräts), Hell, Dunkel.
 * Die Wahl liegt nur im Browser (localStorage „tp_theme“); „Automatisch“ löscht den Eintrag.
 * Ohne JavaScript bleibt der Schalter ausgeblendet und die Seite folgt dem Gerät.
 */
(function () {
  'use strict';
  var KEY = 'tp_theme';
  function current() {
    var t = document.documentElement.getAttribute('data-theme');
    return t === 'light' || t === 'dark' ? t : 'auto';
  }
  function apply(choice) {
    if (choice === 'light' || choice === 'dark') {
      document.documentElement.setAttribute('data-theme', choice);
    } else {
      document.documentElement.removeAttribute('data-theme');
    }
    try {
      if (choice === 'light' || choice === 'dark') {
        window.localStorage.setItem(KEY, choice);
      } else {
        window.localStorage.removeItem(KEY);
      }
    } catch (e) { /* Speicher gesperrt: gilt nur für diese Seite */ }
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-theme-switch]'), function (group) {
    var buttons = group.querySelectorAll('[data-theme-choice]');
    function mark() {
      Array.prototype.forEach.call(buttons, function (b) {
        b.setAttribute('aria-pressed', b.getAttribute('data-theme-choice') === current() ? 'true' : 'false');
      });
    }
    Array.prototype.forEach.call(buttons, function (b) {
      b.addEventListener('click', function () {
        apply(b.getAttribute('data-theme-choice'));
        mark();
      });
    });
    mark();
    group.hidden = false;
  });
})();
