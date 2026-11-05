#!/usr/bin/env node
/*
 * Browser tests for public/assets/js/*.js: run in headless Chrome against a running instance,
 * controlled through the DevTools protocol (no dependencies).
 *
 *   dev/reset.sh && dev/demo.sh && node tests/browser.mjs [base_url]   (default http://localhost:8080)
 *
 * Needs Node 22+ (Node 20: node --experimental-websocket) and Chrome or Chromium (found in PATH, or
 * set CHROME=/path/to/chrome). Uses the demo data from dev/demo.sh and creates one extra round,
 * which it deletes again.
 */
import { spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, delimiter } from 'node:path';

const BASE = (process.argv[2] || 'http://localhost:8080').replace(/\/$/, '');
const USER = 'sabrina';
const PASS = 'Sehr-Sicheres-Dev-Pw1';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

if (typeof WebSocket === 'undefined') {
  console.error('This test needs WebSocket in Node: use Node 22+ or run node --experimental-websocket tests/browser.mjs');
  process.exit(2);
}

let failed = 0;
function check(name, ok, detail = '') {
  console.log((ok ? '  ✓ ' : '  ✗ ') + name + (!ok && detail ? `\n      ${detail}` : ''));
  if (!ok) failed++;
}
const section = (name) => console.log(name);

// --- Chrome ------------------------------------------------------------------------------------

function findChrome() {
  if (process.env.CHROME) return process.env.CHROME;
  for (const name of ['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser']) {
    for (const dir of (process.env.PATH || '').split(delimiter)) {
      if (dir && existsSync(join(dir, name))) return join(dir, name);
    }
  }
  throw new Error('Chrome/Chromium not found – set CHROME=/path/to/chrome');
}

const profile = mkdtempSync(join(tmpdir(), 'talkslot-browser-'));
const chrome = spawn(findChrome(), [
  '--headless=new', '--no-sandbox', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
  '--remote-debugging-port=0', `--user-data-dir=${profile}`, '--window-size=1280,900', 'about:blank',
], { stdio: 'ignore' });

async function connect() {
  const portFile = join(profile, 'DevToolsActivePort');
  // Chrome can take a while to start on a busy CI runner
  for (let i = 0; i < 300 && !existsSync(portFile); i++) await sleep(100);
  if (!existsSync(portFile)) throw new Error('Chrome did not start within 30 s (no DevToolsActivePort)');
  const port = readFileSync(portFile, 'utf8').split('\n')[0];
  for (let i = 0; i < 50; i++) {
    try {
      const page = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === 'page');
      if (page) return new Cdp(page.webSocketDebuggerUrl);
    } catch { /* not ready yet */ }
    await sleep(100);
  }
  throw new Error('no page target');
}

class Cdp {
  constructor(url) {
    this.ws = new WebSocket(url);
    this.id = 0;
    this.pending = new Map();
    this.listeners = [];
    this.dialogs = [];
    this.acceptDialogs = true;
    this.errors = []; // uncaught JavaScript errors in the pages (Runtime.exceptionThrown)
    this.ready = new Promise((r) => this.ws.addEventListener('open', r));
    this.ws.addEventListener('message', (m) => {
      const msg = JSON.parse(m.data);
      if (msg.id && this.pending.has(msg.id)) {
        const { resolve, reject } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
      } else if (msg.method) {
        if (msg.method === 'Runtime.exceptionThrown') {
          const d = msg.params.exceptionDetails;
          this.errors.push(`${d.exception?.description || d.text} (${d.url || '?'}:${d.lineNumber})`);
        }
        if (msg.method === 'Page.javascriptDialogOpening') {
          this.dialogs.push({ type: msg.params.type, message: msg.params.message });
          this.send('Page.handleJavaScriptDialog', { accept: this.acceptDialogs });
        }
        this.listeners = this.listeners.filter((l) => !(l.method === msg.method && (l.resolve(msg.params), true)));
      }
    });
  }
  send(method, params = {}) {
    const id = ++this.id;
    this.ws.send(JSON.stringify({ id, method, params }));
    return new Promise((resolve, reject) => this.pending.set(id, { resolve, reject }));
  }
  once(method, timeout = 15000) {
    return new Promise((resolve, reject) => {
      const l = { method, resolve };
      this.listeners.push(l);
      setTimeout(() => { this.listeners = this.listeners.filter((x) => x !== l); reject(new Error(`timeout waiting for ${method}`)); }, timeout);
    });
  }
}

const cdp = await connect();
await cdp.ready;
await cdp.send('Page.enable');
await cdp.send('Runtime.enable');
await cdp.send('Emulation.setFocusEmulationEnabled', { enabled: true }); // headless: page counts as focused (clipboard)

/** Evaluates an expression in the page; with gesture: true it counts as a user click (user activation). */
async function js(expression, { gesture = false } = {}) {
  const r = await cdp.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, userGesture: gesture });
  if (r.exceptionDetails) throw new Error(`${r.exceptionDetails.exception?.description || r.exceptionDetails.text}\n    in: ${expression.slice(0, 200)}`);
  return r.result.value;
}
async function goto(url) {
  const loaded = cdp.once('Page.loadEventFired');
  await cdp.send('Page.navigate', { url: url.startsWith('http') ? url : BASE + url });
  await loaded;
}
/** Runs an action that navigates (e.g. submitting a form) and waits for the new page. */
async function navigating(expression) {
  const loaded = cdp.once('Page.loadEventFired');
  await js(expression);
  await loaded;
}
async function until(expression, timeout = 5000) {
  for (const end = Date.now() + timeout; Date.now() < end; await sleep(50)) {
    if (await js(expression)) return true;
  }
  return false;
}
/** Types text like a user (one input event per character), optionally leaving the field afterwards. */
async function type(selector, text, { blur = false } = {}) {
  await js(`(e => { e.focus(); e.value = ''; })(document.querySelector(${JSON.stringify(selector)}))`);
  for (const ch of text) await cdp.send('Input.insertText', { text: ch });
  if (blur) await js(`document.querySelector(${JSON.stringify(selector)}).blur()`);
  return js(`document.querySelector(${JSON.stringify(selector)}).value`);
}
const day = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); };

