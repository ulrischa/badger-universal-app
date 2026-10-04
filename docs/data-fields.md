# Universelle Apps: Layout und Daten getrennt

Der Hub enthält keine fest eingebauten Bitaxe-, Home-Assistant- oder anderen
Fachanwendungen. Eine App besteht aus einem Namen, Seiten und beliebigen benannten
Datenfeldern. Jede Quelle, die einen HTTPS-Request senden kann, kann Daten liefern.
Home Assistant ist optional. Auf dem PHP-Webhoster läuft kein Hintergrundprozess.

## 1. App und Layout anlegen

1. **App registrieren**, eigene ID und Namen setzen.
2. **Layout-Verwaltung → Im Hub – Publisher liefert nur Daten** wählen
   (Standard für neue Apps in der Oberfläche).
3. Seiten und Zeilen im Editor gestalten. Pro Zeile auswählen:
   - **Fester Text:** manuell gepflegter Wert, etwa Raumname oder Hinweis.
   - **Datenfeld:** frei benannter Schlüssel, etwa `temperature`, `stock` oder `status`.
4. Für Datenfelder Einheit und 0–3 Nachkommastellen festlegen. Feldnamen beginnen
   mit einem Kleinbuchstaben, gefolgt von Kleinbuchstaben, Ziffern oder Unterstrichen;
   maximal 32 Zeichen. Die Namen sind groß-/kleinschreibungssensitiv.
5. Speichern, Publisher-Schlüssel sichern und App einem Gerät zuweisen.

Beispiel für eine Datenfeld-Zeile:

| Einstellung | Wert |
|---|---|
| Bezeichnung | `Temperatur` |
| Inhalt | Datenfeld |
| Datenfeld | `temperature` |
| Einheit | `deg C` |
| Nachkommastellen | `1` |

Die Datei [layout.json](../examples/layout.json) lässt sich unter **Erweitert: JSON
bearbeiten** einfügen, übernehmen und speichern. Sie mischt zwei Datenfelder mit
einer statischen Zeile. Es gibt keine ausführbaren Templates, Formeln oder URL-Abrufe.
Ein Feld kann auf mehreren Seiten mit unterschiedlichen Beschriftungen erscheinen.

## 2. Werte senden – unabhängig von der Quelle

Der Publisher benötigt nur die Hub-Adresse und den Schlüssel seiner App:

1. `GET /api.php?r=app-status` mit `Authorization: Bearer APP_TOKEN`.
2. Prüfen, dass `publish_mode` gleich `values` ist; `data_version` übernehmen.
3. `POST /api.php?r=publish-values` mit demselben Header und
   `Content-Type: application/json`:

```json
{
  "version": 0,
  "values": {"temperature": 21.5, "status": "OK"}
}
```

`0` ist nur die erste Datenversion einer neu angelegten App. Immer den tatsächlich
zurückgegebenen Wert verwenden. Die Antwort lautet z. B. `{"data_version":1}`.
Seitentitel, Beschriftungen, Einheiten und Reihenfolge bleiben unverändert.

Der vollständige Vertrag steht in [api.md](api.md). Ein App-Schlüssel kann weder
Layouts im Hub-Modus noch andere Apps oder Geräte verändern.

## 3. Generisches PHP-Beispiel ausprobieren

[examples/publish-values.php](../examples/publish-values.php) liest **ein JSON-Objekt
von stdin**, fragt die Datenversion ab, sendet einen Snapshot und beendet sich.
Benötigt PHP CLI mit cURL auf dem ausführenden Rechner; dieser muss nicht der
Webhoster sein. `publisher-client.php` muss daneben liegen.

```bash
export BADGER_URL='https://badge.example.com'
read -rsp 'Publisher-Schlüssel: ' BADGER_APP_TOKEN
printf '\n'
export BADGER_APP_TOKEN
php examples/publish-values.php < examples/values.json
unset BADGER_APP_TOKEN BADGER_URL
```

Ergebnis: `Published data version ...`. Im Hub **Liste neu laden** und Vorschau
öffnen; auf dem Badger B drücken. Layoutänderungen können gleichzeitig erfolgen.
Für echte Daten erzeugt deine Quelle das JSON, beispielsweise eine private Datei
oder die Ausgabe eines eigenen Skripts. Keine Tokens in Dateien im Webverzeichnis
oder in öffentlich sichtbare Befehlszeilen schreiben.

