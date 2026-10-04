# Badger 2040 W einrichten

Du benötigst einen **Badger 2040 W**, ein USB-Datenkabel, WLAN, den eingerichteten
HTTPS-Hub und dessen Geräteschlüssel. Die Version ohne W wird nicht unterstützt.
Diese Fassung ist für USB-Dauerbetrieb vorgesehen, nicht für sparsamen Batteriebetrieb.

## 1. Vorhandene Dateien sichern und Firmware prüfen

1. Badger über USB anschließen. Bestehende Dateien und insbesondere `main.py`
   mit Thonny oder `mpremote` auf dem Rechner sichern.
2. Eine passende **Badger-2040-W-Firmware** von
   [Pimoroni](https://github.com/pimoroni/badger2040/releases) verwenden.
   Eine gewöhnliche Pico-W-Firmware allein enthält das benötigte Badger-Modul nicht.
3. Falls ein Firmwarewechsel nötig ist: BOOTSEL auf dem Pico-W-Modul gedrückt
   halten und RESET drücken. Die passende UF2-Datei auf das erschienene
   Laufwerk `RPI-RP2` kopieren.
4. Eine `with-badger-os`-UF2 überschreibt auch das Dateisystem. Die reguläre
   Firmwaredatei lässt vorhandene Dateien laut Pimoroni-Anleitung bestehen.

Hardware-Version und TLS-Unterstützung müssen zusammenpassen. Die Anwendung
schaltet Zertifikatsprüfung bei Inkompatibilität nicht ab.

## 2. Geräteschlüssel im Hub erzeugen

Im Hub zuerst eine App, danach unter „Gerät hinzufügen“ beispielsweise das Gerät
`badger-flur` anlegen. Die App zuweisen und speichern. Den neu angezeigten
**Geräteschlüssel** sichern. Der Publisher-Schlüssel einer App ist hierfür falsch.

## 3. Verbindungsdaten vorbereiten

`device/config.example.py` lokal als `device/config.py` kopieren und bearbeiten:

```python
WIFI_SSID = "DEIN_WLAN"
WIFI_PASSWORD = "DEIN_WLAN_PASSWORT"
SERVER_HOST = "badge.example.com"
SERVER_PORT = 443
API_PATH = "/api.php?r=manifest"
DEVICE_TOKEN = "HIER_DEN_64_ZEICHEN_LANGEN_GERAETESCHLUESSEL_EINTRAGEN"
CA_FILE = "/ca.der"
```

`SERVER_HOST` enthält ausschließlich den Hostnamen, kein `https://` und keinen
Pfad. Der Schlüssel besteht aus 64 hexadezimalen Zeichen. Sonderzeichen in
Python-Zeichenketten korrekt maskieren. Diese Datei enthält Geheimnisse und gehört
nicht ins Repository. Die mitgelieferte `.gitignore` schließt sie aus.

## 4. Vertrauenswürdiges CA-Zertifikat bereitstellen

Der Badger benötigt einen passenden Vertrauensanker für die Zertifikatskette des
Hub-Servers, im DER-Format und höchstens 8 KiB groß.

1. Beim Serverbetreiber bzw. Zertifikatsanbieter klären, welche Root-CA zur
   tatsächlich ausgelieferten Kette gehört.
2. Root-Zertifikat aus einer vertrauenswürdigen Quelle beziehen und dessen
   Fingerabdruck unabhängig prüfen. Nicht einfach ein Zertifikat aus einer
   ungeprüften Verbindung übernehmen.
3. Ein PEM-Zertifikat bei Bedarf auf dem Rechner konvertieren:

   ```bash
   openssl x509 -in trusted-root.pem -outform DER -out ca.der
   ```

4. Die Datei später als `/ca.der` auf den Badger übertragen.

Bei eigener PKI entsprechend die eigene vertrauenswürdige CA verwenden. Eine
Zertifikatserneuerung am Server kann bei unveränderter Kette weiter funktionieren;
ein Wechsel der CA kann ein neues `ca.der` erfordern. Beides praktisch testen.

## 5. Dateien mit Thonny übertragen

1. In Thonny den MicroPython-Interpreter für Raspberry Pi Pico und den USB-Port
   des Badgers auswählen. Andere Programme mit Zugriff auf denselben Port schließen.
2. Falls der Hub-Client bereits läuft: **C gedrückt halten und RESET drücken**.
   Erst danach im USB-Interpreter arbeiten. Ein bloßes Unterbrechen beendet einen
   bereits aktivierten Hardware-Watchdog nicht zuverlässig.
3. Folgende Dateien in das **Wurzelverzeichnis des Geräts** kopieren:

   | Lokale Datei | Ziel auf dem Badger |
   |---|---|
   | `device/hub_core.py` | `/hub_core.py` |
   | `device/hub_network.py` | `/hub_network.py` |
   | Deine `device/config.py` | `/config.py` |
   | Dein CA-Zertifikat | `/ca.der` |
   | `device/main.py` | `/main.py` |

4. `main.py` zuletzt übertragen; dadurch ist die Konfiguration beim nächsten
   Neustart bereits vollständig. Die vorhandene Startanwendung wird ersetzt.

### Alternative: mpremote

Nach Installation des offiziellen MicroPython-Werkzeugs und Wechsel ins
Projektverzeichnis, mit genau einem angeschlossenen Gerät:

```bash
mpremote fs cp device/hub_core.py :hub_core.py
mpremote fs cp device/hub_network.py :hub_network.py
mpremote fs cp device/config.py :config.py
mpremote fs cp ca.der :ca.der
mpremote fs cp device/main.py :main.py
```

Auch hier vorher bei schon laufendem Hub-Client C+RESET verwenden. Bei mehreren
Geräten den Port ausdrücklich über `mpremote connect ...` auswählen. Der
[Doku zu mpremote](https://docs.micropython.org/en/latest/reference/mpremote.html)
entnimmst du Installation und Portauswahl.

## 6. Uhrzeit in UTC setzen

Die Zertifikatsprüfung braucht eine gültige Uhr. Über USB die Pico-Uhr auf die
**aktuelle UTC-Zeit** setzen, die Einstellung prüfen und anschließend in die
externe Badger-Uhr kopieren.

Im Thonny-Interpreter:

```python
import machine
import badger2040
print(machine.RTC().datetime())
```

Wenn du die Zeit manuell setzt, verwendet `RTC.datetime` die Reihenfolge
`(Jahr, Monat, Tag, Wochentag, Stunde, Minute, Sekunde, Subsekunde)`.
Beispiel **nur für das Tupelformat**, durch die aktuelle UTC-Zeit ersetzen:

```python
machine.RTC().datetime((2026, 10, 4, 6, 9, 0, 0, 0))
badger2040.pico_rtc_to_pcf()
```

Alternativ bietet `mpremote rtc --set` die Übernahme der Rechnerzeit. Die tatsächlich
übernommene UTC-Zeit prüfen und danach `pico_rtc_to_pcf()` ausführen. Nach vollständigem
Stromverlust kann eine erneute Einrichtung nötig sein. Die App liest beim Start
die externe Uhr zurück in die Pico-Uhr.

## 7. Starten und testen

1. RESET ohne gehaltene C-Taste drücken.
2. Der Client versucht nach dem Start, seine App-Liste abzurufen.
3. Mit Hoch/Runter eine App auswählen, A drücken.
4. B aktualisiert den Hub-Snapshot. C führt zurück ins Menü.
5. Einen Wert im Hub ändern und speichern, danach B am Gerät drücken.
6. WLAN testweise ausschalten: gespeicherte Daten und Menünavigation müssen
   erhalten bleiben. WLAN wieder einschalten und mit B erneut abrufen.

Erst nach der [Hardware-Abnahme](verification.md#required-on-a-real-badger-before-unattended-use)
unbeaufsichtigt einsetzen. Die Tests auf dem Entwicklungsrechner bestätigen keine
konkrete Kombination aus UF2-Firmware, CA-Zertifikat und WLAN.

## Häufige Fehler

| Anzeige oder Symptom | Prüfen / Abhilfe |
|---|---|
| `No apps assigned` | App aktiv? Dem richtigen Gerät zugewiesen? Danach B drücken. |
| `OFFLINE - B retry` | WLAN, Hostname, CA, UTC-Uhr, Geräte- statt Publisher-Schlüssel und HTTPS-Erreichbarkeit prüfen. |
| `RECOVERY - B retry` | Vorheriger Watchdog-Neustart; Ursache prüfen, dann mit B ausdrücklich neu versuchen. |
| `PAUSED - B retry` | Fünf Abrufversuche fehlgeschlagen; automatische Versuche pausieren. |
| `CANCELLED - B retry` | Abruf mit C abgebrochen; B startet wieder. |
| `OLD DATA` | Hub erreichbar, aber Inhalte älter als TTL; Publisher prüfen. |
| `ONLINE - cache not saved` | Gerätespeicher prüfen; aktuelle Daten liegen im RAM, überleben den Neustart aber möglicherweise nicht. |
| Alter Bildschirm bei C+RESET | Normal: E-Ink behält das Bild; Wartungsmodus meldet sich über USB. |
| Keine USB-Verbindung | Datenfähiges Kabel, Portauswahl und andere Programme prüfen; C+RESET verwenden. |
| TLS bleibt trotz korrekter Daten erfolglos | Kompatibilität der Firmware prüfen; keine ungesicherte TLS-Umgehung verwenden. |

Bei Schlüsselrotation `config.py` über USB anpassen. Vor Außerbetriebnahme
`config.py`, beide `cache.a.json`/`cache.b.json` und gegebenenfalls `cache.tmp`
entfernen sowie den Schlüssel im Hub widerrufen. Ein offline liegendes Gerät
kann nicht aus der Ferne gelöscht werden.

Weiter: [Nutzung](usage.md) · [Datenfluss](data-flow.md).
