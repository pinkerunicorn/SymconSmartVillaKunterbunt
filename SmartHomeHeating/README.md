# SmartHomeHeating

Das SmartHomeHeating Modul steuert zentral die Heizkörper im gesamten Haus basierend auf dem aktuellen Haus-Modus, inklusive Absenkautomatik bei Abwesenheit oder Urlaub.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Automatische Temperaturabsenkung bei Abwesenheit, Schlafen oder Urlaub.
* Noch tiefere Absenkung (Standard: -2 °C) während eines längeren Urlaubs.
* "Smart Return"-Funktion: Speicherung der Zieltemperaturen und Modi (Auto/Manu) vor der Absenkung und automatische Wiederherstellung bei erneuter Anwesenheit.
* Umschaltbarer Sommerbetrieb ("Heizperiode aktiv"), um ungewollte Schaltvorgänge an Thermostaten außerhalb der Heizsaison zu verhindern.
* Berechnung der durchschnittlichen Haus-Temperatur aus allen eingebundenen Thermostaten, inklusive automatischer Protokollierung (Archivierung).
* Frostschutz-Überwachung mit konfigurierbarem Schwellenwert zur Alarmauslösung.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* Unterstützte Thermostate / Heizkörperregler (z. B. Homematic IP)

### 3. Installation

* Über den Module Store das Modul `SmartHomeHeating` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartVillaKunterbunt`

### 4. Konfiguration

Die Modulkonfiguration enthält folgende Einstellungsmöglichkeiten:

* **TargetTemperature**: Globale Absenktemperatur in °C, die bei Abwesenheit angefahren wird.
* **FrostWarningThreshold**: Temperatur-Schwellenwert in °C. Fällt die berechnete Durchschnittstemperatur unter diesen Wert, wird ein Frostalarm ausgelöst.
* **HeatingInstances**: Liste der zu steuernden Thermostat-Instanzen. Hier kann für jeden Raum auch eine *individuelle* Absenktemperatur definiert werden, die die globale Einstellung überschreibt.

### 5. Statusvariablen und Profile

Folgende Variablen werden vom Modul gepflegt und bereitgestellt:

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| HeatingSeason | ❄ Heizperiode aktiv | Boolean | Manueller Schalter, um die Heizsaison ein- oder auszuschalten (verhindert Absenkbetrieb im Sommer). |
| AlarmFrostWarning | Alarm: Frostgefahr | Boolean | Alarm-Indikator. Wird automatisch aktiviert bei Untertemperatur und kann manuell quittiert werden. |
| HeatingStatus | ℹ Status | String | Lesbarer Text über den aktuellen Betriebszustand (z. B. "🌙 Abwesenheit aktiv"). |
| AverageTemperature | 🌡 Ø Haus-Temperatur | Float | Die durchschnittliche aktuelle Temperatur im gesamten Haus. Wird standardmäßig archiviert. |
| IsAbsenkbetrieb | 📉 Absenkbetrieb | Boolean | Zeigt visuell an, ob sich das Haus gerade in der Temperaturabsenkung befindet. |

### 6. PHP-Befehlsreferenz

```php
SHH_SetHouseMode(int $InstanceID, int $mode, bool $isAbsence = false, bool $isSleep = false, int $vacationEndTime = 0);
```
Wird primär durch das übergeordnete `SmartHomeControl` Modul aufgerufen. Aktiviert abhängig von den übergebenen Parametern (Modus, Abwesenheit, Schlaf, Urlaub) den Absenkbetrieb oder stellt den vorherigen Zustand aus dem Profil / Normalbetrieb wieder her.

```php
SHH_UpdateAverageTemperature(int $InstanceID);
```
Interne Funktion: Berechnet aus allen hinterlegten Thermostaten die aktuelle Durchschnittstemperatur neu, aktualisiert die Anzeige-Variable und prüft auf mögliche Frostgefahr. Wird regelmäßig automatisch getriggert, wenn die Thermostate neue Werte senden.
