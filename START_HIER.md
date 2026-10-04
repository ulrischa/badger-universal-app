# Badger Hub einrichten

**Webservice und Verwaltung: PHP + JavaScript. Auf dem Badger läuft ein kleiner
MicroPython-Client**, weil die Pimoroni-Firmware PHP und Browser-JavaScript nicht
als normale Apps ausführen kann.

1. PHP-Webspace mit PDO SQLite und HTTPS verwenden. Empfohlen: PHP 8.4 oder neuer.
2. Projekt hochladen. Die Domain muss ausschließlich auf den Ordner `public`
   zeigen. `var`, `src` und `device` dürfen nicht öffentlich erreichbar sein.
3. `php bin/setup.php https://deine-domain.de` ausführen und ein neues Passwort
   über die Standardeingabe übergeben. Die README zeigt die verdeckte Eingabe.
4. Im Browser anmelden, eine App registrieren und ihren Publisher-Schlüssel sichern.
5. Ein Gerät anlegen, Apps zuweisen und den getrennten Geräteschlüssel sichern.
6. Die drei Programmdateien aus `device` auf den Badger kopieren.
   `config.example.py` als `config.py` speichern, WLAN und Geräteschlüssel eintragen.
7. Vertrauenswürdiges CA-Zertifikat und UTC-Uhrzeit wie in der README einrichten.
8. Badger per USB betreiben. A öffnet/blättert, B aktualisiert, C führt ins Menü.

**Dynamische Inhalte:** Ein PHP-Skript, Node-RED oder eine andere Datenquelle
schickt frei benannte Datenwerte per API an die registrierte App. Das Layout
bleibt im Hub. `examples/publish-values.php` zeigt einen vollständigen PHP-Aufruf. Der Webservice wartet nie auf diese Quelle.

**Bei Störungen:** Die letzte gültige Konfiguration bleibt verfügbar. Nach einem
Watchdog-Neustart pausiert der Netzwerkzugriff bis B gedrückt wird. C beim Reset
halten lässt den USB-Zugang zur Reparatur offen.

Diese erste Fassung umfasst Text-/Werteanzeigen und USB-Dauerbetrieb. Fertige
Fronius-Adapter, Steueraktionen und stromsparender Batteriebetrieb
sind nicht enthalten. Die Tests ersetzen keinen Test auf deinem echten Badger;
die Hardware-Abnahme ist in `docs/verification.md` beschrieben.

Vollständige Installation und Betrieb: [README.md](README.md).

## Ausführliche Anleitungen

- **[Server installieren](docs/installation-server.md)**
- **[Badger einrichten](docs/installation-badger.md)**
- **[Apps und Geräte benutzen, dynamische Daten liefern](docs/usage.md)**
- **[Push-/Pull-Datenfluss mit Diagrammen und Begründung](docs/data-flow.md)**

**Der Publisher liefert an den Hub; der Badger holt dessen gespeicherte Daten ab.
Das Anlegen einer App startet noch keinen Datenlieferanten. Taste B liest den Hub,
sie startet keine neue Messung an der Datenquelle.**

**[Universelle Datenfelder einrichten](docs/data-fields.md)** — ohne Pflichtintegration.

**Optionale Beispiele: [Bitaxe und Home Assistant einrichten](docs/home-assistant-bitaxe.md).**
Formular-Seiteneditor mit Vorschau und optionalem JSON. Lokales Hosting ist möglich;
kein PHP-Dauerprozess nötig. Das PHP-Beispiel läuft einmal, das HA-Paket jede Minute.

**Neue Apps:** Layout im Hub, je Zeile fester Text oder frei benanntes Datenfeld.
Einheiten und Nachkommastellen im Editor setzen. Bestehende Apps bleiben zunächst
bei der bisherigen Seiten-API; die Doku erklärt die bewusste Umstellung.