Für regelmäßige Updates einen Scheduler, vorhandene Automatisierung oder einen
Ereignisauslöser verwenden. Ein Lauf pro App zur selben Zeit, Zeitlimits für die
Quelle und kein unbegrenztes Wiederholen. Das Beispiel selbst plant keine Aufrufe.
Auch ein JavaScript-Dienst, Node-RED oder ein anderes System kann die beiden
HTTP-Aufrufe direkt verwenden; PHP CLI ist für Publisher nicht vorgeschrieben.

## 4. Anzeige- und Fehlerregeln

- Bis zu **32 Felder je vollständigem Snapshot**. Nicht verwendete Felder sind
  zulässig, damit du das Layout später erweitern kannst.
- Werte sind Zahlen (endlich, Betrag höchstens `1e12`), druckbarer ASCII-Text
  (1–22 Zeichen) oder `null`. Keine verschachtelten Objekte, Arrays oder Booleans;
  einen Zustand beispielsweise als `"ON"`/`"OFF"` senden.
- Zahlen erhalten die konfigurierte Zahl von Nachkommastellen. Text bleibt Text.
  Numerische Strings wie `"21.5"` werden nicht als Zahl formatiert.
- Die Einheit wird mit einem Leerzeichen angehängt, ohne Einheitenumrechnung.
  Werte müssen also bereits zur gewählten Einheit passen.
- **Fehlend oder `null` → `--`**, ohne Einheit. Ein neuer Snapshot ersetzt alle
  bisherigen Datenfelder. Weggelassene Werte bleiben nicht versehentlich bestehen.
  Ein leeres Objekt `{}` entfernt alle Werte.
- **Mehr als 22 Zeichen nach Formatierung → `OVERFLOW`**. Keine stille Kürzung.
- Die Vorschau verwendet den zuletzt beim Öffnen geladenen Datenstand. Für neue
  Daten schließen, Liste neu laden und erneut öffnen. Die endgültige Formatierung
  erfolgt in PHP; die Browser-Vorschau ist eine Näherung.
- Vor den ersten Daten zeigt eine gebundene App Platzhalter und gilt als veraltet.
  Layoutänderungen ändern den Datenzeitstempel nicht. Reine Textseiten verwenden
  dagegen die Zeit des letzten manuellen Speicherns.

TTL misst für gebundene Apps den Empfang des gesamten Datensnapshots, nicht die
Messzeit jedes einzelnen Feldes. Nur zusammengehörige, aktuelle Werte gemeinsam
senden; bei unterschiedlich aktualisierten Quellen getrennte Apps verwenden.
Optional ein Feld wie `measured_at` als kurzen Text anzeigen. Bei Quellenausfall
keine alten Messungen immer wieder als frisch senden.

## 5. Versionskonflikte und Umstellung bestehender Apps

Layout und Daten haben getrennte Versionsnummern. Ein Datenupdate unterbricht
keine laufende Layoutbearbeitung. Zwei gleichzeitige Publisher derselben Datenversion
können dagegen nicht beide schreiben: einer erhält HTTP 409. Nach einem Timeout ist
unklar, ob gespeichert wurde; beim nächsten geplanten Lauf die Version neu lesen.

Bestehende Apps bleiben nach dem Update in **Im Publisher – komplette Seiten**.
Der bisherige Endpunkt `publish` und `examples/publish.php` funktionieren weiter.
So stellst du eine App bewusst um:

1. Alten Publisher pausieren und das Seiten-JSON sichern.
2. App bearbeiten, Layout-Verwaltung auf **Im Hub** umstellen.
3. Gewünschte Zeilen in Datenfelder umwandeln, Einheiten konfigurieren, speichern.
4. Publisher auf `publish-values`, `values` und `data_version` umstellen.
5. Einmal ausführen, Anzeige prüfen und Zeitplan wieder aktivieren.

Ein Betriebsartwechsel löscht gespeicherte Datenwerte und erhöht die Datenversion.
Schlüssel und Gerätezuweisungen bleiben erhalten. Der falsche Publisher-Endpunkt
liefert HTTP 409 und kann das Layout nicht überschreiben. Für den Rückwechsel
Datenfeld-Zeilen zuerst in feste Texte umwandeln.

[Server-Update und Migration](installation-server.md#update-auf-layoutdaten-trennung) ·
[Optionale HA-/Bitaxe-Beispiele](home-assistant-bitaxe.md) · [Nutzung](usage.md)
