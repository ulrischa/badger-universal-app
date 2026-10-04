<?php
declare(strict_types=1);
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
if (($_SERVER['HTTPS'] ?? '') === 'on') { header('Strict-Transport-Security: max-age=31536000'); }
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Badger Hub · Apps und Geräte</title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="style.css"><script src="app.js" defer></script>
</head>
<body>
<a class="skip" href="#main">Zum Inhalt</a>
<header><a class="brand" href="./">▦ <span>BADGER HUB</span></a><span class="tag">Ein Display. Deine Apps.</span><button id="logout" hidden>Abmelden</button></header>
<main id="main">
<div class="intro"><p class="eyebrow">DEIN E-INK-KONTROLLZENTRUM</p><h1>Kleine Anzeige.<br>Viele Möglichkeiten.</h1><p>Apps registrieren, Inhalte aktualisieren und deinem Badger zuweisen.</p></div>
<p id="notice" role="status" aria-live="polite"></p>
<noscript><p>Für diese Verwaltung ist JavaScript erforderlich. Die API kann unabhängig davon verwendet werden.</p></noscript>
<section id="login-panel" class="panel narrow" hidden><h2>Anmelden</h2><form id="login-form" method="post" action="api.php?r=login"><label for="password">Administrator-Passwort</label><input id="password" name="password" type="password" autocomplete="current-password" required maxlength="72"><button type="submit">Hub öffnen</button></form></section>
<div id="workspace" hidden>
<nav class="toolbar" aria-label="Verwaltung"><button id="new-app">+ App registrieren</button><button id="new-device" class="secondary">+ Gerät hinzufügen</button><button id="reload" class="secondary">Liste neu laden</button></nav>
<div class="layout"><div><section class="panel"><div class="section-head"><h2>Deine Apps</h2><span id="app-count" class="pill">0</span></div><p class="hint">Jede App erhält einen eigenen Schlüssel zum Liefern ihrer Anzeigedaten.</p><div id="apps" class="cards"></div></section>
<section class="panel"><div class="section-head"><h2>Deine Geräte</h2><span id="device-count" class="pill">0</span></div><div id="devices" class="cards"></div></section></div>
<aside><section class="panel preview-panel"><p class="eyebrow">DISPLAY-VORSCHAU</p><div class="badge"><div class="screen"><h2 id="preview-title">Bereit für deine Ideen</h2><div id="preview-rows"><p>Registriere deine erste App.</p></div><div class="screen-footer">A Weiter · B Laden · C Menü</div></div><div class="badge-buttons"><span>A</span><span>B</span><span>C</span></div></div><button id="preview-next" class="secondary">Nächste Seite</button><p class="hint">Layoutvorschau für 296 × 128 Pixel. C führt auf dem Gerät immer ins Menü.</p></section><section class="panel"><h2>So kommt Inhalt aufs Display</h2><ol><li>App registrieren und Schlüssel sichern.</li><li>App einem Gerät zuweisen.</li><li>Daten per API liefern oder hier bearbeiten.</li></ol><p class="hint">Eine unterbrochene Verbindung lässt die letzte gültige Anzeige verfügbar. Alte Daten werden gekennzeichnet.</p></section></aside></div>
<details class="panel"><summary>Letzte Verwaltungsaktivitäten</summary><ul id="audit"></ul></details>
</div>
</main>
<dialog id="app-dialog" aria-labelledby="app-heading"><form id="app-form" method="post" action="api.php?r=app"><h2 id="app-heading">App registrieren</h2><label for="app-id">App-ID</label><input id="app-id" name="id" required pattern="[a-z][a-z0-9\-]{0,31}" maxlength="32" placeholder="energie"><label for="app-title">Name auf dem Display</label><input id="app-title" name="title" required maxlength="24" placeholder="Energie"><div class="two"><div><label for="app-ttl">Nach wie vielen Sekunden sind Daten alt?</label><input id="app-ttl" name="ttl" type="number" min="60" max="604800" value="1800" required></div><label class="check"><input id="app-enabled" name="enabled" type="checkbox" checked> App aktiv</label></div><label for="app-screens">Seiten und Werte (JSON)</label><textarea id="app-screens" name="screens" rows="12" spellcheck="false" required aria-describedby="screen-help"></textarea><p id="screen-help" class="hint">1–6 Seiten mit je 1–3 Zeilen. Titel: 28, Bezeichnung: 14, Wert: 22 Zeichen. Displaytexte in ASCII, z. B. „Waerme“ statt „Wärme“.</p><p class="form-error" role="alert"></p><div class="actions"><button type="submit">App speichern</button><button type="button" class="secondary" data-close>Abbrechen</button></div></form></dialog>
<dialog id="device-dialog" aria-labelledby="device-heading"><form id="device-form" method="post" action="api.php?r=device"><h2 id="device-heading">Gerät hinzufügen</h2><label for="device-id">Geräte-ID</label><input id="device-id" name="id" required pattern="[a-z][a-z0-9\-]{0,31}" maxlength="32" placeholder="badger-flur"><label for="device-name">Gerätename</label><input id="device-name" name="name" required maxlength="64" placeholder="Badger im Flur"><label for="device-refresh">Aktualisierung alle … Sekunden</label><input id="device-refresh" name="refresh" type="number" min="60" max="86400" value="900" required><label class="check"><input id="device-enabled" name="enabled" type="checkbox" checked> Gerät aktiv</label><fieldset><legend>Zugewiesene Apps (maximal 10)</legend><div id="assignments"></div></fieldset><p class="form-error" role="alert"></p><div class="actions"><button type="submit">Gerät speichern</button><button type="button" class="secondary" data-close>Abbrechen</button></div></form></dialog>
<dialog id="token-dialog" aria-labelledby="token-heading"><h2 id="token-heading">Schlüssel jetzt sichern</h2><p>Er wird nur einmal angezeigt. Bei Verlust kannst du einen neuen erzeugen.</p><label for="new-token">API-Schlüssel</label><textarea id="new-token" rows="3" readonly spellcheck="false"></textarea><p id="token-context" class="hint"></p><button id="token-done">Gesichert · schließen</button></dialog>
<footer>Badger Hub · PHP + JavaScript · App-Protokoll v1</footer>
</body></html>
