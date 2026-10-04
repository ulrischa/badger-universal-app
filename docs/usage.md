# Badger Hub benutzen

Voraussetzung: [Server eingerichtet](installation-server.md). Für den ersten Test
reicht die Weboberfläche; ein externer Datenlieferant ist noch nicht erforderlich.

## 1. Erste App anlegen

1. Im Hub anmelden und **„App registrieren“** wählen.
2. App-ID `energie` und Displayname `Energie` eintragen. Die ID ist nach dem
   Anlegen nicht mehr über die Oberfläche veränderbar.
3. App aktiv lassen. Als TTL beispielsweise `900` Sekunden wählen.
4. Unter „Seiten und Werte (JSON)“ folgende Seiten eintragen:

```json
[
  {
    "title": "Energie heute",
    "rows": [
      {"label": "PV", "value": "5.8 kW"},
      {"label": "Akku", "value": "84 %"},
      {"label": "Netz", "value": "-2.1 kW"}
    ]
  },
  {
    "title": "Messung",
    "rows": [
      {"label": "Stand UTC", "value": "09:00"}
    ]
  }
]
```

5. Speichern. Den einmalig angezeigten **Publisher-Schlüssel** sichern.
6. „Vorschau“ anklicken; mit „Nächste Seite“ die zweite Seite anzeigen.

Es sind Beispielwerte, keine Verbindung zu deiner PV-Anlage. Eine App ist hier
ein Datensatz mit Anzeigeseiten. Erst ein eigener Publisher liefert echte Werte.
Wenn du Inhalte nur von Hand pflegst, brauchst du den Publisher-Schlüssel nicht
im laufenden Betrieb; der Administrator kann über die Oberfläche speichern.

## 2. Gerät hinzufügen und Apps zuweisen

1. **„Gerät hinzufügen“** wählen.
2. Geräte-ID `badger-flur`, Name `Badger im Flur` eintragen.
3. Aktualisierungsintervall beispielsweise auf `300` Sekunden setzen.
4. Die App „Energie“ anhaken, Gerät aktiv lassen und speichern.
5. Den getrennten **Geräteschlüssel** sichern und in `device/config.py` eintragen.
6. [Badger installieren](installation-badger.md) und starten.

Apps können mehreren Geräten zugewiesen werden. Jedes Gerät hat einen eigenen
Schlüssel und erhält nur seine aktiven zugewiesenen Apps. Pro Gerät sind maximal
zehn Apps möglich. Die bestehende Reihenfolge bleibt beim Bearbeiten erhalten;
neu ausgewählte Apps werden in Registry-Reihenfolge angehängt. Eine Drag-and-drop-
Sortierung gibt es noch nicht.

## 3. Auf dem Badger navigieren

| Taste | Menü | Geöffnete App |
|---|---|---|
| A | Ausgewählte App öffnen | Nächste Seite |
| Hoch/Runter | App auswählen | Vorige/nächste Seite |
| B | Hub-Daten abrufen | Hub-Daten abrufen |
| C | Menü / laufenden Abruf abbrechen | Zurück ins Menü / laufenden Abruf abbrechen |

Nach der letzten Seite beginnt das Blättern wieder vorne. C ist fest reserviert,
eine App kann den Rückweg nicht umdefinieren. Gedrückthalten wiederholt eine Aktion
nicht dauerhaft. Bei einem erfolgreichen Abruf wird die geöffnete App nach ihrer
ID wiedergefunden und auf ihre erste Seite gestellt. Ist sie verschwunden, erscheint
das Menü.

B aktualisiert **alle zugewiesenen Apps aus dem Hub**. Die Taste löst keinen neuen
Messvorgang bei der ursprünglichen Quelle aus. Das erklärt der [Datenfluss](data-flow.md).

## 4. Werte zunächst von Hand ändern

1. Bei „Energie“ auf „Bearbeiten“ klicken.
2. Im JSON beispielsweise den PV-Wert ändern und speichern.
3. Auf dem Badger B drücken oder den nächsten automatischen Abruf abwarten.

Bei einem Versionskonflikt wurden zwischenzeitlich Daten geändert. Eigene Eingaben
bei Bedarf separat sichern, die Liste neu laden und den aktuellen Stand prüfen,
bevor du erneut bearbeitest. Nicht blind über neuere Werte speichern.

## 5. Dynamische Daten per PHP liefern

`examples/publish.php` liest die aktuelle App-Version und veröffentlicht danach
vollständige Seiten. Es benötigt PHP CLI mit `ext-curl` und läuft auf einem Rechner,
der den Hub und die jeweilige Quelle erreichen kann. Das kann auch ein Raspberry
Pi im Heimnetz oder ein separater Cronjob auf dem Webserver sein.

Zum einmaligen Test in Bash:

```bash
export BADGER_URL='https://badge.example.com'
read -rsp 'Publisher-Schlüssel: ' BADGER_APP_TOKEN
printf '\n'
export BADGER_APP_TOKEN
php examples/publish.php
unset BADGER_APP_TOKEN BADGER_URL
```

Erwartete Ausgabe: `Published version ...`. Danach B drücken. Im Beispiel werden
feste Demo-Werte gesendet. Für echte Daten die Quelle im eigenen Publisher auslesen
und `$screens` damit füllen. Änderungen im Beispiel in einer eigenen privaten
Kopie vornehmen, damit ein Projektupdate sie nicht überschreibt.

Für regelmäßige Aktualisierung:

1. Einen eigenen Publisher erstellen und manuell testen.
2. Schlüssel in der privaten Umgebung des Schedulers bereitstellen. Nicht in
   URLs, öffentlich abgelegten Dateien oder Befehlszeilen mit sichtbaren Tokens speichern.
