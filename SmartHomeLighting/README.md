# SmartHomeLighting

Das SmartHomeLighting Modul steuert nicht primär alltägliche Lichtregeln (dafür ist SmartActiveLighting zuständig), sondern fokussiert sich auf eine KI-gestützte, realistische Anwesenheitssimulation bei Abwesenheit oder Urlaub.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* KI-gestützte Anwesenheitssimulation: Generierung eines dynamischen Schaltplans über Google Gemini (via SmartGeminiIO Modul) basierend auf dem historischen Schaltverhalten der letzten 14 Tage.
* Berücksichtigung der aktuellen Sonnenuntergangszeit für logische, jahreszeitlich passende Schaltvorgänge.
* Vollständiges Tracking aller im Modul hinterlegten Lampen (Schalter und Dimmer).
* Alarm-Indikator, wenn beim Verlassen des Hauses noch manuelle Lichter eingeschaltet sind.
* Konfigurierbares Verhalten bei der Heimkehr ("Bei Rückkehr anlassen" für bestimmte Lichter wie Flur/Haustür).
* Bereitstellung von Text-Variablen für externe Displays (z.B. Vestaboard) mit der Anzahl brennender Lampen.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* SmartGeminiIO Modul (zur Kommunikation mit der Google Gemini API)

### 3. Installation

* Über den Module Store das Modul `SmartHomeLighting` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartVillaKunterbunt`

### 4. Konfiguration

Die Modulkonfiguration enthält folgende Parameter:

* **SunsetVariableID**: ID der Variable, die den Zeitpunkt des Sonnenuntergangs (als Unix Timestamp oder `H:i` String) liefert.
* **ArchiveControlID**: ID des Archive Controls von IP-Symcon. Wird benötigt, um die historischen Schaltdaten für die KI auszulesen.
* **LightVariables**: Liste der zu simulierenden/trackenden Schalter (Ein/Aus). Den Variablen kann ein beschreibender Name (Kontext für die KI) mitgegeben werden. Zudem gibt es hier den Schalter "Bei Rückkehr anlassen".
* **DimmerVariables**: Analog zu den Schaltern, aber für dimmbare Lampen (0-100%).

### 5. Statusvariablen und Profile

Das Modul generiert die folgenden Variablen zur Visualisierung und Überwachung:

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| LightScheduleStatus | ℹ Aktueller KI-Schaltplan | String | Zeigt die geplanten bzw. verbleibenden Schaltvorgänge für den heutigen Abend als Textliste. |
| GeminiError | Fehler aufgetreten | Boolean | Indikator, wenn die Generierung über die Gemini API mehrfach fehlgeschlagen ist. |
| ActiveLightsCount | 💡 Aktive Lampen (Zähler) | Integer | Anzahl der aktuell im ganzen Haus brennenden Lampen. |
| ActiveLightsList | 📝 Aktive Lampen (Namen) | String | Kommagetrennte Liste der Namen aller aktiven Lampen. |
| AlarmLightsOnDuringAbsence | Alarm: Licht brennt bei Abwesenheit | Boolean | Wird ausgelöst, wenn beim Verlassen noch Licht brannte. Kann manuell quittiert werden. |
| VestaboardMessage | Vestaboard Nachricht | String | Kürzel für Displays (z. B. "3 Lampen an"). |

### 6. PHP-Befehlsreferenz

```php
SHL_SetHouseMode(int $InstanceID, int $mode, bool $isAbsence = false, bool $isSleep = false);
```
Wird üblicherweise zentral durch das `SmartHomeControl` Modul bei Modus-Wechseln aufgerufen. Aktiviert bei Abwesenheit die Präsenzsimulation (erstellt den Plan für den Abend) und schaltet bei Heimkehr oder Schlafmodus alle (oder bestimmte) Lampen aus.

```php
SHL_GenerateAiSchedule(int $InstanceID, bool $isRetry = false);
```
Stößt die asynchrone Generierung eines neuen KI-Schaltplans an. Wird automatisch bei Abwesenheit um 12:00 Uhr mittags oder beim Wechsel in die Abwesenheit aufgerufen.

```php
SHL_CheckAndExecuteLightSchedule(int $InstanceID);
```
Interne Funktion: Wird vom minütlichen Timer aufgerufen, solange die Simulation läuft. Prüft, ob laut Plan ein Licht geschaltet werden muss, und führt den Befehl aus.

```php
SHL_ProcessGeminiResult(int $InstanceID, string $scheduleJson);
```
Interne Callback-Funktion: Wird asynchron vom `SmartGeminiIO` Modul mit dem JSON-Ergebnis aufgerufen, um den neuen Schaltplan abzuspeichern.
