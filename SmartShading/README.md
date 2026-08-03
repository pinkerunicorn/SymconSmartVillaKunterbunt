# SmartHomeShading

Das SmartHomeShading Modul steuert Rollläden und Jalousien vollautomatisch basierend auf dem aktuellen Sonnenstand (Azimut), der Temperatur, der Helligkeit sowie dem Status der Fenster (Aussperrschutz).

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* **Hitzebeschattung:** Rollläden fahren automatisch in eine Schatten-Position, wenn die Außentemperatur und Helligkeit bestimmte Schwellenwerte überschreiten und die Sonne im jeweiligen Fenster-Sektor steht.
* **Sonnenstands-Berechnung:** Für jedes Fenster lässt sich der relevante Sonnen-Sektor (Azimut Von / Bis) individuell konfigurieren.
* **Tag-/Nacht-Modus:** Automatisches Schließen nach Sonnenuntergang und Öffnen nach Sonnenaufgang.
* **Lüftungs- und Aussperrschutz:** Gekoppelt mit Fenster-/Türkontakten. Steht ein Fenster offen, wird eine spezielle "Lüften"-Position angefahren, sodass man sich beispielsweise auf der Terrasse nicht versehentlich aussperrt.
* **Sturmschutz:** Erkennt starken Wind (konfigurierbarer Schwellenwert) und unterbricht das normale Fahrverhalten, um Beschädigungen zu vermeiden (Sturm-Warnung).
* Die Evaluierung erfolgt alle 3 Minuten sowie sofort, sobald sich der Zustand eines verknüpften Fensterkontakts ändert.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* Sensoren für Außentemperatur (°C) und Helligkeit (Lux)
* (Optional) Windsensor (km/h) für den Sturmschutz
* Location Control / Astro-Modul für Azimut und Sonnenauf-/-untergang

### 3. Installation

* Über den Module Store das Modul `SmartHomeShading` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartVillaKunterbunt`

### 4. Konfiguration

Die Modulkonfiguration enthält folgende Einstellungsmöglichkeiten:

* **Globale Sensorik:** Zuweisung der Variablen für Azimut, Helligkeit, Außentemperatur, Wind und Sonnenzeiten (Auf-/Untergang).
* **Schwellenwerte:** Festlegen von `BrightnessThreshold` (z. B. 40.000 Lux), `TempThreshold` (z. B. 24,0 °C) und `WindThreshold` (z. B. 50,0 km/h).
* **BlindVariables (Rollläden):** Pro Rollladen-Aktor kann konfiguriert werden:
    * Fensterkontakt (falls vorhanden)
    * Sonne Azimut Von / Bis
    * Werte für die Fahrpositionen: Auf-Pos, Zu-Pos, Schatten-Pos, Tür/Fenster Offen Position (Lüften)

### 5. Statusvariablen und Profile

Das Modul stellt folgende Variablen zur Überwachung zur Verfügung:

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| AlarmWindWarning | Alarm: Sturmschutz aktiv | Boolean | Wird ausgelöst, sobald der Wind-Schwellenwert erreicht wird. Kann manuell quittiert werden. |
| ActiveShadingCount | Schatten aktiv (Anzahl) | Integer | Wie viele Rollläden befinden sich gerade im Zustand "Beschattung"? |
| StatusIsNight | Status: Es ist Nacht | Boolean | Aktueller Tag-/Nacht-Zustand laut Sensorik. |
| StatusIsHotAndBright | Status: Hitze & Helligkeit erreicht | Boolean | Sind die globalen Bedingungen für eine Beschattung aktuell erfüllt? |
| StatusSunInSectorCount | Status: Rollläden in der Sonne (Anzahl) | Integer | Anzahl der Fenster, die aktuell direkte Sonneneinstrahlung haben. |
| StatusLastEvaluation | Status: Letzte Berechnung | Integer | Unix Timestamp des letzten Berechnungsdurchlaufs. |

### 6. PHP-Befehlsreferenz

```php
SHSH_EvaluateConditions(int $InstanceID);
```
Interne Hauptfunktion des Moduls. Berechnet den Soll-Zustand für sämtliche konfigurierte Rollläden basierend auf den aktuellen Werten und fährt diesen an, falls sich der Zustand im Vergleich zur letzten Prüfung geändert hat. Wird periodisch vom Timer (3 Min) und eventbasiert (Fensterkontakt ändert sich) aufgerufen.
