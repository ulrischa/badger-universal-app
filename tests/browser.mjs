// Requires an externally installed Playwright; no browser dependency is deployed.
import assert from 'node:assert/strict';
import {mkdir} from 'node:fs/promises';
const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({headless: true,
  executablePath: process.env.CHROMIUM_EXECUTABLE || undefined,
  args: process.env.CHROMIUM_EXECUTABLE ? ['--no-sandbox', '--disable-dev-shm-usage'] : []});
const page = await browser.newPage({viewport: {width: 1280, height: 1000}});
const errors = [];
page.on('pageerror', error => errors.push(error.message));
page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
page.on('dialog', dialog => dialog.accept());
try {
  await page.goto('http://127.0.0.1:8080/');
  await page.getByLabel('Administrator-Passwort').fill(process.env.BADGER_TEST_PASSWORD);
  await page.getByRole('button', {name: 'Hub öffnen'}).click();
  await page.getByRole('button', {name: '+ App registrieren', exact: true}).click();
  await page.getByLabel('App-ID', {exact: true}).fill('energie');
  await page.getByLabel('Name auf dem Display').fill('Energie');
  await page.getByRole('button', {name: 'App speichern'}).click();
  await page.getByRole('button', {name: 'Gesichert · schließen'}).click();
  await page.getByRole('button', {name: '+ Gerät hinzufügen', exact: true}).click();
  await page.getByLabel('Geräte-ID', {exact: true}).fill('flur');
  await page.getByLabel('Gerätename', {exact: true}).fill('Badger im Flur');
  await page.locator('#assignments').getByLabel('Energie', {exact: true}).check();
  await page.getByRole('button', {name: 'Gerät speichern'}).click();
  await page.getByRole('button', {name: 'Gesichert · schließen'}).click();
  await page.locator('#devices').getByRole('heading', {name: 'Badger im Flur'}).waitFor();
  assert.equal(await page.locator('#app-count').textContent(), '1');
  assert.equal(await page.locator('#device-count').textContent(), '1');
  await page.locator('#apps').getByRole('button', {name: 'Bearbeiten'}).click();
  await page.getByLabel('Seiten und Werte (JSON)').fill('{invalid');
  await page.getByRole('button', {name: 'App speichern'}).click();
  await page.getByText('Seiten enthalten kein gültiges JSON.').waitFor();
  await page.getByRole('button', {name: 'Abbrechen', exact: true}).click();
  await page.locator('#apps').getByRole('button', {name: 'Schlüssel erneuern'}).click();
  await page.getByRole('button', {name: 'Gesichert · schließen'}).click();
  await page.getByRole('button', {name: 'Liste neu laden'}).click();
  await page.getByText('Liste aktualisiert.', {exact: true}).waitFor();
  await mkdir('test-results', {recursive: true});
  await page.screenshot({path: 'test-results/desktop.png', fullPage: true});
  await page.setViewportSize({width: 390, height: 844});
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  await page.screenshot({path: 'test-results/mobile.png', fullPage: true});
  await page.getByRole('button', {name: 'Abmelden', exact: true}).click();
  await page.getByRole('button', {name: 'Hub öffnen'}).waitFor();
  assert.deepEqual(errors, []);
  console.log('Browser checks passed: login, app/device creation, assignment, invalid JSON, rotation, reload, logout, mobile overflow; no console errors.');
} finally { await browser.close(); }
