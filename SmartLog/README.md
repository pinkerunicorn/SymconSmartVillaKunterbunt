# Smart Log

Zentrales Logging-Modul für IP-Symcon, das Logmeldungen verschiedener Module in einem JSON-Ringbuffer sammelt und diese in einer interaktiven, filterbaren Kachelansicht (Tile View) visualisiert.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Zentralisiertes Logging für andere IP-Symcon Module.
* Interaktive, filterbare Darstellung direkt im Webfront über die IP-Symcon Tile View (Kachelansicht).
* Unterstützung für verschiedene Log-Level (DEBUG, INFO, WARNING, ERROR) mit entsprechenden Icons und Farben.
* JSON-Ringbuffer-Speicherung zur Begrenzung der maximalen Log-Einträge.
* Optionales automatisches Spiegeln von Logs in das reguläre IP-Symcon Syslog.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0

### 3. Installation

* Über den Module Store das Modul `Smart Log` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartTools`

### 4. Konfiguration

* **MaxEntries:** Die maximale Anzahl an Log-Einträgen, die im Ringbuffer vorgehalten werden sollen (Minimum 10, Maximum 1000).
* **AutoRefreshSekunden:** Das Intervall für die automatische Aktualisierung der Visualisierung in Sekunden (0 = aus).
* **MirrorToSyslog:** Wenn aktiviert, werden alle eingehenden Logmeldungen zusätzlich in das IP-Symcon Syslog (`IPS_LogMessage`) weitergeleitet.

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| EntryCount | Log-Einträge | Integer | Die aktuelle Anzahl an gespeicherten Log-Einträgen |
| LastEntry | Letzter Eintrag | String | Der Inhalt der zuletzt empfangenen Logmeldung |

### 6. PHP-Befehlsreferenz

```php
SLOG_Log(int $InstanceID, string $level, string $source, string $message, string $details = '');
```
Zentrale Log-Methode. Erwartet das Level (DEBUG, INFO, WARNING, ERROR), den Namen des aufrufenden Moduls, eine Kurzmeldung und optionale Details.

```php
SLOG_Debug(int $InstanceID, string $source, string $message, string $details = '');
```
Hilfsfunktion zum Schreiben einer Nachricht mit dem Level `DEBUG`.

```php
SLOG_Info(int $InstanceID, string $source, string $message, string $details = '');
```
Hilfsfunktion zum Schreiben einer Nachricht mit dem Level `INFO`.

```php
SLOG_Warning(int $InstanceID, string $source, string $message, string $details = '');
```
Hilfsfunktion zum Schreiben einer Nachricht mit dem Level `WARNING`.

```php
SLOG_Error(int $InstanceID, string $source, string $message, string $details = '');
```
Hilfsfunktion zum Schreiben einer Nachricht mit dem Level `ERROR`.

```php
SLOG_ClearLog(int $InstanceID);
```
Löscht alle aktuell gespeicherten Log-Einträge aus dem Speicher und aktualisiert die Visualisierung.

```php
SLOG_FindInstance(int $InstanceID);
```
Gibt die Instance-ID des ersten verfügbaren SmartLog Moduls zurück. Nützlich zur Auto-Discovery.

```php
SLOG_AktualisierenVisualisierung(int $InstanceID);
```
Aktualisiert manuell die Tile View / Kachelansicht für das Modul.
