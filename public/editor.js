// Form and JSON views share one canonical page model. No HTML from data is rendered.
const copy = value => JSON.parse(JSON.stringify(value));
const textError = (value, max) => typeof value !== 'string' || !value.length || value.length > max || /[^\x20-\x7e]/.test(value);
export function validateScreens(screens, mode = 'values') {
  const keys = (value, expected) => value && typeof value === 'object' && !Array.isArray(value) &&
    Object.keys(value).sort().join(',') === [...expected].sort().join(',');
  if (!Array.isArray(screens) || screens.length < 1 || screens.length > 6) throw new Error('Bitte 1–6 Seiten angeben.');
  screens.forEach((screen, index) => {
    if (!keys(screen, ['title', 'rows']) || textError(screen.title, 28)) throw new Error(`Seite ${index + 1}: Titel benötigt 1–28 druckbare ASCII-Zeichen.`);
    if (!Array.isArray(screen.rows) || screen.rows.length < 1 || screen.rows.length > 3) throw new Error(`Seite ${index + 1}: Bitte 1–3 Zeilen angeben.`);
    screen.rows.forEach((row, rowIndex) => {
      const bound = row && Object.hasOwn(row, 'field');
      const valid = bound
        ? mode === 'values' && keys(row, ['label', 'field', 'unit', 'decimals']) &&
          typeof row.field === 'string' && /^[a-z][a-z0-9_]{0,31}$/.test(row.field) &&
          (row.unit === '' || !textError(row.unit, 8)) && Number.isInteger(row.decimals) && row.decimals >= 0 && row.decimals <= 3
        : keys(row, ['label', 'value']) && !textError(row.value, 22);
      if (!valid || textError(row.label, 14)) {
        throw new Error(`Seite ${index + 1}, Zeile ${rowIndex + 1}: Bezeichnung 1–14, Text 1–22 ASCII-Zeichen oder gültiges Datenfeld mit Einheit (0–8) und Nachkommastellen (0–3).`);
      }
    });
  });
  return screens;
}
function element(tag, text, className) {
  const result = document.createElement(tag);
  if (text !== undefined) result.textContent = text;
  if (className) result.className = className;
  return result;
}
export class PageEditor {
  constructor(root) {
    this.root = root;
    this.find = id => root.querySelector(`#${id}`);
    this.jsonDirty = false;
    this.locked = false;
    this.find('editor-page').addEventListener('change', event => { this.index = Number(event.target.value); this.render(); });
    this.find('page-add').addEventListener('click', () => {
      if (this.screens.length >= 6) return;
      this.screens.push({title: 'Neue Seite', rows: [{label: 'Wert', value: '-'}]});
      this.index = this.screens.length - 1; this.render('page-title');
    });
    this.find('page-delete').addEventListener('click', () => {
      if (this.screens.length <= 1 || !confirm('Diese Seite mit ihren Zeilen entfernen?')) return;
      this.screens.splice(this.index, 1); this.index = Math.min(this.index, this.screens.length - 1); this.render('editor-page');
    });
    this.find('page-up').addEventListener('click', () => this.movePage(-1));
    this.find('page-down').addEventListener('click', () => this.movePage(1));
    this.find('row-add').addEventListener('click', () => {
      const rows = this.screens[this.index].rows;
      if (rows.length >= 3) return;
      rows.push({label: 'Wert', value: '-'}); this.render(`row-${rows.length - 1}-label`);
    });
    this.find('app-screens').addEventListener('input', () => {
      this.jsonDirty = true; this.find('json-error').textContent = ''; this.find('json-result').textContent = ''; this.syncControls();
    });
    this.find('json-apply').addEventListener('click', () => this.applyJson());
    this.find('json-discard').addEventListener('click', () => {
      this.jsonDirty = false; this.find('json-error').textContent = ''; this.find('json-result').textContent = ''; this.render();
    });
  }
  load(screens, mode = 'values', values = {}) {
    validateScreens(screens, mode);
    this.mode = mode; this.values = values;
    this.find('available-fields').textContent = Object.keys(values).length
      ? `Zuletzt empfangene Datenfelder: ${Object.keys(values).join(', ')}`
      : 'Noch keine Datenfelder empfangen. Du kannst frei benannte Felder vorab eintragen.';
    this.screens = copy(screens); this.index = 0; this.jsonDirty = false; this.locked = false;
    this.find('advanced-pages').open = false; this.find('json-error').textContent = ''; this.find('json-result').textContent = '';
    this.render();
  }
  setMode(mode) {
    if (this.jsonDirty) throw new Error('JSON-Änderungen zuerst übernehmen oder verwerfen.');
    if (mode === 'pages' && this.screens.some(screen => screen.rows.some(row => Object.hasOwn(row, 'field')))) {
      throw new Error('Datenfeld-Zeilen zuerst in feste Texte umwandeln, bevor der Publisher ganze Seiten liefern darf.');
    }
    this.mode = mode; this.render();
  }
  setLocked(locked) { this.locked = locked; this.syncControls(); }
  syncControls() {
    this.find('page-fields').disabled = this.locked || this.jsonDirty;
    this.find('json-fields').disabled = this.locked;
    this.find('json-pending').hidden = !this.jsonDirty;
    if (!this.screens) return;
    this.find('page-add').disabled = this.screens.length >= 6;
    this.find('page-delete').disabled = this.screens.length <= 1;
    this.find('page-up').disabled = this.index === 0;
    this.find('page-down').disabled = this.index === this.screens.length - 1;
    this.find('row-add').disabled = this.screens[this.index].rows.length >= 3;
  }
  movePage(direction) {
    const next = this.index + direction;
    if (next < 0 || next >= this.screens.length) return;
    [this.screens[this.index], this.screens[next]] = [this.screens[next], this.screens[this.index]];
    this.index = next; this.render('editor-page');
  }
  field(id, title, max, value, onInput, optional = false, pattern = '[\\x20-\\x7E]+') {
    const wrapper = element('div', undefined, 'editor-field');
    const label = element('label', title); label.htmlFor = id;
    const input = element('input'); input.id = id; input.name = id; input.value = value;
    input.required = !optional; input.maxLength = max; input.pattern = pattern;
    const hint = element('span', `${value.length}/${max} Zeichen`, 'hint'); hint.id = `${id}-count`;
    input.setAttribute('aria-describedby', `${hint.id} screen-help`);
    input.addEventListener('input', () => {
      hint.textContent = `${input.value.length}/${max} Zeichen`;
      input.setCustomValidity(''); input.removeAttribute('aria-invalid');
      onInput(input.value); this.changed();
    });
    input.addEventListener('invalid', () => {
      input.setAttribute('aria-invalid', 'true');
      input.setCustomValidity(`Bitte ${title} prüfen: ${optional ? 0 : 1}–${max} Zeichen im angegebenen Format.`);
    });
    wrapper.append(label, input, hint); return wrapper;
  }
  updateOptions() {
    this.find('editor-page').replaceChildren(...this.screens.map((screen, index) => {
      const option = element('option', `${index + 1}. ${screen.title || 'Ohne Titel'}`);
      option.value = index; option.selected = index === this.index; return option;
    }));
  }
  changed() {
    this.updateOptions(); this.renderPreview();
    if (!this.jsonDirty) this.find('app-screens').value = JSON.stringify(this.screens, null, 2);
  }
  render(focusId) {
    const screen = this.screens[this.index];
    this.find('page-title-field').replaceChildren(this.field('page-title', 'Seitentitel', 28, screen.title, value => { screen.title = value; }));
    this.find('editor-rows').replaceChildren(...screen.rows.map((row, index) => {
      const group = element('fieldset', undefined, 'editor-row');
      group.append(element('legend', `Zeile ${index + 1}`));
      const fields = element('div', undefined, 'editor-row-fields');
      fields.append(this.field(`row-${index}-label`, 'Bezeichnung', 14, row.label, value => { row.label = value; }));
      const bound = Object.hasOwn(row, 'field');
      if (this.mode === 'values') {
        const typeLabel = element('label', 'Inhalt'); typeLabel.htmlFor = `row-${index}-type`;
        const type = element('select'); type.id = typeLabel.htmlFor;
        for (const [value, title] of [['text', 'Fester Text'], ['field', 'Datenfeld']]) {
          const option = element('option', title); option.value = value; option.selected = (value === 'field') === bound; type.append(option);
        }
        type.addEventListener('change', () => {
          screen.rows[index] = type.value === 'field'
            ? {label: row.label, field: '', unit: '', decimals: 1}
            : {label: row.label, value: '--'};
          this.render(`row-${index}-${type.value === 'field' ? 'field' : 'value'}`);
        });
        const typeField = element('div'); typeField.append(typeLabel, type); fields.append(typeField);
      }
      if (bound) {
        fields.append(this.field(`row-${index}-field`, 'Datenfeld (a-z, 0-9, _)', 32, row.field, value => { row.field = value; }, false, '[a-z][a-z0-9_]{0,31}'),
          this.field(`row-${index}-unit`, 'Einheit (optional)', 8, row.unit, value => { row.unit = value; }, true));
        const label = element('label', 'Nachkommastellen'); label.htmlFor = `row-${index}-decimals`;
        const select = element('select'); select.id = label.htmlFor;
        for (let count = 0; count <= 3; count++) {
          const option = element('option', String(count)); option.value = count; option.selected = row.decimals === count; select.append(option);
        }
        select.addEventListener('change', () => { row.decimals = Number(select.value); this.changed(); });
        const precision = element('div'); precision.append(label, select); fields.append(precision);
      } else {
        fields.append(this.field(`row-${index}-value`, 'Wert', 22, row.value, value => { row.value = value; }));
      }
      const remove = element('button', 'Zeile entfernen', 'secondary'); remove.type = 'button';
      remove.disabled = screen.rows.length <= 1; remove.setAttribute('aria-label', `Zeile ${index + 1} entfernen`);
      remove.addEventListener('click', () => {
        if (screen.rows.length <= 1) return;
        screen.rows.splice(index, 1); this.render(`row-${Math.min(index, screen.rows.length - 1)}-label`);
      });
      group.append(fields, remove); return group;
    }));
    this.changed(); this.syncControls();
    if (focusId) this.find(focusId)?.focus();
  }
  previewValue(row) {
    if (!Object.hasOwn(row, 'field')) return row.value || 'Wert';
    const value = Object.hasOwn(this.values, row.field) ? this.values[row.field] : null;
    if (value === null) return '--';
    let text = typeof value === 'number' ? value.toFixed(row.decimals) : String(value);
    if (row.unit) text += ` ${row.unit}`;
    return text.length > 22 ? 'OVERFLOW' : text;
  }
  renderPreview() {
    const screen = this.screens[this.index];
    this.find('editor-preview-title').textContent = screen.title || 'Seitentitel';
    this.find('editor-preview-rows').replaceChildren(...screen.rows.map(row => {
      const line = element('div', undefined, 'row');
      line.append(element('span', row.label || 'Bezeichnung'), element('strong', this.previewValue(row))); return line;
    }));
    this.find('editor-preview-caption').textContent = `Seite ${this.index + 1} von ${this.screens.length} · noch nicht gespeichert`;
  }
  applyJson() {
    try {
      const raw = this.find('app-screens').value;
      if (raw.length > 16384) throw new Error('JSON ist zu groß (maximal 16 KiB).');
      let screens;
      try { screens = JSON.parse(raw); } catch { throw new Error('Seiten enthalten kein gültiges JSON.'); }
      validateScreens(screens, this.mode);
      // Replace only after the entire input passes validation.
      this.screens = copy(screens); this.index = Math.min(this.index, screens.length - 1);
      this.jsonDirty = false; this.find('json-error').textContent = ''; this.find('json-result').textContent = ''; this.render();
      this.find('json-result').textContent = 'JSON übernommen. Zum Speichern „App speichern“ wählen.';
    } catch (error) {
      this.find('json-error').textContent = error.message;
    }
  }
  value() {
    if (this.jsonDirty) {
      this.find('advanced-pages').open = true; this.find('app-screens').focus();
      throw new Error('JSON-Änderungen zuerst übernehmen oder verwerfen.');
    }
    for (let index = 0; index < this.screens.length; index++) {
      try { validateScreens([this.screens[index]], this.mode); }
      catch {
        this.index = index; this.render();
        this.find('page-fields').querySelector('input:invalid')?.reportValidity();
        throw new Error(`Bitte die Eingaben auf Seite ${index + 1} korrigieren.`);
      }
    }
    return copy(validateScreens(this.screens, this.mode));
  }
}
