# SmartHomeGarage

Das SmartHomeGarage Modul ermöglicht die intelligente Steuerung und Überwachung eines Garagentors inklusive Taster-Integration, Status-LED-Rückmeldung und Automatik-Funktionen.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Steuerung eines Garagentors über einen kurzen Motor-Relais-Impuls (1 Sekunde).
* Präzise Statusermittlung (Zu, Auf, Fährt Auf, Fährt Zu, Teiloffen) über zwei Endschalter.
* Integration beliebiger Taster (z.B. Wand- oder Funktaster) zum Starten/Stoppen der Torfahrt.
* Visuelle Statusrückmeldung über Homematic LEDs (Farb- und Blinkmuster je nach Torzustand).
* Alarm-Timer: Löst einen Alarm aus, wenn das Tor länger als eine konfigurierte Zeitspanne offen steht.
* Automatisches Schließen des Tors bei aktivierter Abwesenheit im SmartHome.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0

### 3. Installation

* Über den Module Store das Modul `SmartHomeGarage` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartVillaKunterbunt`

### 4. Konfiguration

Die Modulkonfiguration enthält folgende Parameter:

* **CloseOnAbsence**: Wenn aktiviert, schließt sich das Garagentor automatisch, wenn der Hausmodus auf Abwesenheit wechselt.
* **MotorVariableID**: Variable des Aktors/Relais, der den Impuls für die Motorsteuerung gibt.
* **SensorClosedID / SensorClosedValue**: Variable und auslösender Wert des Endschalters in der "Zu"-Position.
* **SensorOpenID / SensorOpenValue**: Variable und auslösender Wert des Endschalters in der "Auf"-Position.
* **AlarmDelayMinutes**: Dauer in Minuten (0 = aus), nach der ein Alarm ausgelöst wird, wenn das Tor nicht geschlossen wurde.
* **ButtonVariables**: Liste von auslösenden Taster-Variablen inklusive deren Trigger-Werten.
* **LEDInstances**: Liste von Homematic-Instanzen für die optische Statusanzeige via LED.

### 5. Statusvariablen und Profile

Das Modul erstellt automatisch die folgenden Variablen im Baum:

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| DoorState | 🚪 Torstatus | Integer | Zeigt den aktuellen Zustand an (Profil: `SmartAbsence.DoorState`). |
| DoorControl | Tor Steuerung | Boolean | Aktions-Button (WebFront) zum Triggern des Tors (Auf/Zu/Stopp). |
| AlarmOpenTooLong | Alarm: Tor zu lange offen | Boolean | Zeigt an, ob der Alarm-Timer abgelaufen ist. Kann manuell im WebFront quittiert werden. |

### 6. PHP-Befehlsreferenz

```php
SHG_SetHouseMode(int $InstanceID, int $mode, bool $isAbsence = false, bool $isSleep = false);
```
Wird in der Regel zentral vom `SmartHomeControl` Modul aufgerufen. Wenn die Abwesenheit erkannt wird und `CloseOnAbsence` aktiv ist, wird das Tor automatisch geschlossen, sofern es geöffnet ist.

```php
SHG_TurnOffRelay(int $InstanceID);
```
Interne Funktion: Setzt das Relais für die Motorsteuerung nach 1 Sekunde zurück (simuliert den Taster-Impuls).

```php
SHG_TriggerOpenAlarm(int $InstanceID);
```
Interne Funktion: Wird vom Alarm-Timer aufgerufen, wenn das Tor länger als die eingestellte Verzögerungszeit offen geblieben ist, und aktiviert die Alarm-Variable.