try {
  // --- Admin area ------------------------------------------------------------------------------
  section('Admin: login and copy link');
  await goto('/admin/login');
  await js(`document.querySelector('[name=username]').value = ${JSON.stringify(USER)}; document.querySelector('[name=password]').value = ${JSON.stringify(PASS)}`);
  await navigating(`document.querySelector('[name=password]').form.requestSubmit()`);
  check('login through the form', await js(`location.pathname === '/admin' || location.pathname === '/admin/'`), await js('location.href'));

  await cdp.send('Browser.grantPermissions', { origin: BASE, permissions: ['clipboardReadWrite', 'clipboardSanitizedWrite'] });
  const copyLink = await js(`document.querySelector('[data-copy]').getAttribute('data-copy')`);
  await js(`document.querySelector('[data-copy]').click()`, { gesture: true });
  const copied = await until(`document.querySelector('[data-copy]').textContent === 'Kopiert ✓'`);
  const clip = await js(`navigator.clipboard.readText().catch(e => 'ERR ' + e.message)`);
  check('"Link kopieren" copies and confirms', copied && clip === copyLink && cdp.dialogs.length === 0,
    `confirmed=${copied} clipboard=${clip} dialogs=${JSON.stringify(cdp.dialogs)}`);
  const noClipboard = await cdp.send('Page.addScriptToEvaluateOnNewDocument', { source: `Object.defineProperty(navigator, 'clipboard', { value: undefined });` });
  await goto('/admin');
  cdp.dialogs = [];
  await js(`document.querySelector('[data-copy]').click()`, { gesture: true });
  check('without clipboard access the link is offered in a dialog to copy', cdp.dialogs.length === 1 && cdp.dialogs[0].type === 'prompt'
    && cdp.dialogs[0].message === 'Link kopieren:', JSON.stringify(cdp.dialogs));
  await cdp.send('Page.removeScriptToEvaluateOnNewDocument', { identifier: noClipboard.identifier });
  await goto('/admin');
  const mathRound = await js(`[...document.querySelectorAll('a[href^="/admin/round/"]')].find(a => a.textContent.trim() === 'Sprechstunde Mathematik')?.getAttribute('href')`);
  const demoRound = await js(`[...document.querySelectorAll('a[href^="/admin/round/"]')].find(a => a.textContent.trim() === 'Elternsprechtag Klasse 7b')?.getAttribute('href')`);
  check('demo data present (dev/demo.sh)', /^\/admin\/round\/\d+$/.test(demoRound || '') && /^\/admin\/round\/\d+$/.test(mathRound || ''), `${demoRound} ${mathRound}`);

  section('Admin: new round form (times, dates, breaks, preview)');
  await goto('/admin/round/new');
  check('time typed as 1430 becomes 14:30 while typing', (await type('#start_time', '1430')) === '14:30');
  check('time 9 becomes 09:00 when leaving the field', (await type('#start_time', '9', { blur: true })) === '09:00');
  check('time 930 becomes 09:30 when leaving the field', (await type('#start_time', '930', { blur: true })) === '09:30');
  check('time 18.15 becomes 18:15', (await type('#end_time', '18.15', { blur: true })) === '18:15');
  check('invalid time 2575 stays as typed (server rejects it)', (await type('#end_time', '2575', { blur: true })) === '25:75');

  const preview = () => js(`(p => p.hidden ? null : p.textContent)(document.querySelector('[data-slot-preview]'))`);
  await type('#start_time', '14:00', { blur: true });
  await type('#end_time', '15:00', { blur: true });
  await js(`(e => { e.value = '15'; e.dispatchEvent(new Event('input', { bubbles: true })); })(document.querySelector('#slot_minutes'))`);
  check('preview: 4 slots of 15 minutes', (await preview()) === 'Vorschau: 4 Termine pro Tag: 14:00–14:15, 14:15–14:30, 14:30–14:45, 14:45–15:00', await preview());

  await js(`document.querySelector('[data-add-break]').click()`);
  check('"+ Pause hinzufügen" adds a row and focuses it', await js(`document.querySelectorAll('[data-breaks] .break-row').length === 1 && document.activeElement.name === 'break_start[]'`));
  await type('[name="break_start[]"]', '14:20', { blur: true });
  await type('[name="break_end[]"]', '14:40', { blur: true });
  check('preview skips the break', (await preview()) === 'Vorschau: 2 Termine pro Tag: 14:00–14:15, 14:40–14:55', await preview());
  await js(`document.querySelector('[data-remove-break]').click()`);
  check('removing the break updates the preview', (await preview())?.startsWith('Vorschau: 4 Termine pro Tag') && await js(`document.activeElement.matches('[data-add-break]')`));

  await js(`(e => { e.value = '2030-01-07'; e.dispatchEvent(new Event('change', { bubbles: true })); })(document.querySelector('[name="day_dates[]"]'))`);
  check('date shown in German with weekday', await js(`document.querySelector('[name="day_dates[]"] + .date-de').textContent`) === 'Mo, 07.01.2030');
  await js(`document.querySelector('[data-add-date]').click()`);
  check('"+ weiteren Tag" adds an optional date field', await js(`(l => l.length === 2 && !l[1].required && document.activeElement === l[1])(document.querySelectorAll('[name="day_dates[]"]'))`));
  await js(`(e => { e.value = '2030-01-08'; e.dispatchEvent(new Event('change', { bubbles: true })); })(document.querySelectorAll('[name="day_dates[]"]')[1])`);
  check('preview counts all days', (await preview())?.includes('(8 insgesamt an 2 Tagen)'), await preview());
  await type('#end_time', '13:00', { blur: true });
  check('no preview when the end is before the start', (await preview()) === null);

  section('Admin: save round, preview matches the server, no double submission');
  const title = `Browsertest ${Date.now()}`;
  await js(`document.querySelector('#title').value = ${JSON.stringify(title)}`);
  await js(`(l => { l[0].value = ${JSON.stringify(day(30))}; l[1].value = ''; })(document.querySelectorAll('[name="day_dates[]"]'))`);
  await type('#start_time', '15:00', { blur: true });
  await type('#end_time', '16:10', { blur: true });
  await js(`(e => { e.value = '20'; e.dispatchEvent(new Event('input', { bubbles: true })); })(document.querySelector('#slot_minutes'))`);
  await js(`document.querySelector('[data-add-break]').click()`);
  await type('[name="break_start[]"]', '15:30', { blur: true });
  await type('[name="break_end[]"]', '15:45', { blur: true });
  const expected = (await preview()).replace(/^.*: /, '').split(', ');
  const secondPrevented = await (async () => {
    const loaded = cdp.once('Page.loadEventFired');
    const r = await js(`(f => { f.requestSubmit(); const e = new Event('submit', { cancelable: true }); f.dispatchEvent(e); return e.defaultPrevented; })(document.querySelector('form[data-once]'))`);
    await loaded;
    return r;
  })();
  check('a second submit of the form is blocked', secondPrevented === true);
  const roundPath = await js('location.pathname');
  check('round saved', /^\/admin\/round\/\d+$/.test(roundPath), roundPath);
  const serverSlots = await js(`[...document.querySelectorAll('.slots-table tbody tr td:first-child')].map(td => td.textContent.trim())`);
  check(`server creates exactly the previewed slots (${expected.join(', ')})`, JSON.stringify(serverSlots) === JSON.stringify(expected), JSON.stringify(serverSlots));
  await goto('/admin');
  check('round created only once', await js(`[...document.querySelectorAll('a')].filter(a => a.textContent.trim() === ${JSON.stringify(title)}).length`) === 1);

  section('Admin: "Duplizieren …" opens the form with the date');
  await goto(roundPath);
  check('duplicate form closed at first', await js(`!document.getElementById('duplicate').open`));
  await js(`document.querySelector('[data-open-details="duplicate"]').click()`, { gesture: true });
  await sleep(50);
  check('button opens it and focuses the date', await js(`document.getElementById('duplicate').open && document.activeElement.id === 'first_date'`));

  section('Admin: template form (times and breaks, no dates)');
  await goto('/admin/templates/new');
  await js(`document.querySelector('[data-add-break]').click()`, { gesture: true });
  check('"+ Pause hinzufügen" adds a break row in the template form', await js(`document.querySelectorAll('[data-breaks] .break-row').length`) === 1
    && await js(`document.activeElement.name === 'break_start[]'`));
  await type('[name="break_start[]"]', '15:00', { blur: true });
  await type('[name="break_end[]"]', '15:30', { blur: true });
  check('template form: preview skips the break', ((await preview()) || '').includes('14:45–15:00, 15:30–15:45'), await preview());

  section('Admin: confirmation before deleting');
  await goto(roundPath);
  cdp.dialogs = [];
  cdp.acceptDialogs = false;
  await js(`document.querySelector('form[data-confirm][action$="/delete"]').requestSubmit()`);
  await sleep(300);
  check('"Abbrechen" in the dialog keeps the round', cdp.dialogs.length === 1 && cdp.dialogs[0].type === 'confirm'
    && cdp.dialogs[0].message.includes(title) && await js('location.pathname') === roundPath);
  cdp.acceptDialogs = true;
  await navigating(`document.querySelector('form[data-confirm][action$="/delete"]').requestSubmit()`);
  await goto('/admin');
  check('"OK" deletes it', await js(`![...document.querySelectorAll('a')].some(a => a.textContent.trim() === ${JSON.stringify(title)})`));

  await goto(demoRound);
  const slug = (await js(`document.querySelector('[data-copy]').getAttribute('data-copy')`)).replace(/.*\//, '');

  section('Admin: translation in the browser (Translator API simulated)');
  // Chrome's on-device Translator API is not available headless; this stand-in behaves like it:
  // every language is ready except Turkish, and "translating" prefixes the language code.
  const stub = await cdp.send('Page.addScriptToEvaluateOnNewDocument', { source: `
    Object.defineProperty(navigator, 'userAgentData', { value: { brands: [{ brand: 'Chromium', version: '140' }] } });
    self.Translator = {
      availability: async (o) => (o.targetLanguage === 'tr' ? 'unavailable' : 'available'),
      create: async (o) => ({ translate: async (t) => '[' + o.targetLanguage + '] ' + t, destroy() {} }),
    };` });
  await goto(demoRound);
  check('translation panel replaces the "enter manually" notice', await js(`!document.querySelector('form[data-browser-translate]').hidden && document.querySelector('[data-bt-hint]').hidden`));
  const status = () => js(`document.querySelector('[data-bt-status]').textContent`);
  check('ready languages are translated and saved without a click', await until(`document.querySelector('[data-bt-status]').textContent.startsWith('Fertig:')`, 10000), await status());
  check('the language without a model is reported', (await status()).includes('Nicht im Browser übersetzbar: TR'), await status());
  check('fields updated in place', await js(`document.querySelector('[name="t_title[pl]"]').value`) === '[pl] Elternsprechtag Klasse 7b');
  await cdp.send('Page.removeScriptToEvaluateOnNewDocument', { identifier: stub.identifier });
  const noApi = await cdp.send('Page.addScriptToEvaluateOnNewDocument', { source: `self.Translator = undefined;` });
  await goto(demoRound);
  check('translations stored on the server', await js(`document.querySelector('[name="t_title[uk]"]').value`) === '[uk] Elternsprechtag Klasse 7b'
    && await js(`document.querySelector('[name="t_title[tr]"]').value`) === '');
  check('without the API the panel stays hidden and the notice remains', await js(`document.querySelector('form[data-browser-translate]').hidden && !document.querySelector('[data-bt-hint]').hidden`));
  await cdp.send('Page.removeScriptToEvaluateOnNewDocument', { identifier: noApi.identifier });

  section('Admin: translation in the browser – language packs, re-translating, errors');
  // Stand-in with language packs: "downloadable" languages are only loaded after a click (like Chrome,
  // which refuses the download without user activation) and report download progress.
  const translator = (avail, prefix) => cdp.send('Page.addScriptToEvaluateOnNewDocument', { source: `
    Object.defineProperty(navigator, 'userAgentData', { value: { brands: [{ brand: 'Chromium', version: '140' }] } });
    (() => {
      const avail = ${JSON.stringify(avail)};
      self.Translator = {
        availability: async (o) => avail[o.targetLanguage] || 'unavailable',
        create: async (o) => {
          if (avail[o.targetLanguage] === 'downloadable') {
            if (!navigator.userActivation.isActive) throw new DOMException('needs a click', 'NotAllowedError');
            const m = new EventTarget();
            if (o.monitor) o.monitor(m);
            for (const loaded of [0.5, 1]) { const e = new Event('downloadprogress'); e.loaded = loaded; m.dispatchEvent(e); await new Promise((r) => setTimeout(r, 200)); }
          }
          return { translate: async (t) => '[' + ${JSON.stringify(prefix)} + o.targetLanguage + '] ' + t, destroy() {} };
        },
      };
    })();` }).then((r) => r.identifier);
  const btn = (name) => `document.querySelector('[data-bt-${name}]')`;
  const field = (lang) => js(`document.querySelector('[name="t_title[${lang}]"]').value`);
  const idle = () => until(`!${btn('next')}.disabled && !document.querySelector('[data-bt-status]').textContent.includes('Übersetze im Browser')`, 10000);

  let t = await translator({ en: 'available', pl: 'downloadable', ru: 'downloadable' }, '');
  await goto(mathRound);
  check('ready language translated at once, then one button per language pack', await until(`!${btn('next')}.hidden`, 10000)
    && await js(`${btn('next')}.textContent`) === 'Nächste Sprache laden: PL (noch 2)' && await js(`${btn('pending')}.hidden`)
    && (await status()).startsWith('Übersetzt und gespeichert: EN.') && await field('en') === '[en] Sprechstunde Mathematik', await status());
  await js(`${btn('next')}.click()`); // without a user click: the browser refuses the download
  await idle();
  check('without a real click the download is refused and the button stays', await js(`${btn('next')}.textContent`) === 'Nächste Sprache laden: PL (noch 2)' && await field('pl') === '');
  await js(`${btn('next')}.click()`, { gesture: true });
  check('download progress is shown', await until(`document.querySelector('[data-bt-status]').textContent.startsWith('Lade Sprachpaket PL … ')`), await status());
  await idle();
  check('after the download: translated, button offers the next language', await field('pl') === '[pl] Sprechstunde Mathematik'
    && await js(`${btn('next')}.textContent`) === 'Nächste Sprache laden: RU' && (await status()).startsWith('Übersetzt und gespeichert: EN, PL.'), await status());
  await js(`${btn('next')}.click()`, { gesture: true });
  check('last language pack: done, unavailable languages listed', await until(`document.querySelector('[data-bt-status]').textContent.startsWith('Fertig: EN, PL, RU ')`, 10000)
    && (await status()).includes('Nicht im Browser übersetzbar: UK, TR') && await js(`${btn('next')}.hidden`), await status());
  await cdp.send('Page.removeScriptToEvaluateOnNewDocument', { identifier: t });

  t = await translator({ en: 'available', pl: 'available', ru: 'available', uk: 'available', tr: 'available' }, 'neu:');
  await goto(mathRound);
  await idle();
  const label = await js(`${btn('all')}.textContent`);
  await js(`${btn('all')}.click()`, { gesture: true });
  await sleep(300);
  check('"Alle neu übersetzen" asks for a second click first', await js(`${btn('all')}.textContent`) === await js(`${btn('all')}.getAttribute('data-bt-confirm')`)
    && await field('pl') === '[pl] Sprechstunde Mathematik');
  await js(`${btn('all')}.click()`, { gesture: true });
  const doneLangs = async () => ((await status()).match(/^Fertig: ([A-Z, ]+) im Browser/)?.[1] || '').split(', ').sort().join(',');
  check('second click re-translates all languages, overwriting earlier ones', await until(`document.querySelector('[data-bt-status]').textContent.startsWith('Fertig:')`, 10000)
    && await doneLangs() === 'EN,PL,RU,TR,UK'
    && await field('pl') === '[neu:pl] Sprechstunde Mathematik' && await js(`${btn('all')}.textContent`) === label, await status());

  await goto(mathRound);
  await idle();
  await js(`document.querySelector('form[data-browser-translate]').setAttribute('data-hash', 'veraltet')`); // title changed in the meantime
  await js(`${btn('all')}.click()`, { gesture: true });
  await js(`${btn('all')}.click()`, { gesture: true });
  check('title changed meanwhile (409): asks to reload, nothing overwritten', await until(`document.querySelector('[data-bt-status]').textContent.includes('Bitte die Seite neu laden')`, 10000)
    && (await status()).startsWith('Titel oder Beschreibung wurden inzwischen geändert'), await status());
  await goto(mathRound);
  await idle();
  await js(`document.querySelector('form[data-browser-translate]').setAttribute('action', '/admin/gibt-es-nicht')`);
  await js(`${btn('all')}.click()`, { gesture: true });
  await js(`${btn('all')}.click()`, { gesture: true });
  check('saving fails: error message, buttons usable again', await until(`document.querySelector('[data-bt-status]').textContent.startsWith('Die Übersetzungen konnten nicht gespeichert werden')`, 10000)
    && await js(`!${btn('all')}.disabled`), await status());
  await cdp.send('Page.removeScriptToEvaluateOnNewDocument', { identifier: t });
  await goto(mathRound);
  check('server keeps the last saved translations', await field('pl') === '[neu:pl] Sprechstunde Mathematik');

  section('Admin: forms after "Back"');
  await js(`(f => f.setAttribute('data-sent', '1'))(document.querySelector('form[data-once]'))`);
  await js(`window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }))`);
  check('a form sent before can be submitted again after returning from the browser cache', await js(`!document.querySelector('form[data-once]').hasAttribute('data-sent')`));

  section('Admin: print button');
  const printStub = await cdp.send('Page.addScriptToEvaluateOnNewDocument', { source: `window.print = () => { window.__printed = true; };` });
  await goto(`${demoRound}/print`);
  await js(`document.querySelector('[data-print]').click()`);
  check('"Drucken" opens the print dialog', await js('window.__printed === true'));
  await cdp.send('Page.removeScriptToEvaluateOnNewDocument', { identifier: printStub.identifier });

  section('Admin: parents told about a deletion, or that they are not');
  await goto(demoRound);
  // Delete dialog of a booking (cancelled, nothing is deleted): $1 name of the booking
  const bookingForm = (name) => `[...document.querySelectorAll('form[data-confirm][action*="/booking/"]')].find(f => f.closest('tr').textContent.includes(${JSON.stringify(name)}))`;
  const deleteDialog = async (name) => {
    cdp.dialogs = [];
    cdp.acceptDialogs = false;
    await js(`${bookingForm(name)}.requestSubmit()`);
    await sleep(300);
    cdp.acceptDialogs = true;
    return cdp.dialogs.map((d) => d.message).join(' | ');
  };
  const withMail = await deleteDialog('Familie Yılmaz (Elif)');
  check('deleting a booking with an address: box ticked, the dialog says a cancellation goes out by e-mail', withMail.startsWith('Eintrag von „Familie Yılmaz (Elif)“ löschen?')
    && withMail.endsWith('\n\nDie Eltern erhalten eine Absage per E-Mail.') && await js(`${bookingForm('Familie Yılmaz (Elif)')}.querySelector('input[name="notify"]').checked`), withMail);
  await js(`${bookingForm('Familie Yılmaz (Elif)')}.querySelector('input[name="notify"]').click()`);
  const unticked = await deleteDialog('Familie Yılmaz (Elif)');
  check('… box unticked: the dialog says the parents are NOT notified', unticked.endsWith('\n\nDie Eltern werden NICHT automatisch benachrichtigt – bitte selbst informieren.'), unticked);
  const noMail = await deleteDialog('Lukas Schmidt');
  check('… without an e-mail address: please inform them in person, no box', noMail.endsWith('Für diesen Eintrag ist keine E-Mail-Adresse hinterlegt – bitte persönlich informieren.')
    && await js(`!${bookingForm('Lukas Schmidt')}.querySelector('input[name="notify"]')`), noMail);
  check('"Abbrechen" keeps both bookings', await js(`location.pathname`) === demoRound
    && await js(`['Familie Yılmaz (Elif)', 'Lukas Schmidt'].every(n => document.querySelector('main').textContent.includes(n))`));
  // Deleting a day: the same box next to the required "inkl. N Buchung(en)"
  const dayForm = `document.querySelector('form[data-confirm][action*="/day/"]')`;
  cdp.dialogs = [];
  cdp.acceptDialogs = false;
  await js(`${dayForm}.querySelector('input[name="confirm"]').checked = true; ${dayForm}.requestSubmit()`);
  await sleep(300);
  const dayTicked = cdp.dialogs.map((d) => d.message).join(' | ');
  await js(`${dayForm}.querySelector('input[name="notify"]').click(); ${dayForm}.requestSubmit()`);
  await sleep(300);
  cdp.acceptDialogs = true;
  const dayUnticked = cdp.dialogs.slice(1).map((d) => d.message).join(' | ');
  check('deleting a day: with the box a cancellation for the parents with an address and the others in person, without it NOT notified',
    /erhalten eine Absage per E-Mail\. \d+ Buchung\(en\) ohne E-Mail an die Eltern – bitte persönlich informieren/.test(dayTicked)
    && dayUnticked.includes('Die Eltern werden NICHT automatisch benachrichtigt – bitte selbst informieren. Vorher in der Druckansicht nachsehen')
    && await js('location.pathname') === demoRound, `${dayTicked} || ${dayUnticked}`);

  const mailBox = `document.querySelector('#send_mail')`;
  const setEmail = (value) => js(`(e => { e.value = ${JSON.stringify(value)}; e.dispatchEvent(new Event('input', { bubbles: true })); })(document.querySelector('#email'))`);
  await goto(await js(`document.querySelector('a[href$="/book"]').getAttribute('href')`));
  check('new entry without an address: mail option unticked and disabled', await js(`${mailBox}.disabled && !${mailBox}.checked`));
  await type('#email', 'eltern@example.org');
  check('address entered: confirmation mail ticked', await js(`!${mailBox}.disabled && ${mailBox}.checked`));
  await js(`${mailBox}.click()`);
  await setEmail('');
  await type('#email', 'eltern@example.org');
  check('unticked by hand: stays unticked when the address is retyped', await js(`!${mailBox}.disabled && !${mailBox}.checked`));
  const editOf = (name) => js(`[...document.querySelectorAll('.slots-table tr')].find(tr => tr.textContent.includes(${JSON.stringify(name)})).querySelector('a[href$="/edit"]').getAttribute('href')`);
  await goto(demoRound);
  await goto(await editOf('Familie Yılmaz (Elif)'));
  check('editing a booking with an address: change mail ticked', await js(`!${mailBox}.disabled && ${mailBox}.checked`));
  await goto(demoRound);
  await goto(await editOf('Lukas Schmidt'));
  check('editing a booking without an address: off until one is entered', await js(`${mailBox}.disabled && !${mailBox}.checked`)
    && (await setEmail('lukas@example.org'), await js(`!${mailBox}.disabled && ${mailBox}.checked`)));

  section('Admin: JavaScript texts in English');
  const setLang = async (lang) => {
    await goto('/admin/profile');
    await navigating(`(s => { s.value = '${lang}'; s.form.requestSubmit(); })(document.querySelector('#lang'))`);
  };
  await setLang('en');
  await goto('/admin/round/new');
  await type('#start_time', '14:00', { blur: true });
  await type('#end_time', '14:30', { blur: true });
  await js(`(e => { e.value = '15'; e.dispatchEvent(new Event('input', { bubbles: true })); })(document.querySelector('#slot_minutes'))`);
  check('preview in English', (await preview()) === 'Preview: 2 slots per day: 14:00–14:15, 14:15–14:30', await preview());
  await js(`(e => { e.value = '2030-01-01'; e.dispatchEvent(new Event('input', { bubbles: true })); })(document.querySelector('[name="day_dates[]"]'))`);
  check('weekday next to the date in English', await js(`document.querySelector('[name="day_dates[]"]').nextElementSibling.textContent`) === 'Tue, 01/01/2030');
  await setLang('de');
  await goto('/admin/round/new');
  check('back in German', await js('document.documentElement.lang') === 'de' && !(await js(`!!document.getElementById('tp-i18n')`)));

  // --- Parent pages ----------------------------------------------------------------------------
  const KEY = 'tp_bookings';
  const stored = () => js(`JSON.parse(localStorage.getItem('${KEY}') || '{}')`);
  const firstFree = `document.querySelector('[name=slot_id]:not(:checked)')`;
  async function book(name, { remember }) {
    await goto(`/${slug}`);
    await js(`${firstFree}.checked = true; document.querySelector('#name').value = ${JSON.stringify(name)};
      document.querySelector('#privacy').checked = true; document.querySelector('#remember').checked = ${remember};`);
    await navigating(`document.querySelector('#name').form.requestSubmit()`);
    return js('location.pathname');
  }

  section('Parents: remember the appointment on this device');
  await goto(`/${slug}`);
  await js(`localStorage.clear()`);
  const roundId = await js(`document.querySelector('[data-round-id]').getAttribute('data-round-id')`);
  const entries = async () => (await stored())[roundId] || [];
  const pathOf = (u) => String(u).split('?')[0];
  const managePath = await book('Browser Remember', { remember: true });
  const entry = (await entries())[0];
  check('booking with consent stores link, appointment and its date', /^\/b\/[A-Za-z0-9_-]{43}$/.test(managePath) && (await entries()).length === 1
    && pathOf(entry?.u) === managePath && entry?.l?.length > 0 && /^\d{4}-\d{2}-\d{2}$/.test(entry?.d || ''), JSON.stringify(entry));

  section('Parents: keeping the personal link (copy, share, calendar)');
  const personalLink = await js(`document.getElementById('manage-link').value`);
  check('appointment page: "Link kopieren" shown with JavaScript, moving and cancelling folded away',
    await js(`!document.querySelector('[data-copy]').hidden && !document.getElementById('change').open && !document.getElementById('cancel').open`));
  await js(`document.querySelector('[data-copy]').click()`, { gesture: true });
  const linkCopied = await until(`document.querySelector('[data-copy]').textContent === 'Kopiert ✓'`);
  const linkClip = await js(`navigator.clipboard.readText().catch(e => 'ERR ' + e.message)`);
  check('"Link kopieren" copies the personal link and confirms', linkCopied && linkClip === personalLink && pathOf(linkClip).endsWith(managePath), linkClip);
  check('"Teilen" only where the browser can share', await js(`document.querySelector('[data-share]').hidden === (typeof navigator.share !== 'function')`));
  // getAttribute: the field named "action" hides the form's action property
  const ics = await js(`(f => fetch(f.getAttribute('action'), { method: 'POST', body: new URLSearchParams(new FormData(f)) })
    .then(r => r.headers.get('Content-Type') + '|' + r.headers.get('Content-Disposition')))(document.querySelector('form[data-calendar]'))`);
  check('"In Kalender eintragen" sends its form and gets the calendar file', ics === 'text/calendar; charset=utf-8|attachment; filename="termin.ics"', ics);
  const fakeShare = await cdp.send('Page.addScriptToEvaluateOnNewDocument', { source: `
    Object.defineProperty(navigator, 'share', { configurable: true, value: (d) => { window.__shared = d; return Promise.resolve(); } });
    Object.defineProperty(navigator, 'clipboard', { value: undefined });` });
  await goto(managePath + '?lang=en');
  cdp.dialogs = [];
  await js(`document.querySelector('[data-copy]').click()`, { gesture: true });
  check('without clipboard access the link is offered in a dialog, in the language of the page', cdp.dialogs.length === 1 && cdp.dialogs[0].type === 'prompt'
    && cdp.dialogs[0].message === 'Copy link:', JSON.stringify(cdp.dialogs));
  await js(`document.querySelector('[data-share]').click()`, { gesture: true });
  const shared = await js(`window.__shared || null`);
  check('"Share" hands over the personal link with the appointment; the hint about the link belongs to it',
    await js(`!document.querySelector('[data-share]').hidden && document.getElementById(document.querySelector('[data-share]').getAttribute('aria-describedby')).textContent.startsWith('Anyone who has this link')`)
    && pathOf(shared?.url) === pathOf(personalLink) && shared?.text === await js(`document.querySelector('[data-share]').getAttribute('data-share-text')`), JSON.stringify(shared));
  await cdp.send('Page.removeScriptToEvaluateOnNewDocument', { identifier: fakeShare.identifier });

  section('Parents: several appointments on one device (siblings)');
  const siblingPath = await book('Browser Geschwister', { remember: true });
  const both = await entries();
  check('a sibling booked on the same device gets its own entry, the first one stays', both.length === 2 && pathOf(both[0].u) === managePath && pathOf(both[1].u) === siblingPath, JSON.stringify(both));
  await goto(`/${slug}`);
  check('round page lists both appointments instead of the slot list, with a button for another one',
    await js(`!document.getElementById('remembered').hidden && document.getElementById('booking-area').hidden
      && document.querySelectorAll('[data-remembered-list] li').length === 2 && !document.querySelector('[data-remembered-another]').hidden`)
    && await js(`[...document.querySelectorAll('[data-remembered-link]')].map(a => a.getAttribute('href')).join(' ')`) === both.map((e) => e.u).join(' '));
  await js(`document.querySelector('[data-remembered-another]').click()`);
  check('"Weiteren Termin buchen" shows the form without deleting anything', await js(`!document.getElementById('booking-area').hidden
    && document.querySelector('[data-remembered-another]').hidden && document.activeElement.name === 'slot_id'`) && (await entries()).length === 2);
  await goto(`/${slug}`);
  await js(`document.querySelectorAll('[data-remembered-forget]')[1].click()`);
  check('"Merker löschen" removes only that entry, the other appointment stays listed', (await entries()).length === 1 && pathOf((await entries())[0].u) === managePath
    && await js(`document.querySelectorAll('[data-remembered-list] li').length === 1 && document.getElementById('booking-area').hidden`));

  await goto(managePath);
  await js(`(f => { f.querySelector('[name=slot_id]').checked = true; })(document.querySelector('input[name=action][value=move]').form)`);
  await navigating(`document.querySelector('input[name=action][value=move]').form.requestSubmit()`);
  const moved = (await entries())[0];
  check('moving updates the stored appointment', (await entries()).length === 1 && moved.l !== entry.l && pathOf(moved.u) === managePath && /^\d{4}-\d{2}-\d{2}$/.test(moved.d), JSON.stringify(moved));

  await js(`(f => { const c = f.querySelector('[name=confirm]'); if (c) c.checked = true; })(document.querySelector('input[name=action][value=cancel]').form)`);
  await navigating(`document.querySelector('input[name=action][value=cancel]').form.requestSubmit()`);
  check('cancelling removes the stored appointment', (await stored())[roundId] === undefined);

  await book('Browser Ohne Merken', { remember: false });
  check('without consent nothing is stored', (await stored())[roundId] === undefined && await js(`localStorage.getItem('${KEY}')`) === null);

  section('Parents: the booking form is sent only once');
  await goto(`/${slug}`);
  await js(`${firstFree}.checked = true; document.querySelector('#name').value = 'Browser Doppelklick'; document.querySelector('#privacy').checked = true;`);
  const secondBooking = await (async () => {
    const loaded = cdp.once('Page.loadEventFired');
    const r = await js(`(f => { f.requestSubmit(); const e = new Event('submit', { cancelable: true }); f.dispatchEvent(e); return e.defaultPrevented; })(document.getElementById('booking-form'))`);
    await loaded;
    return r;
  })();
  check('a second click on "book" is not sent: the page with the personal link follows', secondBooking === true && /^\/b\/[A-Za-z0-9_-]{43}$/.test(await js('location.pathname')));

  section('Parents: stored entries are not trusted blindly, and expire');
  const fake = 'A'.repeat(43);
  for (const [what, u] of [['other site', `https://evil.example/b/${fake}`], ['javascript: link', 'javascript:alert(1)//b/' + fake]]) {
    // earlier format: one entry per round
    await js(`localStorage.setItem('${KEY}', JSON.stringify({ ${JSON.stringify(roundId)}: { u: ${JSON.stringify(u)}, l: '<img src=x onerror=alert(1)>' } }))`);
    await goto(`/${slug}`);
    check(`link to ${what} is not shown, label only as text`, await js(`document.querySelector('[data-remembered-link]').hidden
      && document.querySelector('[data-remembered-label]').textContent === '<img src=x onerror=alert(1)>' && !document.querySelector('[data-remembered-label] img')`));
  }
  await js(`document.querySelector('[data-remembered-forget]').click()`);
  check('"vergessen" removes the last entry and shows the slot list again', await js(`document.getElementById('remembered').hidden && !document.getElementById('booking-area').hidden && localStorage.getItem('${KEY}') === null`));
  const kept = { u: '/b/' + 'B'.repeat(43), l: 'neu', d: day(30) };
  await js(`localStorage.setItem('${KEY}', JSON.stringify({ ${JSON.stringify(roundId)}: [{ u: '/b/${fake}', l: 'alt', d: '${day(-1)}' }, ${JSON.stringify(kept)}], 999999: [{ u: '/b/${'C'.repeat(43)}', l: 'x', d: '${day(-2)}' }] }))`);
  await goto('/');
  check('entries of past days are removed on the next page view, also those of other rounds', JSON.stringify(await stored()) === JSON.stringify({ [roundId]: [kept] }), JSON.stringify(await stored()));
  await goto(`/b/${'B'.repeat(43)}`);
  check('a link that leads nowhere any more (booking deleted) removes its entry', await js(`localStorage.getItem('${KEY}')`) === null);

  section('Parents: language choice at the top (phone width)');
  await cdp.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  const ua = await js('navigator.userAgent');
  await cdp.send('Emulation.setUserAgentOverride', { userAgent: ua, acceptLanguage: 'de-DE,de' });
  await goto(`/${slug}`);
  const top = await js(`(n => ({ bottom: n.getBoundingClientRect().bottom, names: [...n.querySelectorAll('button')].map(b => b.textContent).join(' '),
    h1: document.querySelector('h1').getBoundingClientRect().top, wide: document.documentElement.scrollWidth }))(document.querySelector('.lang-switch'))`);
  check('390 px: all language names in their own language above the heading, no horizontal scrolling',
    top.names === 'Deutsch English Polski Русский Українська Türkçe' && top.bottom <= top.h1 && top.bottom < 200 && top.wide <= 390, JSON.stringify(top));
  await js(`${firstFree}.checked = true; document.querySelector('#name').value = 'Browser Enter'; document.querySelector('#privacy').checked = true; document.querySelector('#name').focus();`);
  const entered = cdp.once('Page.loadEventFired');
  await cdp.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: '\r' });
  await cdp.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
  await entered;
  check('Enter in the name field books (the language buttons above do not take it over)',
    /^\/b\/[A-Za-z0-9_-]{43}$/.test(await js('location.pathname')) && await js('document.documentElement.lang') === 'de', await js('location.href'));
  await goto(`/${slug}`);
  await js(`document.querySelector('#name').value = 'Browser Sprache'; document.querySelector('#privacy').checked = true;`);
  await navigating(`[...document.querySelectorAll('.lang-switch button')].find(b => b.textContent === 'Türkçe').click()`);
  check('a language at the top switches the page and keeps the input', await js('document.documentElement.lang') === 'tr'
    && await js(`document.querySelector('#name').value === 'Browser Sprache' && document.querySelector('#privacy').checked`));
  await cdp.send('Emulation.setUserAgentOverride', { userAgent: ua, acceptLanguage: 'uk-UA,uk;q=0.9' });
  await goto(`/${slug}`);
  check('browser in Ukrainian: page stays German, a hint in Ukrainian above the heading', await js('document.documentElement.lang') === 'de'
    && await js(`(h => !!h && h.textContent.trim() === 'Відкрити цю сторінку українською' && h.getBoundingClientRect().bottom <= document.querySelector('h1').getBoundingClientRect().top)(document.querySelector('.lang-hint'))`));
  await navigating(`document.querySelector('.lang-hint button').click()`);
  check('the hint opens the page in Ukrainian and then disappears', await js('document.documentElement.lang') === 'uk' && await js(`!document.querySelector('.lang-hint')`));
  await cdp.send('Emulation.setUserAgentOverride', { userAgent: ua, acceptLanguage: 'de-DE,de' });
  await cdp.send('Emulation.clearDeviceMetricsOverride');

  section('Security: forms on other sites cannot post to TalkSlot');
  // A page from another origin (here a data: URL) submits forms to the round page and the login. Chrome marks
  // these requests "Sec-Fetch-Site: cross-site" and the front controller refuses them; the forms of TalkSlot
  // itself (same origin, "Origin: null" because of no-referrer) work, as all other sections show.
  for (const [what, path, fields] of [['booking', `/${slug}`, { name: 'Fremde Seite', privacy: '1', slot_id: '1' }], ['login', '/admin/login', { username: USER, password: PASS }]]) {
    const inputs = Object.entries(fields).map(([k, v]) => `<input name="${k}" value="${v}">`).join('');
    const loaded = cdp.once('Page.loadEventFired');
    await cdp.send('Page.navigate', { url: 'data:text/html,' + encodeURIComponent(`<form method="post" action="${BASE}${path}">${inputs}</form>`) });
    await loaded;
    await navigating(`document.forms[0].submit()`);
    check(`${what} form posted from another site is refused`, await js(`location.href === ${JSON.stringify(BASE + path)}
      && /^(Anfrage abgelehnt|Request refused)$/.test(document.querySelector('h1')?.textContent || '')`), await js(`location.href + ' ' + document.title`));
  }

  section('Parents: errors and appearance');
  await js(`localStorage.setItem('${KEY}', JSON.stringify({ ${JSON.stringify(roundId)}: [{ u: '/b/${fake}', l: 'x' }] }))`);
  await goto(`/${slug}`);
  await js(`document.querySelector('[data-remembered-another]').click()`); // to the form, the entry stays
  await js(`${firstFree}.checked = true; document.querySelector('#name').value = 'www.example.org'; document.querySelector('#privacy').checked = true;`);
  await navigating(`document.querySelector('#name').form.requestSubmit()`);
  check('error box gets the focus (screen readers)', await js(`document.activeElement && document.activeElement.id === 'form-errors'`));
  check('with errors the form stays visible, the stored appointment is listed above it', await js(`!document.getElementById('booking-area').hidden
    && !document.getElementById('remembered').hidden && document.querySelector('[data-remembered-another]').hidden`));
  await js(`localStorage.removeItem('${KEY}')`);

  check('appearance switch is shown', await js(`!document.querySelector('[data-theme-switch]').hidden`));
  await js(`document.querySelector('[data-theme-choice=dark]').click()`);
  check('"Dunkel" switches and is stored', await js(`document.documentElement.dataset.theme === 'dark' && localStorage.getItem('tp_theme') === 'dark'
    && document.querySelector('[data-theme-choice=dark]').getAttribute('aria-pressed') === 'true'`));
  await goto(`/${slug}`);
  check('the choice applies on the next page', await js(`document.documentElement.dataset.theme === 'dark'`));
  await js(`document.querySelector('[data-theme-choice=auto]').click()`);
  check('"Automatisch" follows the device again', await js(`!document.documentElement.hasAttribute('data-theme') && localStorage.getItem('tp_theme') === null`));
  check('no uncaught JavaScript errors on any page', cdp.errors.length === 0, cdp.errors.join('\n      '));
} catch (e) {
  check('test run completed', false, e.stack || String(e));
} finally {
  cdp.ws.close();
  chrome.kill();
  await sleep(200);
  rmSync(profile, { recursive: true, force: true });
}

console.log(failed === 0 ? 'ALL BROWSER TESTS PASSED' : `${failed} FAILED`);
process.exit(failed === 0 ? 0 : 1);
