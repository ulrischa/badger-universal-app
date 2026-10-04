# Server installieren

Diese Anleitung richtet einen einzelnen Badger Hub unter einer eigenen HTTPS-Domain
ein, zum Beispiel `https://badge.example.com`. Ersetze Domain, Dateipfade,
PHP-Version und Benutzer durch die Werte deines Servers.

## 1. Voraussetzungen prüfen

- PHP ab 8.2 mit PDO SQLite, Sessions und JSON; eine aktuell gepflegte PHP-Version verwenden.
- HTTPS-Zertifikat, dessen Vertrauenskette auch der Badger prüfen kann.
- Lokaler, beschreibbarer Datenspeicher. SQLite und Sessions nicht auf NFS ablegen.
- Möglichkeit, das öffentliche Webverzeichnis auf `public` zu setzen.
- PHP-Kommandozeile für Einrichtung und Wartung; alternativ lokale Vorbereitung
  für Webspace ohne SSH, siehe unten.
- `ext-curl` nur dort, wo du das mitgelieferte PHP-Publisher-Beispiel ausführst.

```bash
php -v
php -r 'echo "PDO SQLite: ", extension_loaded("pdo_sqlite") ? "OK" : "FEHLT", PHP_EOL; echo "Sessions: ", extension_loaded("session") ? "OK" : "FEHLT", PHP_EOL;'
```

Prüfe die Erweiterungen zusätzlich für die PHP-Version des Webservers: CLI und
PHP-FPM können unterschiedliche Installationen verwenden. Kein Composer, Node,
npm oder Python wird für den Webservice benötigt.

## 2. Dateien und Domain einrichten

Repository klonen oder das GitHub-ZIP entpacken, beispielsweise nach
`/srv/badger-hub`. Die Struktur muss erhalten bleiben:

| Verzeichnis | Zweck | Öffentlich erreichbar? |
|---|---|---|
| `public` | Oberfläche und API | Ja, als einziges Webverzeichnis |
| `src` | PHP-Anwendungslogik | Nein |
| `var` | Konfiguration, Datenbank und Sessions | Nein |
| `bin` | Einrichtungs- und Wartungsbefehle | Nein |
| `device` | Dateien für den Badger | Nein |

Setze den Document Root der Domain auf `/srv/badger-hub/public`. Eine eigene
Subdomain genügt; Installation unter einem Unterpfad wie `/badger/` wird derzeit
nicht unterstützt. Der PHP-Prozess benötigt Schreibrechte nur auf `var`.

**Nicht einfach den kompletten Projektordner in einen öffentlich erreichbaren
Ordner hochladen.** Eine `.htaccess` ersetzt die Trennung der privaten Dateien nicht.

## 3. Administrator und Datenbank anlegen

Führe die Einrichtung als Benutzer des PHP-Prozesses aus oder übertrage danach die
Eigentümerschaft von `var` an diesen Benutzer. Auf verwaltetem Webspace ist das
häufig bereits dein eigener Hosting-Benutzer.

Bash-Beispiel für verdeckte Passworteingabe:

```bash
cd /srv/badger-hub
read -rsp 'Neues Administrator-Passwort: ' badger_password
printf '\n'
printf '%s\n' "$badger_password" | php bin/setup.php https://badge.example.com
unset badger_password
```

16–72 Bytes verwenden. Die Domain ohne abschließenden Slash eingeben. Das Passwort
erscheint so weder als Argument des PHP-Prozesses noch als Klartext im Shell-Verlauf.

Die Einrichtung erzeugt `var/config.json`, `var/hub.sqlite` und `var/sessions`.
Die Konfiguration enthält einen Passwort-Hash. Ein erneuter Setup-Aufruf
überschreibt keine bestehende Installation. Private Verzeichnisse benötigen 0700,
private Dateien 0600 und den passenden Eigentümer. Bestehende WAL-/SHM-Dateien
gehören ebenfalls zum SQLite-Datenverzeichnis.

Optional kann `BADGER_CONFIG` auf einen anderen privaten absoluten Dateipfad zeigen.
Setze diese Variable dann konsistent für CLI, Cron und PHP-FPM. Die Datenbank und
Sessions werden neben dieser Konfigurationsdatei eingerichtet.

## 4. Webserver konfigurieren

### Nginx mit PHP-FPM

Passe [nginx.conf.example](nginx.conf.example) an:

1. Domain und Zertifikatspfade ersetzen.
2. Document Root auf dein `public`-Verzeichnis setzen.
3. PHP-FPM-Socket auf die installierte PHP-Version ändern.
4. Konfiguration mit `nginx -t` prüfen und erst danach neu laden.

