/*
 * TalkSlot – small, dependency-free script.
 *
 * Remember-me entries in the browser (localStorage, key "tp_bookings"):
 * Only stored when parents tick "Remember my appointment on this device" (form.remember):
 * { <round-id>: [ { u: <link to the booking page>, l: <appointment label>, d: <date YYYY-MM-DD> }, … ] },
 * one entry per booking (siblings), removed after the day of the appointment.
 * Nothing is sent to the server. Pages also work without JavaScript.
 *
 * Admin area (teacher): translation in the browser via the Translator API; sessionStorage
 * only as loop protection for the automatic translation and for an error notice.
 *
 * Texts of the admin area: L(germanText, {placeholder: value}). In English the admin layout
 * provides the translations as JSON (#tp-i18n, from app/lang/admin/en/js.php).
 */
var L = (function () {
  'use strict';
  var map = null;
  return function (de, vars) {
    if (map === null) {
      var el = document.getElementById('tp-i18n');
      try { map = el ? JSON.parse(el.textContent) : {}; } catch (e) { map = {}; }
    }
    var text = Object.prototype.hasOwnProperty.call(map, de) ? map[de] : de;
    Object.keys(vars || {}).forEach(function (k) { text = text.split('{' + k + '}').join(String(vars[k])); });
    return text;
  };
})();

