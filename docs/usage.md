# Badger Hub benutzen

Voraussetzung: [Server eingerichtet](installation-server.md). Für den ersten Test
reicht die Weboberfläche; ein externer Datenlieferant ist noch nicht erforderlich.

## 1. Erste App anlegen

1. Im Hub anmelden und **„App registrieren“** wählen.
2. App-ID `energie` und Displayname `Energie` eintragen. Die ID ist nach dem
   Anlegen nicht mehr über die Oberfläche veränderbar.
3. App aktiv lassen. Als TTL beispielsweise `900` Sekunden wählen.
4. **Layout-Verwaltung auf „Im Hub“ lassen.** Im **Seiteneditor** den Seitentitel und die Zeilen mit Bezeichnung/Wert ausfüllen.
   Beispielsweise `Energie heute`, `PV` / `5.8 kW`, `Akku` / `84 %`.
   „Zeile hinzufügen“ ergänzt bis zu drei Zeilen; „Seite hinzufügen“ bis zu sechs Seiten.
   Über die Seitenauswahl wechseln, mit „Seite nach vorne“/„Seite nach hinten“ umsortieren.
   Die Vorschau reagiert sofort. Zeichenzähler zeigen die jeweiligen Grenzen.

Unter **„Erweitert: JSON bearbeiten“** kannst du weiterhin das komplette Seitenarray
importieren oder kopieren. Nach einer Änderung „JSON übernehmen“ oder
„JSON-Änderungen verwerfen“ wählen; erst danach lässt sich die App speichern.
Ungültiges JSON ersetzt die Formularwerte nicht. Auch nicht sichtbare Seiten werden
vor dem Speichern geprüft. Die letzte Seite und die letzte Zeile bleiben erhalten.

5. Speichern. Den einmalig angezeigten **Publisher-Schlüssel** sichern.
6. „Vorschau“ anklicken; mit „Nächste Seite“ durch weitere angelegte Seiten blättern.

Es sind Beispielwerte, keine Verbindung zu deiner PV-Anlage. Eine App ist hier
ein Datensatz mit Anzeigeseiten. Erst ein eigener Publisher liefert echte Werte.
**Pro Zeile kannst du auch „Datenfeld“ wählen und frei benannte Werte verknüpfen;
siehe [universelle Datenfelder](data-fields.md).**
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
2. Im Seiteneditor beispielsweise den PV-Wert ändern und speichern.
3. Auf dem Badger B drücken oder den nächsten automatischen Abruf abwarten.

Bei einem Versionskonflikt wurden zwischenzeitlich Daten geändert. Eigene Eingaben
bei Bedarf separat sichern, die Liste neu laden und den aktuellen Stand prüfen,
bevor du erneut bearbeitest. Nicht blind über neuere Werte speichern.

## 5. Universelle dynamische Daten liefern

**Empfohlen: Layout im Hub, nur Daten im Publisher.** Der [Datenfelder-Leitfaden](data-fields.md)
zeigt die Einrichtung ohne Home Assistant oder andere Pflichtsysteme, ein generisches
JSON-Beispiel und die Migration vorhandener Apps.

1. In der App **Layout-Verwaltung → Im Hub** wählen.
2. Pro dynamischer Zeile **Inhalt → Datenfeld**, Feldname, Einheit und
   Nachkommastellen setzen. Feste Texte können daneben stehen.
3. Publisher: `GET app-status`, dann `POST publish-values` mit
   `{"version":DATA_VERSION,"values":{"temperature":21.5}}`.
4. `data_version` aus der Statusantwort verwenden, nicht die Layout-`version`.
5. Im Hub Liste neu laden; auf dem Badger B drücken oder Abruf abwarten.

`examples/publish-values.php` nimmt ein JSON-Objekt von stdin entgegen und läuft
**einmal**. `examples/values.json` enthält generische Testdaten, `examples/layout.json`
das passende Layout. Für regelmäßige Updates startet ein Scheduler oder eine
vorhandene Automatisierung den Publisher. Der Hub startet keinen Hintergrundprozess.
PHP ist als Publisher-Sprache optional; jedes System mit HTTPS/JSON kann senden.

Bestehende Apps in **Im Publisher – komplette Seiten** behalten ihre bisherige API:
`GET app-status` liefert `version`, `POST publish` erhält `{version,screens}`.
`examples/publish.php` sendet einmal feste Demo-Seiten. Nur in dieser Betriebsart
kann der Publisher Editoränderungen überschreiben. Die neue Werte-API kann das nicht.

Der JSON-Editor enthält das Layout-Seitenarray; eine Werteveröffentlichung enthält
dagegen das Objekt mit `version` und `values`. Nicht miteinander verwechseln.
Für beide APIs gilt: bei HTTP 409 neu lesen und entscheiden; nach Timeout kann
bereits gespeichert worden sein. Keine blinden oder unbegrenzten Wiederholungen.
Eine erfolgreiche Veröffentlichung bestätigt noch keine Anzeige auf dem Badger.

[API-Vertrag](api.md) · [Optionale Beispiele: Bitaxe und Home Assistant](home-assistant-bitaxe.md)

## 6. Intervalle und Aktualität verstehen

| Begriff | Bedeutung |
|---|---|
| Publisher-Intervall | Häufigkeit neuer Quelldaten im Hub; außerhalb des Hubs eingestellt |
| Geräteintervall | Häufigkeit des Manifest-Abrufs; pro Gerät eingestellt |
| TTL | Alter ab Datenempfang für gebundene Apps, sonst ab Seitenspeicherung; pro App eingestellt |
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
- Home-Assistant-/Bitaxe-Beispiel als YAML-Paket; kein eingebauter Quellenabruf im Hub.
- USB-Dauerbetrieb; Batterieschlaf noch nicht implementiert.
- Hardware-Abnahme offen, siehe [Prüfliste](verification.md).

Zurück: [README](../README.md) · [Serverinstallation](installation-server.md) · [Badgerinstallation](installation-badger.md).

**Layout und Daten sind im Hub-Modus getrennt.** Editoränderungen bleiben bei neuen
Messwerten erhalten und machen alte Messwerte nicht wieder frisch.