Das Beispiel setzt HTTPS für PHP am vertrauenswürdigen TLS-Endpunkt. Für einen
zusätzlichen Reverse Proxy muss dessen Betreiber die vertrauenswürdige Weitergabe
konfigurieren. Beliebige vom Client gesetzte Forwarded-Header werden von der App
bewusst nicht als HTTPS-Nachweis akzeptiert.

### Apache oder Hosting-Verwaltung

Die Domain ebenfalls auf `public` zeigen lassen. Die dortige `.htaccess` enthält
Hilfen für Authorization-Weitergabe und ausgeschaltete API-Kompression. Der Host
muss die verwendeten Direktiven zulassen oder gleichwertig zentral konfigurieren.
Bei HTTP 500 nach dem Upload zuerst das Apache-Fehlerprotokoll prüfen.

### Für beide Varianten

- `Authorization` an PHP weitergeben.
- API nicht cachen, komprimieren oder durch CDN-Prüfseiten ersetzen.
- `Content-Length` und JSON-Antwort unverändert ausliefern; keine Chunked-Antwort
  an den HTTP/1.0-Geräteclient erzwingen.
- PHP: `display_errors=Off`, `log_errors=On`, `zlib.output_compression=Off`,
  `max_execution_time=10`, `memory_limit=128M`.
- PHP-FPM: `request_terminate_timeout=15s`.
- Request-Body auf 16 KiB begrenzen, Lesezeitlimits und Rate-Limits am Webserver
  konfigurieren. Besonders die unauthentifizierte Sitzungserzeugung begrenzen.
- Prüfen, dass `/var/config.json` und `/src/core.php` über die Domain nicht abrufbar sind.

## 5. Funktionsprüfung

1. HTTPS-Domain öffnen. Die Anmeldemaske muss erscheinen.
2. Mit dem eingerichteten Passwort anmelden.
3. Eine Test-App anlegen. Schlüssel einmalig sichern.
4. Ein Gerät anlegen, die Test-App zuweisen, Geräteschlüssel sichern.
5. Abmelden und erneut anmelden.

`GET /api.php?r=manifest` ohne Geräteschlüssel muss HTTP 401 liefern. Eine leere
Anzeigeliste nach gültiger Geräteanmeldung ist zulässig, solange keine aktive App
zugewiesen wurde. Die Schritte zur Anzeige stehen in [Nutzung](usage.md).

## Webspace ohne SSH: sichere Vorbereitung per SFTP/FTPS

1. Projekt auf einem Rechner mit PHP und PDO SQLite entpacken.
2. Setup dort **mit der endgültigen HTTPS-Domain** ausführen, nicht mit `--dev`.
3. Die CLI beendet sich vollständig; keinen lokalen Server mit dieser Installation starten.
4. In `var/config.json` den Wert `db` auf den absoluten SQLite-Pfad des Webspace
   anpassen. Diesen Pfad beim Anbieter ermitteln, nicht erraten.
5. Projekt einschließlich der erzeugten privaten Daten über SFTP oder FTPS
   übertragen. `var/sessions` muss auch als leeres Verzeichnis angelegt werden.
6. Domain auf `public` setzen und Eigentümer/Rechte kontrollieren.
7. Private lokale Konfigurationskopien sicher verwahren oder entfernen.

Wenn der Anbieter weder private Dateien außerhalb des Webverzeichnisses noch einen
passenden Document Root erlaubt, diese Installation dort nicht öffentlich betreiben.
Einrichtung und Wartung nicht durch öffentlich erreichbare PHP-Hilfsskripte ersetzen.

## Wartung, Sicherung und Aktualisierung

Stündlich als PHP-Benutzer ausführen, zum Beispiel über den Hosting-Cronplaner:

```bash
php /srv/badger-hub/bin/maintenance.php cleanup
```

Konsistente Sicherung in ein privates Verzeichnis erstellen:

```bash
php /srv/badger-hub/bin/maintenance.php backup /private/backups/badger.sqlite
```

Der Zielname darf noch nicht existieren. Zusätzlich `config.json` sichern.
Nicht nur eine laufend verwendete SQLite-Datei kopieren: WAL-Schreibvorgänge können
noch in Begleitdateien liegen. Die Wiederherstellung ist in der
[README](../README.md#maintenance) beschrieben.

Ein neues Passwort wie bei Setup verdeckt einlesen und an
`php bin/maintenance.php password` übergeben. Bestehende Admin-Sitzungen werden
ungültig; Geräte- und Publisher-Schlüssel bleiben erhalten.

Vor einem Update Daten sichern, Änderungshinweise lesen und nur Programmdateien
aktualisieren. `var` und eigene Publisher-Konfigurationen erhalten. Nicht erneut
Setup ausführen. Automatisierungen und Gerät danach einmal prüfen.

Weiter: [Badger einrichten](installation-badger.md) · [Hub benutzen](usage.md).