(function () {
  'use strict';

  var KEY = 'tp_bookings';
  var MAX_PER_ROUND = 10;
  // Only links to own bookings on this site (/b/<token>, possibly with subdirectory and language)
  var OWN_LINK = /^(\/[A-Za-z0-9_-]+)*\/b\/[A-Za-z0-9_-]{43}(\?lang=[a-z]{2})?$/;

  function path(u) {
    return String(u || '').split('?')[0];
  }

  function today() {
    var d = new Date();
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
  }

  // Remember-me entries as a list per round. The earlier format (one entry per round) is read as a list
  // of one. Entries of past days are left out: the booking itself is deleted the day after the appointment.
  function load() {
    var raw;
    try {
      raw = JSON.parse(window.localStorage.getItem(KEY) || '{}');
    } catch (e) {
      raw = null;
    }
    var data = {};
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
      return data;
    }
    var now = today();
    Object.keys(raw).forEach(function (id) {
      var list = (Array.isArray(raw[id]) ? raw[id] : [raw[id]]).filter(function (e) {
        return e && typeof e === 'object' && typeof e.u === 'string' && !(typeof e.d === 'string' && e.d < now);
      });
      if (list.length) { data[id] = list; }
    });
    return data;
  }

  function save(data) {
    try {
      if (Object.keys(data).length) {
        window.localStorage.setItem(KEY, JSON.stringify(data));
      } else {
        window.localStorage.removeItem(KEY);
      }
    } catch (e) { /* storage unavailable – no remember-me entry */ }
  }

  function entryOf(el) {
    return { u: el.getAttribute('data-remember-url'), l: el.getAttribute('data-remember-label'), d: el.getAttribute('data-remember-date') };
  }

  // Removes the entries with this link (from every round)
  function without(data, u) {
    Object.keys(data).forEach(function (id) {
      data[id] = data[id].filter(function (e) { return path(e.u) !== path(u); });
      if (!data[id].length) { delete data[id]; }
    });
    return data;
  }

  // Expired entries go on every page view, not only when the round is opened again
  try {
    if (window.localStorage.getItem(KEY) !== null) { save(load()); }
  } catch (e) { /* storage unavailable */ }

  // After booking: remember the appointment (only with explicit consent). Each booking gets its own
  // entry, so that a sibling's booking does not replace the first one.
  var remember = document.querySelector('[data-remember-round]');
  if (remember) {
    var d1 = load();
    var id1 = remember.getAttribute('data-remember-round');
    var e1 = entryOf(remember);
    d1[id1] = (d1[id1] || []).filter(function (e) { return path(e.u) !== path(e1.u); }).concat([e1]).slice(-MAX_PER_ROUND);
    save(d1);
  }

  // Appointment page opened or changed: update an existing entry (label, date), never create one
  var update = document.querySelector('[data-update-round]');
  if (update) {
    var d2 = load();
    var id2 = update.getAttribute('data-update-round');
    var e2 = entryOf(update);
    // only the same appointment (same link), never take over someone else's
    if ((d2[id2] || []).some(function (e) { return path(e.u) === path(e2.u); })) {
      d2[id2] = d2[id2].map(function (e) { return path(e.u) === path(e2.u) ? e2 : e; });
      save(d2);
    }
  }

  // Appointment cancelled, or its link leads nowhere any more: remove the entry
  var forget = document.querySelector('[data-forget-url]');
  if (forget) {
    save(without(load(), forget.getAttribute('data-forget-url')));
  }

  // Round page: show the own appointments instead of the slot list. "Book another appointment" shows the
  // form again without deleting anything; after an error or a language switch the form stays open.
  var round = document.querySelector('[data-round-id]');
  var box = document.getElementById('remembered');
  var area = document.getElementById('booking-area');
  if (round && box && area) {
    var roundId = round.getAttribute('data-round-id');
    var entries = load()[roundId] || [];
    var list = box.querySelector('[data-remembered-list]');
    var item = box.querySelector('[data-remembered-item]');
    var another = box.querySelector('[data-remembered-another]');
    var showForm = function () {
      area.hidden = false;
      another.hidden = true;
      var first = area.querySelector('input:not([type=hidden]), button');
      if (first) { first.focus(); }
    };
    entries.forEach(function (entry, i) {
      var li = item.content.firstElementChild.cloneNode(true);
      var label = li.querySelector('[data-remembered-label]');
      var link = li.querySelector('[data-remembered-link]');
      var drop = li.querySelector('[data-remembered-forget]');
      label.textContent = entry.l || '';
      label.id = 'remembered-' + i;
      if (OWN_LINK.test(entry.u)) {
        link.setAttribute('href', entry.u);
        link.setAttribute('aria-describedby', label.id);
      } else {
        link.hidden = true;
      }
      drop.setAttribute('aria-describedby', label.id);
      drop.addEventListener('click', function () {
        save(without(load(), entry.u));
        li.remove();
        if (list.children.length) {
          list.querySelector('[data-remembered-forget]').focus();
        } else {
          box.hidden = true;
          showForm();
        }
      });
      list.appendChild(li);
    });
    if (entries.length) {
      box.hidden = false;
      if (!document.getElementById('form-errors') && !area.hasAttribute('data-keep-open')) {
        area.hidden = true;
        another.hidden = false;
        another.addEventListener('click', showForm);
      }
    }
  }

  // Focus the error box after submitting (screen readers)
  var errors = document.getElementById('form-errors');
  if (errors) {
    errors.focus();
  }

  // Copy link (admin, and the parents' appointment page with texts in the page language; there the
  // button is hidden until JavaScript runs)
  Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (btn) {
    var original = btn.textContent;
    var copied = btn.getAttribute('data-copy-done') || L('Kopiert ✓');
    var ask = btn.getAttribute('data-copy-prompt') || L('Link kopieren:');
    btn.hidden = false;
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      var done = function () {
        btn.textContent = copied;
        setTimeout(function () { btn.textContent = original; }, 2000);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, function () { window.prompt(ask, text); });
      } else {
        window.prompt(ask, text);
      }
    });
  });

  // Share the appointment link where the device offers it (mostly phones), e.g. to oneself in a
  // messenger. The page says next to it that whoever has the link can change the appointment.
  if (typeof navigator.share === 'function') {
    Array.prototype.forEach.call(document.querySelectorAll('[data-share]'), function (btn) {
      btn.hidden = false;
      btn.addEventListener('click', function () {
        navigator.share({ title: btn.getAttribute('data-share-title') || document.title, text: btn.getAttribute('data-share-text') || '', url: btn.getAttribute('data-share') })
          .catch(function () { /* cancelled */ });
      });
    });
  }

  // Button that opens a <details> section further down (e.g. "Duplizieren …")
  Array.prototype.forEach.call(document.querySelectorAll('[data-open-details]'), function (link) {
    link.addEventListener('click', function () {
      var box = document.getElementById(link.getAttribute('data-open-details'));
      if (box) {
        box.open = true;
        var field = box.querySelector('input:not([type=hidden]), select, textarea, button');
        if (field) {
          window.setTimeout(function () { field.focus(); }, 0);
        }
      }
    });
  });

  // Confirmation prompt before delete actions. With the box "Eltern per E-Mail informieren" unticked, the
  // prompt says that the parents are not told (data-confirm-off)
  Array.prototype.forEach.call(document.querySelectorAll('form[data-confirm]'), function (form) {
    form.addEventListener('submit', function (ev) {
      var notify = form.querySelector('input[name="notify"]');
      var off = notify && !notify.checked && form.hasAttribute('data-confirm-off');
      if (!window.confirm(form.getAttribute(off ? 'data-confirm-off' : 'data-confirm'))) {
        ev.preventDefault();
      }
    });
  });

  // Mail option of a manual entry: ticked as soon as an e-mail address is entered, unticked and
  // disabled without one (nothing to send). A choice made by hand is kept.
  Array.prototype.forEach.call(document.querySelectorAll('input[data-mail-for]'), function (box) {
    var email = document.getElementById(box.getAttribute('data-mail-for'));
    if (!email) { return; }
    var wanted = box.checked;
    box.addEventListener('change', function () { wanted = box.checked; });
    function follow() {
      var hasAddress = email.value.trim() !== '';
      box.disabled = !hasAddress;
      box.checked = hasAddress && wanted;
    }
    email.addEventListener('input', follow);
    follow();
  });

  // Prevent double submission (e.g. double click while the translation is running).
  // After "Back" from the browser cache, submitting is allowed again.
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

  // The chosen appointment above the booking button: text from the chosen slot (data-when), announced by
  // screen readers (aria-live). Empty while nothing is chosen; without JavaScript only after an error.
  Array.prototype.forEach.call(document.querySelectorAll('[data-slot-summary]'), function (out) {
    var form = out.closest('form');
    if (!form) { return; }
    function show() {
      var chosen = form.querySelector('input[name="slot_id"]:checked');
      var when = chosen ? chosen.getAttribute('data-when') : '';
      var text = when ? out.getAttribute('data-slot-summary').split('{when}').join(when) : '';
      // Only a change is written (and announced): after an error the server already shows the same text
      if (out.textContent !== text) { out.textContent = text; }
    }
    form.addEventListener('change', function (ev) {
      if (ev.target && ev.target.name === 'slot_id') { show(); }
    });
    show(); // e.g. a slot the browser kept checked after "Back"
  });

  // Translation in the teacher's browser (Translator API in Chrome/Edge on desktop).
  // Title and description are translated on the teacher's own device with a local model,
  // no text goes to a translation service; the result is sent to the own server per
  // language. Chrome allows only one language pack download per click: one click
  // translates all languages that are already ready and downloads at most one more;
  // ready languages are translated even without a click.
  Array.prototype.forEach.call(document.querySelectorAll('form[data-browser-translate]'), function (form) {
    var api = self.Translator;
    var brands = (navigator.userAgentData && navigator.userAgentData.brands) || [];
    var onDevice = brands.some(function (b) { return /^(Google Chrome|Microsoft Edge|Chromium)$/.test(b.brand); });
    if (!api || typeof api.create !== 'function' || typeof api.availability !== 'function' || !onDevice) {
      return; // the "enter manually" notice stays visible
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
    var state = {};     // language → availability() or 'done'
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
    // Line by line, so paragraphs and lists are preserved
    function translateText(translator, text, sig) {
      return Promise.all(text.split('\n').map(function (line) {
        return line.trim() === '' ? Promise.resolve(line) : translator.translate(line, sig ? { signal: sig } : undefined);
      })).then(function (lines) { return lines.join('\n'); });
    }

    // create() is called immediately (still within the click); downloads need this user gesture
    function translateOne(lang, download) {
      var sig = signal(download ? 300000 : 60000);
      var shown = -10;
      var opts = {
        sourceLanguage: source,
        targetLanguage: lang,
        monitor: function (m) {
          m.addEventListener('downloadprogress', function (e) {
            var pct = Math.round((e.loaded || 0) * 100);
            if (pct - shown >= 10 || pct === 100) { shown = pct; say(L('Lade Sprachpaket {lang} … {pct} %', { lang: lang.toUpperCase(), pct: pct })); }
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

    // Show the saved translation right away in the "Übersetzungen" form (fields, status, counter)
    function applyRow(lang, row) {
      var fs = document.querySelector('fieldset[lang="' + lang + '"]');
      if (!fs) { return; }
      var set = function (el, value) { if (el) { el.value = value; el.defaultValue = value; } };
      set(fs.querySelector('[name="t_title[' + lang + ']"]'), row.title);
      set(fs.querySelector('[name="t_description[' + lang + ']"]'), row.description);
      set(fs.querySelector('[name="t_seen[' + lang + ']"]'), row.seen);
      var badge = fs.querySelector('legend .status');
      if (badge) { badge.textContent = L('automatisch'); badge.className = 'status status-on'; }
      var confirmBox = fs.querySelector('[name="t_confirm[' + lang + ']"]');
      if (confirmBox && confirmBox.parentNode) { confirmBox.parentNode.remove(); }
      fs.classList.add('bt-updated');
      var all = document.querySelectorAll('fieldset[lang] legend .status');
      var ok = document.querySelectorAll('fieldset[lang] legend .status.status-on');
      var counter = document.querySelector('[data-bt-counter]');
      if (counter) { counter.textContent = L('({ok} von {all} aktuell)', { ok: ok.length, all: all.length }); }
    }

    function saveOne(r, force) {
      var body = new FormData(form); // contains the CSRF token
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
      if (saved.length) { parts.push(L('Fertig: {langs} im Browser übersetzt und gespeichert – unten prüfen und bei Bedarf korrigieren.', { langs: upper(saved) })); }
      if (failed.length) { parts.push(L('Nicht im Browser übersetzbar: {langs} – bitte unten von Hand eintragen.', { langs: upper(failed) })); }
      if (saved.length && btnPending && pending.every(function (l) { return state[l] === 'done' || failed.indexOf(l) >= 0; })) {
        btnPending.hidden = true;
      }
      say(parts.join(' '), failed.length ? 'warning' : 'success');
    }

    // One pass: all ready languages + at most one download (only with a click)
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
      say(L('Übersetze im Browser …'));
      var outdated = false;
      var waitClick = [];
      var jobs = run.map(function (job) {
        return translateOne(job.lang, job.download)
          .then(function (r) { return saveOne(r, force); })
          .then(function () { state[job.lang] = 'done'; saved.push(job.lang); say(L('Übersetzt und gespeichert: {langs} …', { langs: upper(saved) })); })
          .catch(function (err) {
            if (err && err.message === 'outdated') { outdated = true; return; }
            if (err && err.name === 'NotAllowedError') { waitClick.push(job.lang); return; } // needs its own click
            if (err && err.message === 'save') { throw err; }
            failed.push(job.lang);
          });
      });
      Promise.all(jobs).then(function () {
        if (outdated) {
          busy = false;
          lock(false);
          say(L('Titel oder Beschreibung wurden inzwischen geändert. Bitte die Seite neu laden.'), 'warning');
          return;
        }
        var left = queue.filter(function (l) { return state[l] !== 'done' && failed.indexOf(l) < 0 && state[l] !== 'unavailable'; });
        if (left.length && btnNext) {
          busy = false;
          lock(false);
          btnNext.hidden = false;
          if (btnPending) { btnPending.hidden = true; } // only one button left to continue
          btnNext.textContent = left.length > 1
            ? L('Nächste Sprache laden: {lang} (noch {count})', { lang: left[0].toUpperCase(), count: left.length })
            : L('Nächste Sprache laden: {lang}', { lang: left[0].toUpperCase() });
          btnNext.onclick = function () { step(queue, force, true); };
          say((saved.length ? L('Übersetzt und gespeichert: {langs}.', { langs: upper(saved) }) + ' ' : '') +
            L('Für jedes weitere Sprachpaket einmal auf „{button}“ klicken (der Browser lädt es nur nach einem Klick).', { button: btnNext.textContent }));
          return;
        }
        busy = false;
        finish();
      }).catch(function () {
        busy = false;
        lock(false);
        say(L('Die Übersetzungen konnten nicht gespeichert werden. Bitte die Seite neu laden und erneut versuchen.'), 'error');
      });
    }

    if (btnPending) {
      btnPending.addEventListener('click', function () { step(pending, false, true); });
    }
    if (btnAll) {
      // Confirmation on the page (a dialog would use up the click permission for downloads)
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

    // Query availability up front; translate ready languages without a click – once per state
    // (prevents loops if saving fails)
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
        say(L('Zum Übersetzen auf „Im Browser übersetzen“ klicken. Beim ersten Mal lädt der Browser je Sprache ein Sprachpaket (je ein Klick).'));
      }
    });
  });

  // Print
  Array.prototype.forEach.call(document.querySelectorAll('[data-print]'), function (btn) {
    btn.addEventListener('click', function () { window.print(); });
  });
})();

/*
 * Appointment days: additional date/break fields and live preview of the resulting slots
 * (same calculation as SlotGenerator::compute on the server).
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
        ? (days > 1
          ? L('Vorschau: {count} Termine pro Tag ({total} insgesamt an {days} Tagen): {slots}', { count: slots.length, total: days * slots.length, days: days, slots: slots.join(', ') })
          : L('Vorschau: {count} Termine pro Tag: {slots}', { count: slots.length, slots: slots.join(', ') }))
        : L('Mit diesen Zeiten entsteht kein Termin.');
      preview.hidden = false;
    }
    box.addEventListener('input', update);
    box.addEventListener('change', update);
    // Dates only where the form has them (templates have times and breaks, but no dates)
    var addDate = box.querySelector('[data-add-date]');
    if (addDate) {
      addDate.addEventListener('click', function () {
        var inputs = box.querySelectorAll('[name="day_dates[]"]');
        var n = inputs[inputs.length - 1].cloneNode();
        n.value = ''; n.required = false; n.setAttribute('aria-label', L('Datum {n}', { n: inputs.length + 1 }));
        addDate.parentNode.insertBefore(n, addDate);
        n.focus();
      });
    }
    // Breaks: no empty rows up front, "+ Pause hinzufügen" adds one from the template
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
 * Time fields in 24-hour format (HH:MM) regardless of the browser language.
 * Inserts the colon while typing ("1430" → "14:30") and completes on blur
 * ("9" → "09:00", "930" → "09:30"). The server validates the format as well.
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
    // Allow only digits and separators; insert a colon only if none was typed
    var v = el.value.replace(/[^0-9:.]/g, '');
    var d = v.replace(/\D/g, '').slice(0, 4);
    // Without a separator, insert a colon from 3 digits on; if more than 2 digits follow the
    // separator (e.g. "1:830" after "1830"), split again: "18:30"
    if (/^\d{3,4}$/.test(v) || /^\d{1,2}[:.]\d{3,}$/.test(v)) { v = d.slice(0, d.length - 2) + ':' + d.slice(-2); }
    if (v !== el.value) { el.value = v; } // leaving it unchanged preserves the cursor position
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
 * Date fields: the display of type=date follows the browser language (e.g. 11/19/2026).
 * That is why the date is always shown next to it in German notation with the weekday.
 */
(function () {
  'use strict';
  var days = [L('So'), L('Mo'), L('Di'), L('Mi'), L('Do'), L('Fr'), L('Sa')];
  function update(input) {
    var label = input.nextElementSibling;
    if (!label || !label.classList.contains('date-de')) {
      label = document.createElement('span');
      label.className = 'date-de';
      input.insertAdjacentElement('afterend', label);
    }
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(input.value);
    label.textContent = m ? L('{weekday}, {d}.{m}.{y}', { weekday: days[new Date(+m[1], m[2] - 1, +m[3]).getDay()], d: m[3], m: m[2], y: m[1] }) : '';
  }
  Array.prototype.forEach.call(document.querySelectorAll('input[data-de-date]'), update);
  ['input', 'change'].forEach(function (t) {
    document.addEventListener(t, function (ev) {
      if (ev.target.matches && ev.target.matches('input[data-de-date]')) { update(ev.target); }
    });
  });
})();

/*
 * Light/dark toggle in the page footer: automatic (device setting), light, dark.
 * The choice is stored only in the browser (localStorage "tp_theme"); "automatic" removes the entry.
 * Without JavaScript the toggle stays hidden and the page follows the device.
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
    } catch (e) { /* storage blocked: applies to this page only */ }
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
