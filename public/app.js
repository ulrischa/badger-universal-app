'use strict';
const $ = (id) => document.getElementById(id);
let csrf = '';
let state = {apps: [], devices: []};
let appVersion = 0;
let deviceVersion = 0;
let previewScreens = [];
let previewIndex = 0;
let busy = false;
const sampleScreens = [{title: 'Energie heute', rows: [{label: 'PV', value: '5.8 kW'}, {label: 'Akku', value: '84 %'}, {label: 'Netz', value: '-2.1 kW'}]}];

async function api(route, body) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 10000);
  try {
    const response = await fetch(`api.php?r=${encodeURIComponent(route)}`, {
      method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: body === undefined ? {} : {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
      body: body === undefined ? undefined : JSON.stringify(body), signal: controller.signal
    });
    const data = await response.json();
    if (!response.ok) {
      if (response.status === 401 && route !== 'login') {
        $('workspace').hidden = true; $('logout').hidden = true; $('login-panel').hidden = false;
      }
      throw new Error(data.error || `HTTP ${response.status}`);
    }
    return data;
  } catch (error) {
    if (error.name === 'AbortError') throw new Error('Zeitlimit erreicht. Bei Speichervorgängen ist das Ergebnis unklar: Liste neu laden, bevor du erneut speicherst.');
    throw error;
  } finally { clearTimeout(timeout); }
}
function notice(message, error = false) {
  $('notice').textContent = message; $('notice').classList.toggle('error', error);
}
async function run(task, form = null) {
  if (busy) return;
  busy = true;
  const buttons = [...document.querySelectorAll('button')].filter(button => !button.disabled);
  buttons.forEach(button => { button.disabled = true; });
  if (form) form.querySelector('.form-error')?.replaceChildren();
  try { await task(); }
  catch (error) {
    const target = form?.closest('dialog')?.open ? form.querySelector('.form-error') : null;
    if (target) target.textContent = error.message;
    else notice(error.message, true);
  } finally {
    busy = false; buttons.forEach(button => { button.disabled = false; });
  }
}
function node(tag, text, className) {
  const element = document.createElement(tag);
  if (text !== undefined) element.textContent = text;
  if (className) element.className = className;
  return element;
}
function button(text, handler, className = 'secondary') {
  const element = node('button', text, className); element.type = 'button';
  element.addEventListener('click', handler); return element;
}
function showToken(result, kind) {
  if (!result.token) return;
  $('new-token').value = result.token;
  $('token-context').textContent = `${kind === 'app' ? 'Publisher' : 'Gerät'}: ${result.id}. Schlüssel ausschließlich im Authorization-Header verwenden.`;
  $('token-dialog').showModal();
}
function showPreview(screens) {
  previewScreens = screens; previewIndex = 0; renderPreview();
}
function renderPreview() {
  const screen = previewScreens[previewIndex];
  if (!screen) return;
  $('preview-title').textContent = screen.title;
  $('preview-rows').replaceChildren(...screen.rows.map(row => {
    const element = node('div', undefined, 'row');
    element.append(node('span', row.label), node('strong', row.value)); return element;
  }));
}
function dateLabel(timestamp) { return timestamp ? new Date(timestamp * 1000).toLocaleString('de-DE') : 'noch nicht verbunden'; }
async function reload() {
  state = await api('state'); render();
}
function render() {
  $('app-count').textContent = state.apps.length;
  $('device-count').textContent = state.devices.length;
  for (const kind of ['app', 'device']) {
    const collection = kind === 'app' ? state.apps : state.devices;
    const container = $(kind === 'app' ? 'apps' : 'devices');
    container.replaceChildren();
    if (!collection.length) container.append(node('p', kind === 'app' ? 'Noch keine Apps. Registriere deine erste Anzeige.' : 'Noch keine Geräte. Verbinde deinen ersten Badger.', 'hint'));
    for (const item of collection) {
      const card = node('article', undefined, 'card');
      const heading = node('div', undefined, 'section-head');
      heading.append(node('h3', item.title || item.name), node('span', item.enabled ? 'Aktiv' : 'Pausiert', 'pill'));
      const outdated = kind === 'app' && Date.now() / 1000 - item.updated_at > item.ttl;
      card.append(heading, node('p', kind === 'app'
        ? `${item.id} · ${item.screens.length} Seiten · ${outdated ? 'Daten veraltet · ' : ''}${dateLabel(item.updated_at)}`
        : `${item.id} · ${item.apps.length} Apps · Kontakt: ${dateLabel(item.last_seen)}`));
      const actions = node('div', undefined, 'actions');
      actions.append(button('Bearbeiten', () => kind === 'app' ? editApp(item) : editDevice(item)));
      if (kind === 'app') actions.append(button('Vorschau', () => showPreview(item.screens)));
      actions.append(button('Schlüssel erneuern', () => {
        if (!confirm(`Schlüssel für „${item.title || item.name}“ ersetzen? Der bisherige Schlüssel wird sofort ungültig.`)) return;
        run(async () => {
          const result = await api('rotate', {kind, id: item.id, version: item.version});
          showToken(result, kind); await reload();
        });
      }));
      actions.append(button('Löschen', () => {
        if (!confirm(`„${item.title || item.name}“ und die zugehörigen Zuweisungen löschen?`)) return;
        run(async () => { await api('delete', {kind, id: item.id, version: item.version}); await reload(); notice('Eintrag gelöscht.'); });
      }, 'danger'));
      card.append(actions); container.append(card);
    }
  }
  $('audit').replaceChildren(...state.audit.map(event => node('li', `${dateLabel(event.at)} · ${event.event} · ${event.target}`)));
}
function editApp(item = null) {
  $('app-form').reset(); $('app-form').querySelector('.form-error').textContent = '';
  appVersion = item?.version || 0;
  $('app-heading').textContent = item ? 'App bearbeiten' : 'App registrieren';
  $('app-id').value = item?.id || ''; $('app-id').readOnly = !!item;
  $('app-title').value = item?.title || '';
  $('app-enabled').checked = item?.enabled ?? true;
  $('app-ttl').value = item?.ttl || 1800;
  $('app-screens').value = JSON.stringify(item?.screens || sampleScreens, null, 2);
  $('app-dialog').showModal();
}
function editDevice(item = null) {
  $('device-form').reset(); $('device-form').querySelector('.form-error').textContent = '';
  deviceVersion = item?.version || 0;
  $('device-heading').textContent = item ? 'Gerät bearbeiten' : 'Gerät hinzufügen';
  $('device-id').value = item?.id || ''; $('device-id').readOnly = !!item;
  $('device-name').value = item?.name || '';
  $('device-enabled').checked = item?.enabled ?? true;
  $('device-refresh').value = item?.refresh || 900;
  $('assignments').replaceChildren(...state.apps.map(app => {
    const label = node('label', undefined, 'check');
    const input = node('input'); input.type = 'checkbox'; input.value = app.id; input.name = 'apps';
    input.checked = item?.apps.includes(app.id) || false;
    label.append(input, document.createTextNode(`${app.title}${app.enabled ? '' : ' (pausiert)'}`)); return label;
  }));
  if (!state.apps.length) $('assignments').append(node('p', 'Registriere zuerst eine App.'));
  $('device-dialog').showModal();
}
$('login-form').addEventListener('submit', event => {
  event.preventDefault(); run(async () => {
    const result = await api('login', {password: $('password').value});
    $('password').value = ''; csrf = result.csrf;
    $('login-panel').hidden = true; $('workspace').hidden = false; $('logout').hidden = false;
    await reload(); notice('');
  });
});
$('logout').addEventListener('click', () => run(async () => { await api('logout', {}); location.reload(); }));
$('new-app').addEventListener('click', () => editApp());
$('new-device').addEventListener('click', () => editDevice());
$('reload').addEventListener('click', () => run(async () => { await reload(); notice('Liste aktualisiert.'); }));
$('preview-next').addEventListener('click', () => { if (previewScreens.length) { previewIndex = (previewIndex + 1) % previewScreens.length; renderPreview(); } });
$('app-form').addEventListener('submit', event => {
  event.preventDefault(); run(async () => {
    let screens;
    try { screens = JSON.parse($('app-screens').value); } catch { throw new Error('Seiten enthalten kein gültiges JSON.'); }
    const result = await api('app', {id: $('app-id').value, title: $('app-title').value,
      enabled: $('app-enabled').checked, ttl: Number($('app-ttl').value), screens, version: appVersion});
    $('app-dialog').close(); showToken(result, 'app'); showPreview(screens); await reload(); notice('App gespeichert.');
  }, $('app-form'));
});
$('device-form').addEventListener('submit', event => {
  event.preventDefault(); run(async () => {
    const selected = [...$('assignments').querySelectorAll('input:checked')].map(input => input.value);
    const previous = state.devices.find(device => device.id === $('device-id').value)?.apps || [];
    const ordered = [...previous.filter(id => selected.includes(id)), ...selected.filter(id => !previous.includes(id))];
    const result = await api('device', {id: $('device-id').value, name: $('device-name').value,
      enabled: $('device-enabled').checked, refresh: Number($('device-refresh').value), version: deviceVersion,
      apps: ordered});
    $('device-dialog').close(); showToken(result, 'device'); await reload(); notice('Gerät gespeichert.');
  }, $('device-form'));
});
for (const element of document.querySelectorAll('[data-close]')) element.addEventListener('click', () => element.closest('dialog').close());
$('token-done').addEventListener('click', () => $('token-dialog').close());
$('token-dialog').addEventListener('close', () => { $('new-token').value = ''; });
run(async () => {
  const session = await api('session'); csrf = session.csrf;
  $('login-panel').hidden = session.authenticated; $('workspace').hidden = !session.authenticated;
  $('logout').hidden = !session.authenticated;
  if (session.authenticated) await reload();
});
