# SmartAlarmManager

Das SmartAlarmManager Modul dient der zentralen Verwaltung, Eskalation und Signalisierung von Alarmen und Ereignissen (z. B. durch Sensoren oder andere Module) in IP-Symcon.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Überwachung beliebiger Variablen auf bestimmte Trigger-Werte.
* Mehrstufiges Eskalations-Management (Stufe 1 bis 3) mit konfigurierbaren Verzögerungszeiten.
* Auslösung von Aktionsprofilen (Optische und Akustische Signale über Homematic LEDs, MP3-Gongs und Sirenen).
* Benachrichtigungen über E-Mail (SMTP), WebFront-Push, Vestaboard und Sprachausgabe (Sonos TTS).
* Quittierungs-Funktion für einzelne Alarme oder alle aktiven Alarme gleichzeitig.
* Dynamische Generierung von Alarm-Variablen zur einfachen Visualisierung im WebFront.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0

### 3. Installation

* Über den Module Store das Modul `SmartAlarmManager` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartVillaKunterbunt`

### 4. Konfiguration

Die Konfiguration umfasst folgende Bereiche:

* **MonitoredVariables**: Die zu überwachenden Variablen mit Trigger-Wert, Alarm-Typ, Nachricht und zugewiesenem Aktions-Profil.
* **ActionProfiles**: Definition von Aktions-Profilen (Geräte zur Alarmierung: Homematic MP3, LED, Sirene, Ziel-Variable).
* **EscalationTimeLvl2**: Zeit in Sekunden, bis ein unquittierter Alarm auf Stufe 2 eskaliert (Standard: 300).
* **EscalationTimeLvl3**: Zeit in Sekunden, bis ein unquittierter Alarm auf Stufe 3 (Vollalarm) eskaliert (Standard: 900).
* **TargetWebFront**: Instanz-ID des WebFronts / der Visualisierung für Push-Nachrichten.
* **TargetSMTP**: Instanz-ID der SMTP-Instanz für E-Mail-Versand.
* **EmailAddress**: Die Empfänger-E-Mail-Adresse.
* **TargetVestaboard**: Instanz-ID des Vestaboard-Generators für Text-Nachrichten.
* **TargetSonos**: Instanz-ID eines Sonos-Systems für Sprachausgabe.

### 5. Statusvariablen und Profile

Das Modul erstellt automatisch folgende Variablen zur Statusanzeige und Steuerung:

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| SystemStatus | System Status | Integer | Zeigt den Gesamtstatus (0 = Alles OK, 1 = Info, 2 = Alarm, 3 = Eskalation, 4 = Vollalarm). Profil: `SmartAlarm.Status` |
| ActiveAlarmsCount | Aktive Alarme | Integer | Anzahl der aktuell nicht quittierten Alarme. |
| LastEvent | Letztes Ereignis | String | Text-Ausgabe des zuletzt aufgetretenen Ereignisses. |
| AcknowledgeAll | Alle Alarme quittieren | Boolean | Button zum Quittieren aller anstehenden Alarme (schaltet Sirenen etc. ab). |
| Alarm_* | Status: [Nachricht] | Boolean | Dynamisch erzeugte Schalter für jeden einzelnen ausgelösten Alarm. |

### 6. PHP-Befehlsreferenz

```php
SAM_CheckEscalation(int $InstanceID);
```
Interne Funktion: Prüft alle anstehenden Alarme auf Überschreitung der konfigurierten Eskalationszeiten (Stufe 2 / 3). Wird automatisch periodisch über einen Timer aufgerufen, wenn Alarme anstehen.

```php
SAM_HandleDelays(int $InstanceID);
```
Interne Funktion: Verarbeitet verzögerte Auslösungen von Alarmen.

```php
SAM_TestProfile(int $InstanceID, string $profileID, bool $turnOff);
```
Ermöglicht das manuelle Testen eines Aktions-Profils. Wenn `$turnOff` `true` ist, werden die entsprechenden Geräte des Profils (LEDs, Sirenen) wieder deaktiviert.