3. Beispielsweise alle 60 Sekunden starten. Keine überlappenden Ausführungen;
   Datenquellen ebenfalls mit Zeitlimits abfragen.
4. Fehler im Scheduler beobachten. Bei fehlenden Quelldaten nicht einfach alte
   Messwerte als frisch veröffentlichen.
5. Prüfen, dass sich Messzeit und Werte im Hub tatsächlich aktualisieren.

Der Hub führt den Publisher nicht selbst aus. Der Cronjob ist vom Webrequest des
Badgers unabhängig. Fehlt ein Scheduler, bleibt die zuletzt gespeicherte Seite
stehen und wird nach Ablauf der TTL als alt gekennzeichnet.

### Andere Automatisierungen

Node-RED, Home Assistant oder JavaScript können dieselbe [HTTP-API](api.md) verwenden:

1. `GET /api.php?r=app-status`, Header `Authorization: Bearer APP_TOKEN`.
2. Antwortfeld `version` übernehmen.
3. `POST /api.php?r=publish` mit demselben Header und `Content-Type: application/json`.
4. Body: Objekt mit `version` und `screens` (dem Seitenarray aus Schritt 1).

Der JSON-Editor der Verwaltung enthält **nur das Seitenarray**; die Publisher-API
erwartet dagegen **ein Objekt mit `version` und `screens`**. Publisher dürfen ihre
eigenen Seiten ersetzen, aber keine anderen Apps oder Geräte verändern.

HTTP 409 bedeutet Versionskonflikt: neu lesen und entscheiden. Nach einem Timeout
kann das Speichern bereits erfolgt sein. Nicht unbegrenzt oder blind wiederholen.
Eine erfolgreiche Veröffentlichung ist noch keine Bestätigung, dass ein Badger
sie bereits angezeigt hat.

## 6. Intervalle und Aktualität verstehen

| Begriff | Bedeutung |
|---|---|
| Publisher-Intervall | Häufigkeit neuer Quelldaten im Hub; außerhalb des Hubs eingestellt |
| Geräteintervall | Häufigkeit des Manifest-Abrufs; pro Gerät eingestellt |
| TTL | Alter ab Serverempfang, ab dem App-Daten als alt gelten; pro App eingestellt |
| Letzter Gerätekontakt | Letzter authentifizierter Manifest-Aufruf, keine Renderbestätigung |

Bei 60 Sekunden Quellenabfrage und 300 Sekunden Geräteintervall kann eine Änderung
fast sechs Minuten plus Verarbeitungszeit brauchen, bis sie sichtbar wird.
Mit B verkürzt du den zweiten Anteil. TTL löst weder Abruf noch Messung aus.
Eine TTL von 900 Sekunden ist für diese Beispielintervalle ein sinnvoller Startwert,
muss aber zu deiner Quelle und tolerierbaren Veralterung passen.

`ONLINE` bedeutet, dass der letzte Abruf erfolgreich war. Es ist kein laufender
Verbindungstest, weil WLAN zwischen Abrufen ausgeschaltet wird. `OLD DATA` betrifft
das Alter einer App; `OFFLINE` einen fehlgeschlagenen Abruf. Bei längerem Stillstand
kann der Offline-Hinweis die Altersanzeige ersetzen.

Zwischen zwei Geräteabrufen veröffentlichte Zwischenstände werden nicht
nachträglich angezeigt. Der Hub speichert den neuesten Zustand, keine Warteschlange.
Für jede einzelne Alarmmeldung mit Zustellbestätigung ist diese API nicht ausgelegt.

## 7. Schlüssel, Deaktivierung und Löschen

- **App pausieren:** App deaktivieren. Nach dem nächsten erfolgreichen Abruf
  verschwindet sie vom Gerät; ihr Publisher erhält währenddessen keinen Zugriff.
- **Gerät pausieren:** Gerät deaktivieren. Sein Schlüssel wird für Abrufe abgewiesen.
- **Schlüssel erneuern:** Alten Schlüssel sofort ungültig machen. Neuen Schlüssel
  im Publisher bzw. über USB in der Geräte-Konfiguration ersetzen.
- **App löschen:** Entfernt die App und ihre Zuweisungen. Der nächste Geräteabruf
  enthält sie nicht mehr.
- **Gerät löschen:** Entfernt Gerät und Zuweisungen; Apps bleiben bestehen.

Ein offline liegendes Gerät behält seinen lokalen Cache und sein E-Ink-Bild.
Deaktivieren, Löschen und Schlüsselrotation löschen diese Daten nicht aus der Ferne.
Verlorene Einmalschlüssel lassen sich nicht wieder anzeigen; einen neuen erzeugen.

## 8. Grenzen dieser Version

- Nur ASCII-Displaytexte, beispielsweise `Waerme`, `deg C`, `ug/m3`.
- Bis zu sechs Seiten je App und drei Zeilen je Seite.
- Titel: App 24, Seite 28 Zeichen; Zeile: Bezeichnung 14, Wert 22 Zeichen.
- Kein Download ausführbarer Apps, keine Gerätesteuerung, keine QR-/Bildseiten.
- Kein nativer Home-Assistant-/Fronius-Adapter; die API ist die Integrationsschnittstelle.
- USB-Dauerbetrieb; Batterieschlaf noch nicht implementiert.
- Hardware-Abnahme offen, siehe [Prüfliste](verification.md).

Zurück: [README](../README.md) · [Serverinstallation](installation-server.md) · [Badgerinstallation](installation-badger.md).
