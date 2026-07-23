# SmartHomeControl

Das SmartHomeControl Modul (Villa Kunterbunt Controller) dient als zentraler Orchestrator für das SmartHome. Es verwaltet den globalen Haus-Modus und synchronisiert diesen mit allen anderen relevanten SmartHome-Submodulen.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Zentrale Verwaltung von Haus-Modi (z. B. Anwesenheit, Abwesenheit, Urlaub, Schlafen) inklusive Icon- und Farbanpassungen.
* Orchestrierung und Status-Weitergabe an Submodule: Heizung, Sicherheit, Beleuchtung (Alltag & Anwesenheitssimulation), Beschattung, Bewässerung und Garagen.
* Möglichkeit zur Ausführung von Eintritts- und Austritts-Sequenzen über das SmartHomeSequencer Modul.
* Automatische Urlaubserkennung durch Synchronisation mit einem iCal-Kalender (z. B. Google Kalender).
* Bereitstellung einer Anwesenheits-Variable (Boolean) für die nahtlose Integration in Sprachassistenten wie Google Home oder Amazon Alexa.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0

### 3. Installation

* Über den Module Store das Modul `SmartHomeControl` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartVillaKunterbunt`

### 4. Konfiguration

Die Konfiguration im WebFront bzw. der Verwaltungskonsole umfasst:

* **HouseModes**: Detail-Definition der verfügbaren Modi. Hier werden ID, Name, Icon, Farbe sowie Flags (Ist Abwesenheit?, Ist Schlafen?) und das zugehörige Sequenzer-Skript konfiguriert. Zudem lässt sich pro Modus definieren, welche Submodule benachrichtigt werden sollen.
* **Submodul-Zuweisungen**: Für jedes unterstützte Gewerk (Heating, Security, Lighting, ActiveLighting, Shading, Lawn, Garage) wird die entsprechende Instanz-ID hinterlegt sowie die Steuerung global über eine Checkbox (z. B. `EnableHeating`) aktiviert. Bei Garagen können mehrere Instanzen in einer Liste hinterlegt werden.
* **CalendarURL**: URL zu einem iCal-Kalender. Findet das Modul hierin Termine mit dem Titel "URLAUB", schaltet es automatisch in den Urlaubs-Modus (und nach Ablauf wieder zurück).

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| HouseMode | 🏠 Haus Modus | Integer | Beinhaltet den aktuellen Haus-Modus. Das Profil (`SmartAbsence.HouseMode.[InstanzID]`) wird dynamisch aus der Konfiguration generiert. |
| PresenceStatus | Anwesenheit (Google Home) | Boolean | Schalter zur einfachen Steuerung der Anwesenheit über Sprachassistenten (True = Anwesenheit, False = Abwesenheit/Urlaub). |

### 6. PHP-Befehlsreferenz

```php
SHC_SetHouseMode(int $InstanceID, int $newMode, int $vacationEndTime = 0);
```
Setzt den aktuellen Haus-Modus. Die Änderung wird (sofern in der Modus-Konfiguration aktiviert) an alle angebundenen Submodule (Heizung, Licht, Beschattung etc.) weitergeleitet. Zudem werden ggf. konfigurierte Austritts- bzw. Eintrittssequenzen gestartet.

```php
SHC_CheckCalendar(int $InstanceID);
```
Ruft den konfigurierten iCal-Kalender ab und sucht nach einem Termin mit dem Titel "URLAUB". Ist ein solcher Termin aktuell aktiv, wird das Haus automatisch in den Urlaubs-Modus versetzt. Diese Funktion wird auch automatisch alle 30 Minuten durch einen internen Timer ausgeführt.
